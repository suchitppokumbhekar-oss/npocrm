<?php

namespace App\Services;

use App\Models\Agent;
use App\Models\Lead;
use App\Models\LeadAgent;
use App\Models\Project;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class LeadAssignmentService
{
    public function __construct(private NotificationService $notifications) {}

    /* ============================================================
       MAIN ENTRY — assign a lead using the best rule
       ============================================================ */

    /**
     * Assign a lead to the best available agent.
     *
     * Priority:
     *   1. Direct agents of the lead's project
     *   2. Team members of the lead's project's teams
     *   3. Global active agents (least-loaded)
     *
     * @param  array|null  $scopedAgentIds  Restrict to these agent IDs (for team managers etc.)
     */
        /**
     * Assign a lead to the best available agent.
     *
     * Priority:
     *   1. Direct agents of the lead's project
     *   2. Team members of the lead's project's teams
     *   3. Global least-loaded (only if strict mode is OFF)
     *
     * Strict mode OFF (setting `project_routing_strict = '0'`):
     *   Falls back to global least-loaded when project has no routing.
     *
     * Strict mode ON (setting `project_routing_strict = '1'` — default):
     *   If project has no routing, leaves the lead unassigned,
     *   logs a Note, and notifies all admins.
     */
    public function assign(Lead $lead, ?array $scopedAgentIds = null): ?Agent
    {
        $result = DB::transaction(function () use ($lead, $scopedAgentIds) {
            $lockedLead = Lead::query()->lockForUpdate()->find($lead->id);
            if (! $lockedLead) return ['agent' => null, 'needs_routing' => false];
            if ($lockedLead->agent_id) {
                return ['agent' => Agent::find($lockedLead->agent_id), 'needs_routing' => false];
            }

            $candidates = $this->candidateAgentIds($lockedLead, $scopedAgentIds);
            if (empty($candidates)) return ['agent' => null, 'needs_routing' => true];

            $agent = $this->pickLeastLoaded($candidates, false, true);
            if (! $agent) return ['agent' => null, 'needs_routing' => true];

            $lockedLead->update(['agent_id' => $agent->id, 'assigned_at' => now()]);
            LeadAgent::where('lead_id', $lockedLead->id)->update(['is_primary' => false]);
            LeadAgent::updateOrCreate(
                ['lead_id' => $lockedLead->id, 'agent_id' => $agent->id],
                ['is_primary' => true, 'is_active' => true]
            );
            $agent->increment('current_load');
            return ['agent' => $agent, 'needs_routing' => false];
        });

        if ($result['needs_routing']) {
            $this->handleNoCandidates($lead->fresh() ?: $lead);
            return null;
        }
        $agent = $result['agent'];
        if (! $agent) return null;

        $lead->refresh();
        try {
            $fresh = $lead->fresh(['agent.user']);
            if ($fresh && $fresh->agent?->user_id) $this->notifications->notifyLeadAssigned($fresh);
        } catch (\Throwable $e) {}
        Log::info("Lead {$lead->id} auto-assigned to agent {$agent->id} ({$agent->user?->name})");
        return $agent;
    }
/**
     * When no agents match, log a note on the lead and notify admins.
     * Idempotent — safe to call multiple times.
     */
    private function handleNoCandidates(Lead $lead): void
    {
        Log::warning("LeadAssignment: no eligible agents for lead {$lead->id}");

        // Only log once per lead (check for existing note)
        $alreadyLogged = \App\Models\Activity::where('lead_id', $lead->id)
            ->where('outcome', 'Awaiting project routing')
            ->exists();

        if (! $alreadyLogged) {
            $projectName = $lead->project?->name ?? 'No project';

            \App\Models\Activity::create([
                'lead_id'     => $lead->id,
                'agent_id'    => 1, // system
                'type'        => 'note',
                'outcome'     => 'Awaiting project routing',
                'action_source' => 'automated',
                'notes'       => "⚠️ No agents are configured to receive leads for this project.\n"
                               . "Project: {$projectName}\n"
                               . "Admin: go to Settings → 🎯 Project Routing to add a team or agent.",
                'logged_at'   => now(),
            ]);
        }

        // Notify admins AND project managers. Throttled by cache so the
        // intake path can't spam on retries.
        try {
            $notifiedKey = 'notif_awaiting_routing_' . $lead->id;

            if (! cache()->has($notifiedKey)) {
                $recipients = array_values(array_unique(array_merge(
                    $this->notifications->adminUserIds(),
                    $this->notifications->managerUserIdsForProject($lead->project_id)
                )));

                $body = $lead->customer_name . ' · ' . ($lead->project?->name ?? 'No project');

                foreach ($recipients as $uid) {
                    $this->notifications->notify(
                        $uid,
                        'lead_unassigned_urgent',
                        '🚨 Lead needs routing NOW',
                        [
                            'body'         => $body . ' · no routing configured',
                            'icon'         => '🚨',
                            'action_url'   => '/leads/' . $lead->id,
                            'related_type' => 'lead',
                            'related_id'   => $lead->id,
                        ]
                    );
                }

                cache()->put($notifiedKey, true, 3600);

                // Mark alert time so the 5-min command doesn't immediately re-alert
                $lead->forceFill(['unassigned_alerted_at' => now()])->save();
            }
        } catch (\Throwable $e) {
            // Best-effort
        }
    }
    /**
     * Legacy signature — kept for backward compatibility.
     * $scopedAgentIds takes precedence over the class's default routing.
     */
    public function assignLeastLoaded(Lead $lead, ?array $scopedAgentIds = null, ?string $shareNote = null): ?Agent
    {
        return $this->assign($lead, $scopedAgentIds);
    }

    /* ============================================================
       ASSIGN TO A SPECIFIC AGENT
       ============================================================ */
        public function assignTo(
        Lead $lead,
        Agent $agent,
        ?int $assignedByUserId = null,
        array $additionalAgentIds = [],
        ?string $shareNote = null,
        string $shareType = 'internal'
    ): void {
        if ($agent->status !== 'active') {
            throw new \DomainException('Cannot assign a lead to an inactive agent.');
        }

        $actualOldPrimaryId = null;
        $actualPrimaryChanged = false;

        DB::transaction(function () use ($lead, $agent, $assignedByUserId, &$actualOldPrimaryId, &$actualPrimaryChanged) {
            $lockedLead = Lead::query()->lockForUpdate()->findOrFail($lead->id);
            $lockedAgent = Agent::query()->lockForUpdate()->findOrFail($agent->id);
            $lockedOldPrimaryId = $lockedLead->agent_id;
            $lockedPrimaryChanged = ! $lockedOldPrimaryId || (int) $lockedOldPrimaryId !== (int) $lockedAgent->id;
            $actualOldPrimaryId = $lockedOldPrimaryId;
            $actualPrimaryChanged = $lockedPrimaryChanged;

            $lockedLead->update([
                'agent_id'    => $lockedAgent->id,
                'assigned_at' => now(),
            ]);

            LeadAgent::where('lead_id', $lockedLead->id)->update(['is_primary' => false]);

            LeadAgent::updateOrCreate(
                ['lead_id' => $lockedLead->id, 'agent_id' => $lockedAgent->id],
                [
                    'is_primary'       => true,
                    'is_active'        => true,
                    'added_by_user_id' => $assignedByUserId,
                ]
            );

            if ($lockedPrimaryChanged) {
                $lockedAgent->increment('current_load');
                if ($lockedOldPrimaryId) {
                    $old = Agent::query()->lockForUpdate()->find($lockedOldPrimaryId);
                    if ($old && $old->current_load > 0) {
                        $old->decrement('current_load');
                    }
                }
            }
        });

        if ($actualPrimaryChanged) {
            $this->logAssignmentActivity($lead, $agent, $assignedByUserId, (bool) $actualOldPrimaryId, $shareNote, $actualOldPrimaryId);

            try {
                $fresh = $lead->fresh(['agent.user']);
                if ($fresh && $fresh->agent?->user_id) {
                    $this->notifications->notifyLeadAssigned($fresh);
                }
            } catch (\Throwable $e) {}
        }

        foreach (array_unique(array_map('intval', $additionalAgentIds)) as $extraId) {
            if ($extraId === (int) $agent->id) continue;
            $extra = Agent::find($extraId);
            if ($extra) {
                $this->shareWith($lead, $extra, $assignedByUserId, false, $shareNote, $shareType);
            }
        }
    }

    /**
     * Add a non-primary collaborator without transferring ownership or
     * moving the primary agent's pending work. This is used by controlled
     * Contact -> Lead collaboration where a telecaller supports the Lead
     * only until the site visit is scheduled.
     */
    public function addCollaborator(Lead $lead, Agent $agent, ?int $addedByUserId = null, ?string $note = null): void
    {
        if ($agent->status !== 'active') {
            throw new \DomainException('Cannot collaborate on a lead with an inactive agent.');
        }

        if ((int) $lead->agent_id === (int) $agent->id) {
            return;
        }

        LeadAgent::updateOrCreate(
            ['lead_id' => $lead->id, 'agent_id' => $agent->id],
            [
                'is_primary'       => false,
                'is_active'        => true,
                'added_by_user_id' => $addedByUserId,
                'note'             => $note,
                'share_type'       => 'contact_handoff',
            ]
        );
    }

    /** End a controlled collaborator assignment without changing Lead ownership. */
    public function removeCollaborator(Lead $lead, Agent $agent): void
    {
        if ((int) $lead->agent_id === (int) $agent->id) {
            return;
        }

        LeadAgent::where('lead_id', $lead->id)
            ->where('agent_id', $agent->id)
            ->where('is_primary', false)
            ->update([
                'is_active' => false,
                'updated_at' => now(),
            ]);
    }

    /* ============================================================
       SHARING
       ============================================================ */
    public function shareWith(
    Lead $lead,
    Agent $agent,
    ?int $sharedByUserId = null,
    bool $silent = false,
    ?string $note = null,
    string $shareType = 'internal'
): void {
    if ((int) $lead->agent_id === (int) $agent->id) {
        return;
    }

    $existing = LeadAgent::where('lead_id', $lead->id)
        ->where('agent_id', $agent->id)
        ->first();

    if ($existing && $existing->is_active) return;

    LeadAgent::updateOrCreate(
        ['lead_id' => $lead->id, 'agent_id' => $agent->id],
        [
            'is_primary'       => false,
            'is_active'        => true,
            'added_by_user_id' => $sharedByUserId,
            'note'             => $note,
            'share_type'       => $shareType,
        ]
    );

    /* ============================================================
       Transfer pending action tasks to the co-agent.

       When a lead is shared internally (co-agent handoff), the
       pending follow-ups belong with whoever is actually doing
       the work — the co-agent. The primary does NOT receive an
       automatic second internal check-back task.

       Excluded from transfer:
         - check_shared_agent   (the check-back on the sharer)
         - check_site_team      (external-share check-back; unlikely
                                 on an internal share, but safe to exclude)

       Only runs for internal shares. Site-team shares are a
       different workflow and don't move tasks.
       ============================================================ */
    if ($shareType === 'internal' && $lead->agent_id && (int) $lead->agent_id !== (int) $agent->id) {
        $movedCount = \App\Models\Followup::where('lead_id', $lead->id)
            ->where('agent_id', $lead->agent_id)
            ->where('status', 'pending')
            ->whereNotIn('action_type', ['check_shared_agent', 'check_site_team'])
            ->update([
                'agent_id'   => $agent->id,
                'updated_at' => now(),
            ]);

        if ($movedCount > 0) {
            \Illuminate\Support\Facades\Log::info('shareWith: transferred pending tasks to co-agent', [
                'lead_id'         => $lead->id,
                'from_agent_id'   => $lead->agent_id,
                'from_agent_name' => $lead->agent?->user?->name,
                'to_agent_id'     => $agent->id,
                'to_agent_name'   => $agent->user?->name,
                'tasks_moved'     => $movedCount,
                'share_type'      => $shareType,
            ]);
        }
    }

    // If the receiving agent does not handle the lead's current project,
    // this is a cross-project share. The receiving agent must change the
    // lead to a project they actually handle. Record that requirement in
    // the timeline immediately; the actual project change is logged by
    // LeadController::assignProject.
    if ($shareType === 'internal' && $lead->project_id && ! $lead->agentHandlesProject((int) $agent->id)) {
        $byUser = $sharedByUserId ? \App\Models\User::find($sharedByUserId) : null;
        $byName = $byUser?->name ?? 'System';
        $toName = $agent->user?->name ?? ('Agent #' . $agent->id);

        \App\Models\Activity::create([
            'lead_id'     => $lead->id,
            'agent_id'    => $sharedByUserId
                ? (Agent::where('user_id', $sharedByUserId)->value('id') ?? $agent->id)
                : $agent->id,
            'type'        => 'project_change_required',
            'outcome'     => 'Project change required for shared agent',
            'action_source' => $sharedByUserId ? 'manual' : 'automated',
            'outcome_key' => null,
            'notes'       => implode("\n", [
                "By: {$byName}",
                "Shared with: {$toName}",
                "Current project: " . ($lead->project?->name ?? ('Project #' . $lead->project_id)),
                "Action required: {$toName} must change this lead to a project assigned to them or their active team.",
                "When: " . now()->format('Y-m-d H:i:s'),
            ]),
            'logged_at'   => now(),
        ]);
        $lead->update(['last_activity_at' => now()]);
    }

    /*
     * CRM POLICY: an internal co-agent share must not create a second
     * actionable follow-up for the same lead. The co-agent receives the
     * existing pending work above; the primary remains the owner but does
     * not receive an automatic `check_shared_agent` task.
     *
     * Site-team shares retain their separate check-back workflow.
     */
    if ($shareType === 'site_team') {
        $this->scheduleShareCheckBack($lead, $shareType, $sharedByUserId);
    } else {
        // Cancel any legacy internal check-back so re-sharing cannot leave
        // an obsolete second task behind.
        \App\Models\Followup::where('lead_id', $lead->id)
            ->where('status', 'pending')
            ->where('action_type', 'check_shared_agent')
            ->update(['status' => 'cancelled', 'updated_at' => now()]);
    }

    if ($silent) return;

    $this->logShareActivity($lead, $agent, $sharedByUserId, $note);

    try {
        $this->notifications->notifyLeadShared(
            $lead->fresh(['agent.user', 'primaryAgent.user']),
            $agent,
            $note
        );
    } catch (\Throwable $e) {}
}

/**
 * Create a check-back followup for a shared lead.
 *
 * Type 1 (internal):  reminder for the primary agent, 24h out.
 * Type 2 (site team): reminder for the sharer (admin/manager), 2d out.
 *
 * Idempotent: cancels any existing pending check-back of the same kind
 * before creating a new one, so re-sharing doesn't stack reminders.
 */
private function scheduleShareCheckBack(
    Lead $lead,
    string $shareType,
    ?int $sharedByUserId
): void {
    $followupService = app(\App\Services\FollowupService::class);

    if ($shareType === 'site_team') {
        // Target = the user who shared the lead
        if (! $sharedByUserId) {
            \Illuminate\Support\Facades\Log::warning(
                "Cannot schedule site_team check-back for lead {$lead->id}: no sharedByUserId"
            );
            return;
        }

        $sharerAgentId = Agent::where('user_id', $sharedByUserId)->value('id');
        if (! $sharerAgentId) {
            \Illuminate\Support\Facades\Log::warning(
                "Cannot schedule site_team check-back for lead {$lead->id}: "
                . "user {$sharedByUserId} has no Agent record"
            );
            return;
        }

        // Cancel any existing pending site_team check-back for this lead
        \App\Models\Followup::where('lead_id', $lead->id)
            ->where('status', 'pending')
            ->where('action_type', 'check_site_team')
            ->update(['status' => 'cancelled', 'updated_at' => now()]);

        $followupService->scheduleForAgent(
            $lead,
            (int) $sharerAgentId,
            now()->addDays(2)->setTime(10, 0),
            'check_site_team',
            'high'
        );

        return;
    }

    // Default: internal co-agent — reminder for the primary agent
    if (! $lead->agent_id) return;

    \App\Models\Followup::where('lead_id', $lead->id)
        ->where('status', 'pending')
        ->where('action_type', 'check_shared_agent')
        ->update(['status' => 'cancelled', 'updated_at' => now()]);

    $followupService->scheduleForAgent(
        $lead,
        (int) $lead->agent_id,
        now()->addHours(24),
        'check_shared_agent',
        'normal'
    );
}

    public function unshare(Lead $lead, int $agentId): void
{
    if ((int) $lead->agent_id === $agentId) return;

    // Deactivate the pivot row
    LeadAgent::where('lead_id', $lead->id)
        ->where('agent_id', $agentId)
        ->update(['is_active' => false]);

    // -------- Followup cleanup --------
    // Pending followups for the removed agent on this lead must
    // be reassigned (if there's a primary agent) or cancelled,
    // otherwise they keep showing on the removed agent's dashboard.
    $pendingIds = \App\Models\Followup::where('lead_id', $lead->id)
        ->where('agent_id', $agentId)
        ->where('status', 'pending')
        ->pluck('id');

    if ($pendingIds->isEmpty()) {
        return;
    }

    if ($lead->agent_id) {
        // Reassign to the primary agent
        \App\Models\Followup::whereIn('id', $pendingIds)
            ->update([
                'agent_id'   => $lead->agent_id,
                'updated_at' => now(),
            ]);
    } else {
        // No primary agent — cancel them
        \App\Models\Followup::whereIn('id', $pendingIds)
            ->update([
                'status'     => 'cancelled',
                'updated_at' => now(),
            ]);
    }
}

    /* ============================================================
       LOAD MANAGEMENT
       ============================================================ */
    public function release(Agent $agent): void
    {
        if ($agent->current_load <= 0) return;
        $agent->decrement('current_load');
    }

    public function releaseForFinalStatus(Lead $lead): void
    {
        if (! $lead->agent_id) return;
        $agent = $lead->agent;
        if (! $agent) return;
        $this->release($agent);
    }

    public function reassign(Lead $lead, Agent $newAgent): void
    {
        $this->assignTo($lead, $newAgent, session('user_id'));
    }

    /* ============================================================
       INTERNAL — CANDIDATE RESOLUTION
       ============================================================ */

    /**
     * Resolve the list of agent IDs eligible for a lead, in priority order.
     */
        private function candidateAgentIds(Lead $lead, ?array $overrideScope = null): array
    {
        // Explicit scope always wins (team manager's "within my team" etc.)
        if ($overrideScope !== null) {
            return array_values(array_unique(array_map('intval', $overrideScope)));
        }

        // No project on the lead
        if (! $lead->project_id) {
            return $this->globalCandidatesOrEmpty();
        }

        $project = Project::find($lead->project_id);
        if (! $project) {
            return $this->globalCandidatesOrEmpty();
        }

                // Project-specific routing — direct agents get first pick,
        // team members only as fallback. Load-balancing still applies
        // within each tier via pickLeastLoaded(), but a team member
        // can never outrank a direct agent on load alone.
        $bySource = $project->eligibleAgentIdsBySource();

        if (! empty($bySource['direct'])) {
            return $bySource['direct'];
        }

        if (! empty($bySource['team'])) {
            return $bySource['team'];
        }

        // Project exists but has NO routing configured
        return $this->globalCandidatesOrEmpty();
    }

    /**
     * Global candidates — but only if strict mode is OFF.
     * When strict mode is ON and the project has no routing, return [].
     */
    private function globalCandidatesOrEmpty(): array
    {
        $strict = app(SettingsService::class)->get('project_routing_strict', '1') === '1';

        if ($strict) {
            // No silent fallback — leave the lead unassigned
            return [];
        }

        return Agent::active()->pluck('id')->all();
    }

    /** All active agents */
    private function globalCandidateIds(): array
    {
        return Agent::active()->pluck('id')->all();
    }

        /**
     * Pick the least loaded agent from a list of agent IDs.
     * Admin agents are EXCLUDED from auto-assignment unless explicitly requested.
     */
    private function pickLeastLoaded(array $agentIds, bool $includeAdmins = false, bool $lock = false): ?Agent
    {
        if (empty($agentIds)) return null;

        $query = Agent::whereIn('id', $agentIds)->where('status', 'active');

        if (! $includeAdmins) {
            $query->whereHas('user', fn ($q) => $q->where('role', '!=', 'admin'));
        }

        if ($lock) {
            $query->lockForUpdate();
        }

        $agents = $query->get();
        if ($agents->isEmpty()) return null;

        return $agents->sortBy(function ($a) {
            $max = max(1, (int) $a->max_daily_leads);
            return ($a->current_load / $max) * 1000 + $a->id * 0.001;
        })->first();
    }


    /* ============================================================
       ACTIVITY LOGGING
       ============================================================ */
    private function logAssignmentActivity(
        Lead $lead, Agent $newAgent, ?int $byUserId,
        bool $isReassignment, ?string $note, ?int $oldPrimaryId
    ): void {
        $byUser  = $byUserId ? \App\Models\User::find($byUserId) : null;
        $byName  = $byUser?->name ?? 'System';
        $newName = $newAgent->user?->name ?? ('Agent #' . $newAgent->id);
        $oldName = $oldPrimaryId ? (Agent::find($oldPrimaryId)?->user?->name ?? null) : null;

        $type    = $isReassignment ? 'lead_reassigned' : 'lead_shared';
        $outcome = $isReassignment ? "Reassigned to {$newName}" : "Assigned to {$newName}";

        $lines = ["By: {$byName}"];
        if ($isReassignment && $oldName) $lines[] = "From: {$oldName}";
        $lines[] = "To: {$newName}";
        if ($note && trim($note) !== '') $lines[] = "Reason: " . trim($note);

        \App\Models\Activity::create([
            'lead_id'     => $lead->id,
            'agent_id'    => $byUserId
                ? (Agent::where('user_id', $byUserId)->value('id') ?? $newAgent->id)
                : $newAgent->id,
            'type'        => $type,
            'outcome'     => $outcome,
            'action_source' => $byUserId ? 'manual' : 'automated',
            'outcome_key' => null,
            'notes'       => implode("\n", $lines),
            'logged_at'   => now(),
        ]);
                $lead->update(['last_activity_at' => now()]);
    }

    private function logShareActivity(Lead $lead, Agent $sharedWith, ?int $byUserId, ?string $note): void
    {
        $byUser = $byUserId ? \App\Models\User::find($byUserId) : null;
        $byName = $byUser?->name ?? 'System';
        $toName = $sharedWith->user?->name ?? ('Agent #' . $sharedWith->id);

        $lines = ["By: {$byName}", "Shared with: {$toName}"];
        if ($note && trim($note) !== '') $lines[] = "Reason: " . trim($note);

        \App\Models\Activity::create([
            'lead_id'     => $lead->id,
            'agent_id'    => $byUserId
                ? (Agent::where('user_id', $byUserId)->value('id') ?? $sharedWith->id)
                : $sharedWith->id,
            'type'        => 'lead_shared',
            'outcome'     => "Shared with {$toName}",
            'action_source' => $byUserId ? 'manual' : 'automated',
            'outcome_key' => null,
            'notes'       => implode("\n", $lines),
            'logged_at'   => now(),
        ]);
        
                $lead->update(['last_activity_at' => now()]);
    }
}