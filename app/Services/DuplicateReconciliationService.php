<?php

namespace App\Services;

use App\Models\Activity;
use App\Models\Lead;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;
use InvalidArgumentException;

class DuplicateReconciliationService
{
    public function reconcile(
        int $customerId,
        int $projectId,
        int $survivorId,
        string $reason,
        ?Request $request = null,
    ): array {
        $reason = trim($reason);

        if ($reason === '' || mb_strlen($reason) > 1000) {
            throw new InvalidArgumentException('A reconciliation reason is required (maximum 1000 characters).');
        }
        if ($survivorId <= 0) {
            throw new InvalidArgumentException('Select one surviving lead.');
        }

        return DB::transaction(function () use ($customerId, $projectId, $survivorId, $reason, $request): array {
            $survivor = Lead::with(['project', 'agent.user'])
                ->whereKey($survivorId)
                ->lockForUpdate()
                ->first();

            if (! $survivor) {
                throw new InvalidArgumentException('The selected surviving lead no longer exists. Refresh and try again.');
            }
            if ((int) $survivor->customer_id !== $customerId || (int) $survivor->project_id !== $projectId) {
                throw new InvalidArgumentException('The selected surviving lead does not belong to this customer and project.');
            }
            if ($survivor->origin_type === 'duplicate_reconciled') {
                throw new InvalidArgumentException('The selected surviving lead has already been reconciled. Refresh the page.');
            }

            // Any existing parent relationship means this lead is a related
            // record, not a plain duplicate candidate. Keep it out of duplicate
            // reconciliation entirely so we never rewrite an intentional lead
            // relationship while consolidating same-project duplicates.
            if ($survivor->parent_lead_id) {
                throw new InvalidArgumentException(
                    'Lead #' . $survivorId . ' is already linked to Lead #' . (int) $survivor->parent_lead_id
                    . ' and is protected from duplicate reconciliation. Review that relationship separately.'
                );
            }

            $candidateLeads = Lead::where('customer_id', $customerId)
                ->where('project_id', $projectId)
                ->where('id', '!=', $survivorId)
                ->where(function ($q) {
                    $q->whereNull('origin_type')->orWhere('origin_type', '!=', 'duplicate_reconciled');
                })
                ->lockForUpdate()
                ->get();

            // Any existing parent relationship is protected. Only plain
            // same-customer/same-project records without a parent may be
            // reconciled as duplicates.
            $duplicateLeads = $candidateLeads
                ->filter(fn ($duplicate) => ! $duplicate->parent_lead_id)
                ->values();

            if ($duplicateLeads->isEmpty()) {
                throw new InvalidArgumentException('No eligible same-project duplicate remains. Any related lead is protected and was left untouched. Refresh the page.');
            }

            $ids = array_values(array_unique(array_merge([$survivorId], $duplicateLeads->pluck('id')->all())));

            if ($survivor->parent_lead_id && in_array((int) $survivor->parent_lead_id, $ids, true)) {
                throw new InvalidArgumentException('The selected surviving lead is already related to another selected lead.');
            }

            $transferredFollowups = 0;
            $reconciled = [];

            foreach ($duplicateLeads as $duplicate) {
                $duplicateId = (int) $duplicate->id;
                $previousParentId = (int) ($duplicate->parent_lead_id ?? 0);

                $count = DB::table('followups')
                    ->where('lead_id', $duplicateId)
                    ->where('status', 'pending')
                    ->update([
                        'lead_id' => $survivorId,
                        'updated_at' => now(),
                    ]);
                $transferredFollowups += $count;

                $duplicate->forceFill([
                    'parent_lead_id' => $survivorId,
                    'origin_type' => 'duplicate_reconciled',
                    'origin_note' => 'Reconciled into Lead #' . $survivorId
                        . ($previousParentId && $previousParentId !== $survivorId
                            ? '. Previous same-project relationship parent: Lead #' . $previousParentId
                            : '')
                        . '. Reason: ' . mb_substr($reason, 0, 800),
                ])->save();

                $reconciled[] = $duplicateId;
            }

            Activity::create([
                'lead_id' => $survivorId,
                'agent_id' => null,
                'type' => 'note',
                'outcome' => 'Duplicate enquiry reconciled',
                'notes' => 'Reconciled duplicate lead(s) #' . implode(', #', $reconciled)
                    . ' into Lead #' . $survivorId . '. '
                    . 'Reason: ' . $reason
                    . ($transferredFollowups ? ' Pending actions transferred: ' . $transferredFollowups . '.' : ''),
                'logged_at' => now(),
                'action_source' => 'reconciliation',
            ]);

            app(AuditLogService::class)->record(
                'lead_reconciliation',
                'duplicate_enquiries_reconciled',
                $request,
                [
                    'customer_id' => $customerId,
                    'project_id' => $projectId,
                    'survivor_lead_id' => $survivorId,
                    'duplicate_lead_ids' => $reconciled,
                    'protected_relationships_excluded' => $candidateLeads->count() - $duplicateLeads->count(),
                    'reason' => $reason,
                    'pending_followups_transferred' => $transferredFollowups,
                ],
                'lead',
                $survivorId,
            );

            return [
                'survivor_id' => $survivorId,
                'duplicate_ids' => $reconciled,
                'transferred_followups' => $transferredFollowups,
            ];
        });
    }
}
