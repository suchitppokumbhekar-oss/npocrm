<?php

namespace App\Http\Controllers;

use App\Models\Activity;
use App\Models\Agent;
use App\Models\Followup;
use App\Models\Lead;
use App\Models\LeadExternalShare;
use App\Services\FollowupService;
use App\Services\LeadStatusService;
use App\Services\AccessService;
use App\Services\WorkActionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ExternalShareController extends Controller
{
    public const REASONS = [
        'follow_up'     => '📤 Shared for follow-up',
        'site_visit'    => '🏠 Customer visiting site — site team to receive',
        'outside_agent' => '👔 Outside agent to contact directly',
        'closing'       => '🤝 Handing over for negotiation / closing',
        'reassignment'  => '💼 Reassignment — they now own it',
        'other'         => '📝 Other',
    ];

    public function store(Request $request, FollowupService $followups, LeadStatusService $statuses, AccessService $access)
    {
        if (! session('user_id')) {
            return back()->with('error', 'Session expired.');
        }

        $validated = $request->validate([
            'lead_id'          => 'required|integer|exists:leads,id',
            'group_name'       => 'nullable|string|max:150',
            'reason_key'       => 'required|string|in:' . implode(',', array_keys(self::REASONS)),
            'extra_notes'      => 'nullable|string|max:2000',
            'complete_pending' => 'nullable|boolean',
            'followup_id'      => 'nullable|integer|exists:followups,id',
            'allow_early'      => 'nullable|boolean',
            'return_to'        => 'nullable|string|max:1000',
        ]);

        $lead        = Lead::findOrFail($validated['lead_id']);
        if (! $access->canWorkLead($lead)) {
            abort(403, 'You are not allowed to work on this lead.');
        }
        $taskFollowup = null;
        if (! empty($validated['followup_id']) && ! empty($validated['complete_pending'])) {
            $taskFollowup = Followup::where('id', $validated['followup_id'])
                ->where('lead_id', $lead->id)
                ->where('status', 'pending')
                ->firstOrFail();
            app(WorkActionService::class)->assertCanAct(
                $taskFollowup,
                filter_var($validated['allow_early'] ?? false, FILTER_VALIDATE_BOOLEAN)
            );
        }
        $userId      = (int) session('user_id');
        $sharer      = Agent::where('user_id', $userId)->first();
        $sharerName  = session('user_name', 'Admin');
        $reasonLabel = self::REASONS[$validated['reason_key']];
        $closePending = ! empty($validated['complete_pending']);

        DB::transaction(function () use (
            $lead, $validated, $userId, $sharerName, $reasonLabel,
            $sharer, $followups, $closePending, $taskFollowup, $statuses
        ) {

            // 1. Structured record
            $movedToExternalShared = $statuses->markExternallyShared($lead);

            LeadExternalShare::create([
                'lead_id'           => $lead->id,
                'shared_by_user_id' => $userId,
                'group_name'        => $validated['group_name'] ?: null,
                'reason_key'        => $validated['reason_key'],
                'reason_label'      => $reasonLabel,
                'extra_notes'       => $validated['extra_notes'] ?: null,
            ]);

            // 2. Close existing pending tasks (if requested) BEFORE logging
            //    so the timeline note can report how many were closed.
            $closedCount = 0;
            if ($closePending) {
                if ($taskFollowup) {
                    $taskFollowup->update([
                        'status'     => 'done',
                        'updated_at' => now(),
                    ]);
                    $closedCount = 1;
                } else {
                    $closedCount = Followup::where('lead_id', $lead->id)
                        ->where('status', 'pending')
                        ->update([
                            'status'     => 'done',
                            'updated_at' => now(),
                        ]);
                }
            }

            // 3. Build timeline note
            $lines = ['Shared externally', 'By: ' . $sharerName];
            if ($movedToExternalShared) {
                $lines[] = 'Lifecycle: New → Shared Externally';
            }

            if (! empty($validated['group_name'])) {
                $lines[] = 'To: ' . $validated['group_name'];
            }

            $lines[] = 'Reason: ' . $reasonLabel;

            if (! empty($validated['extra_notes'])) {
                $lines[] = 'Notes: ' . trim($validated['extra_notes']);
            }

            if ($closedCount > 0) {
                $lines[] = 'Also closed ' . $closedCount
                         . ' pending task' . ($closedCount === 1 ? '' : 's');
            }

            // 4. Log the activity
            Activity::create([
            'action_source' => 'manual',
                'lead_id'     => $lead->id,
                'agent_id'    => $sharer ? (int) $sharer->id : $lead->agent_id,
                'type'        => 'external_share',
                'outcome'     => $validated['group_name']
                    ? '📤 Shared with ' . $validated['group_name']
                    : '📤 Shared externally',
                'outcome_key' => null,
                'notes'       => implode("\n", $lines),
                'logged_at'   => now(),
            ]);

            $lead->update(['last_activity_at' => now()]);

            // 5. Check-back on the sharer, 2 days out
            if (! $sharer) {
                Log::warning(
                    "External share: user {$userId} has no Agent record — "
                    . "no check-back scheduled for lead {$lead->id}"
                );
                return;
            }

            // Cancel any remaining pending check-back (no-op if step 2 ran)
            Followup::where('lead_id', $lead->id)
                ->where('status', 'pending')
                ->where('action_type', 'check_site_team')
                ->update(['status' => 'cancelled', 'updated_at' => now()]);

            $followups->scheduleForAgent(
                $lead,
                (int) $sharer->id,
                now()->addDays(2)->setTime(10, 0),
                'check_site_team',
                'high'
            );
        });

        $msg = '📤 External share recorded. Check-back scheduled in 2 days.';
        if ($closePending) {
            $msg = '📤 External share recorded. Pending tasks closed. Check-back in 2 days.';
        }

        // Preserve the work surface that launched Share Lead. This keeps a
        // task opened from My Work returning to My Work after WhatsApp/share,
        // instead of dropping the user onto the lead page. Only relative
        // internal paths are accepted.
        $returnTo = (string) ($validated['return_to'] ?? '');
        $isSafeReturn = $returnTo !== ''
            && str_starts_with($returnTo, '/')
            && ! str_starts_with($returnTo, '//')
            && ! preg_match('/^[a-z][a-z0-9+.-]*:/i', $returnTo);
        $redirectUrl = $isSafeReturn ? url($returnTo) : url('/leads/' . $lead->id) . '?task_completed=1&completed_followup_id=' . ($taskFollowup?->id ?? '');
        $completionUrl = $taskFollowup
            ? url('/leads/' . $lead->id) . '?task_completed=1&completed_followup_id=' . $taskFollowup->id . '&lead_tab=history#lead-wb-completion'
            : null;

        if ($request->expectsJson() || $request->ajax()) {
            return response()->json([
                'ok' => true,
                'message' => $msg,
                'redirect_url' => $redirectUrl,
                'completion_url' => $completionUrl,
            ]);
        }

        return redirect($redirectUrl)->with('success', $msg);
    }
}