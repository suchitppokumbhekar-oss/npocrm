<?php

namespace App\Services;

use App\Models\Contact;
use App\Models\Lead;
use App\Models\ContactProjectAssignment;
use App\Models\Project;
use Illuminate\Support\Facades\DB;

class ContactService
{
    /** Normalize a phone to canonical international digits. */
    public function normalizePhone(string $phone): string
    {
        return phone_canonical($phone);
    }


    /** Import a batch of rows. Returns [imported, skipped, failed, errors]. */
    public function importBatch(array $rows, array $mapping, int $userId, ?int $batchId = null, ?int $pitchProjectId = null, ?int $assignedAgentId = null): array
    {
        $imported = 0; $skipped = 0; $skippedDnd = 0; $skippedDuplicates = 0; $failed = 0; $errors = [];
        $seenPhones = [];

        // Build canonical phone sets once so duplicate/DND detection is based on
        // the same normalization even if older records contain +91/0 formatting.
        $existingContactPhones = [];
        Contact::query()->pluck('phone')->each(function ($phone) use (&$existingContactPhones) {
            $normalizedExisting = $this->normalizePhone((string) $phone);
            if ($normalizedExisting !== '') {
                $existingContactPhones[$normalizedExisting] = true;
            }
        });

        $dndPhones = [];
        Contact::query()->where('status', 'dnc')->pluck('phone')->each(function ($phone) use (&$dndPhones) {
            $normalizedDnd = $this->normalizePhone((string) $phone);
            if ($normalizedDnd !== '') {
                $dndPhones[$normalizedDnd] = true;
            }
        });

        foreach ($rows as $idx => $row) {
            $rowNum = $idx + 1;

            $name  = trim((string) ($row[$mapping['name']  ?? -1] ?? ''));
            $phone = trim((string) ($row[$mapping['phone'] ?? -1] ?? ''));

            if ($name === '' || $phone === '') {
                $failed++;
                $errors[] = ['row' => $rowNum, 'reason' => 'Missing name or phone', 'data' => $row];
                continue;
            }

            $normalized = $this->normalizePhone($phone);
            if (strlen($normalized) < 10) {
                $failed++;
                $errors[] = ['row' => $rowNum, 'reason' => 'Invalid phone: ' . $phone, 'data' => $row];
                continue;
            }

            // DND is a Contact-only suppression rule: a number already marked DNC
            // in Contacts must never be imported again. Check this before
            // duplicate handling so the import summary reports the correct reason.
            if (isset($dndPhones[$normalized])) {
                $skipped++;
                $skippedDnd++;
                continue;
            }

            // Skip within-batch duplicates
            if (isset($seenPhones[$normalized])) {
                $skipped++;
                $skippedDuplicates++;
                continue;
            }
            $seenPhones[$normalized] = true;

            // Skip if already in contacts (using canonical phone normalization).
            if (isset($existingContactPhones[$normalized])) {
                $skipped++;
                $skippedDuplicates++;
                continue;
            }

            // The imported list has exactly one mandatory Pitch Project selected
            // outside the CSV. Do not accept a per-row Project value here.
            $projectId = $pitchProjectId;
            if (! $projectId) {
                $failed++;
                $errors[] = ['row' => $rowNum, 'reason' => 'A pitch project is required for every contact list.', 'data' => $row];
                continue;
            }

            try {
                $email = trim((string) ($row[$mapping['email'] ?? -1] ?? ''));
                $source = trim((string) ($row[$mapping['source'] ?? -1] ?? ''));

                if ($source !== '' && mb_strlen($source) > 50) {
                    $failed++;
                    $errors[] = [
                        'row' => $rowNum,
                        'reason' => 'Mapped Source value is longer than the CRM source field allows (50 characters). Choose a short source/channel column instead.',
                        'data' => $row,
                    ];
                    continue;
                }

                if ($assignedAgentId !== null) {
                    $project = Project::find($projectId);
                    if (! $project || ! in_array($assignedAgentId, array_map('intval', $project->eligibleAgentIds()), true)) {
                        $failed++;
                        $errors[] = [
                            'row' => $rowNum,
                            'reason' => 'The selected caller is not authorized for the selected Pitch Project.',
                            'data' => $row,
                        ];
                        continue;
                    }
                }

                $contact = Contact::create([
                    'name'                 => mb_substr($name, 0, 255),
                    'phone'                => mb_substr($normalized, 0, 20),
                    'email'                => $email !== '' ? mb_substr($email, 0, 255) : null,
                    'source'               => $source !== '' ? $source : 'import',
                    'project_id'           => $projectId,
                    'assigned_to_agent_id' => $assignedAgentId,
                    'status'               => 'new',
                    'imported_from_batch_id' => $batchId,
                    'created_by_user_id'   => $userId,
                    'raw_payload'          => json_encode($row),
                ]);

                ContactProjectAssignment::create([
                    'contact_id' => $contact->id,
                    'project_id' => $projectId,
                    'assigned_to_agent_id' => $assignedAgentId,
                    'assigned_by_user_id' => $userId,
                    'status' => 'active',
                    'assigned_at' => now(),
                ]);
                $existingContactPhones[$normalized] = true;
                $imported++;
            } catch (\Throwable $e) {
                $failed++;
                $errors[] = ['row' => $rowNum, 'reason' => $e->getMessage(), 'data' => $row];
            }
        }

        return compact('imported', 'skipped', 'skippedDnd', 'skippedDuplicates', 'failed', 'errors');
    }

    /** Promote a contact to a lead. The selected Lead project is independent of the pitch project. */
    public function promoteToLead(Contact $contact, int $userId, ?int $leadProjectId = null, ?int $preferredAgentId = null, bool $createInitialFollowup = true): Lead
    {
        if ($contact->status === 'dnc') {
            throw new \RuntimeException('DND contacts cannot be circulated or converted into leads.');
        }

        if ($contact->isPromoted()) {
            return $contact->lead;
        }

        // The Pitch Project is the default Lead Project. The caller can override it
        // only when the customer has a different requirement.
        $leadProjectId = (int) ($leadProjectId ?: ($contact->project_id ?? 0));
        if ($leadProjectId <= 0) {
            throw new \RuntimeException('A Pitch Project is required before converting a contact into a lead.');
        }

        return DB::transaction(function () use ($contact, $userId, $leadProjectId, $preferredAgentId, $createInitialFollowup) {
            // A converted Contact no longer owns caller work. Cancel its pending
            // Contact retries before the Lead takes over the work in /tasks.
            app(\App\Services\ContactFollowupService::class)->cancelPending($contact);

            // One customer may have multiple projects, but there must be only one
            // Lead for the same normalized phone + project. Contact promotion must
            // use the same rule as LeadIntakeService instead of bypassing it with
            // a direct Lead::create().
            $normalizedPhone = $this->normalizePhone((string) $contact->phone);
            $phoneVariants = [$normalizedPhone, '+' . $normalizedPhone, (string) $contact->phone];
            if (strlen($normalizedPhone) === 12 && str_starts_with($normalizedPhone, '91')) {
                $local = substr($normalizedPhone, 2);
                $phoneVariants[] = $local;
                $phoneVariants[] = '0' . $local;
            }
            $phoneVariants = array_values(array_unique(array_filter($phoneVariants)));
            $existingLead = Lead::where('project_id', $leadProjectId)
                ->whereRaw('REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(phone, " ", ""), "-", ""), "+", ""), "(", ""), ")", "") IN (' . implode(',', array_fill(0, count($phoneVariants), '?')) . ')', $phoneVariants)
                ->orderByDesc('id')
                ->first();

            if ($existingLead) {
                $contact->update([
                    'promoted_to_lead_id' => $existingLead->id,
                    'promoted_at'         => $contact->promoted_at ?: now(),
                    'status'              => 'converted',
                ]);

                return $existingLead;
            }

            $lead = Lead::create([
                'customer_name' => $contact->name,
                'phone'         => $contact->phone,
                'email'         => $contact->email,
                'source'        => $contact->source ?? 'contact_promoted',
                'intake_source' => 'contacts',
                'project_id'    => $leadProjectId,
                'status'        => 'new',
            ]);

            $contact->update([
                'promoted_to_lead_id' => $lead->id,
                'promoted_at'         => now(),
                'status'              => 'converted',
            ]);

            // Assign the Lead owner. Automatic Contact -> Lead handoff may
            // provide the current caller as the preferred owner (normal agent
            // caller). Telecallers leave this null so the normal project-aware
            // Lead routing rule selects the sales owner.
            try {
                $assignment = app(\App\Services\LeadAssignmentService::class);
                if ($preferredAgentId) {
                    $preferredAgent = \App\Models\Agent::find($preferredAgentId);
                    if ($preferredAgent && $preferredAgent->status === 'active') {
                        $assignment->assignTo($lead, $preferredAgent, $userId);
                    } else {
                        $assignment->assign($lead);
                    }
                } else {
                    $assignment->assign($lead);
                }
            } catch (\Throwable $e) {
                // assignment is best-effort; the existing routing safety net
                // will surface an unassigned Lead to management.
            }

            $lead->refresh();

            // Schedule the normal first-contact followup only when this
            // promotion is expected to start the owner's independent Lead work.
            // A telecaller handoff deliberately suppresses this because the
            // telecaller gets a controlled supporting task instead.
            if ($lead->agent_id && $createInitialFollowup) {
                $delay = max(1, (int) app(\App\Services\SettingsService::class)
                    ->get('first_contact_delay_minutes', 15));

                \App\Models\Followup::create([
                    'lead_id'        => $lead->id,
                    'agent_id'       => $lead->agent_id,
                    'scheduled_for'  => now()->addMinutes($delay),
                    'action_type'    => 'followup_call',
                    'priority'       => 'high',
                    'status'         => 'pending',
                    'escalated_flag' => false,
                    'auto_created'   => true,
                ]);
            }

            // Log a note about the promotion even when the normal first task is
            // intentionally suppressed for a telecaller handoff.
            \App\Models\Activity::create([
                'action_source' => 'manual',
                'lead_id'     => $lead->id,
                'agent_id'    => $lead->resolveLoggingAgentId(),
                'type'        => 'note',
                'outcome'     => 'Promoted from contact',
                'notes'       => "Contact #{$contact->id} ({$contact->name}) promoted to lead.",
                'logged_at'   => now(),
            ]);

            return $lead;
        });
    }

    /** Assign/reassign the contact for a specific pitch project. History is preserved. */
    public function assignPitch(Contact $contact, int $projectId, ?int $agentId, int $assignedByUserId): ContactProjectAssignment
    {
        if ($contact->status === 'dnc') {
            throw new \RuntimeException('DND contacts cannot be assigned or circulated.');
        }

        if ($agentId !== null) {
            $project = Project::find($projectId);
            if (! $project || ! in_array($agentId, array_map('intval', $project->eligibleAgentIds()), true)) {
                throw new \InvalidArgumentException('That agent is not authorized for this pitch project.');
            }
        }

        return DB::transaction(function () use ($contact, $projectId, $agentId, $assignedByUserId) {
            // A Contact may be actively pitched for multiple projects at the same time.
            // Reassignment only closes the prior active assignment for THIS project;
            // assignments for other projects remain active and untouched.
            ContactProjectAssignment::where('contact_id', $contact->id)
                ->where('project_id', $projectId)
                ->where('status', 'active')
                ->update([
                    'status' => 'completed',
                    'completed_at' => now(),
                    'updated_at' => now(),
                ]);

            $assignment = ContactProjectAssignment::create([
                'contact_id' => $contact->id,
                'project_id' => $projectId,
                'assigned_to_agent_id' => $agentId,
                'assigned_by_user_id' => $assignedByUserId,
                'status' => 'active',
                'assigned_at' => now(),
            ]);

            $contact->update([
                'project_id' => $projectId,
                'assigned_to_agent_id' => $agentId,
            ]);

            return $assignment;
        });
    }

    /** Clear the current pitch assignment while preserving assignment history. */
    public function clearPitchAssignment(Contact $contact, int $completedByUserId): void
    {
        DB::transaction(function () use ($contact, $completedByUserId) {
            $projectId = (int) ($contact->project_id ?? 0);
            $agentId = $contact->assigned_to_agent_id !== null ? (int) $contact->assigned_to_agent_id : null;

            if ($projectId > 0) {
                $query = ContactProjectAssignment::where('contact_id', $contact->id)
                    ->where('project_id', $projectId)
                    ->where('status', 'active');

                if ($agentId !== null) {
                    $query->where('assigned_to_agent_id', $agentId);
                }

                $query->update([
                    'status' => 'completed',
                    'completed_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            // Do not touch other active project assignments. The legacy Contact fields
            // represent the current/default pitch context; the assignment table retains
            // all project/caller relationships.
            $replacement = ContactProjectAssignment::where('contact_id', $contact->id)
                ->where('status', 'active')
                ->orderByDesc('id')
                ->first();

            $contact->update([
                'project_id' => $replacement?->project_id,
                'assigned_to_agent_id' => $replacement?->assigned_to_agent_id,
            ]);
        });
    }

    /** Backward-compatible helper retained for callers that only change the assignee. */
    public function assignTo(Contact $contact, ?int $agentId): void
    {
        $projectId = (int) ($contact->project_id ?? 0);
        if ($projectId <= 0) {
            $contact->update(['assigned_to_agent_id' => $agentId]);
            return;
        }
        $this->assignPitch($contact, $projectId, $agentId, (int) session('user_id'));
    }
}