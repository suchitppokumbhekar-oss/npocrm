<?php

namespace App\Services;

use App\Models\Agent;
use App\Models\AppNotification;
use App\Models\Customer;
use App\Models\Followup;
use App\Models\Lead;
use App\Models\Team;
use App\Models\User;
use App\Services\AuditLogService;

class NotificationService
{
    public function __construct(private AuditLogService $audit) {}

    /* ============================================================
       CORE
       ============================================================ */

    public function notify(int $userId, string $type, string $title, array $opts = []): AppNotification
    {
        return AppNotification::create([
            'user_id'      => $userId,
            'type'         => $type,
            'title'        => $title,
            'body'         => $opts['body'] ?? null,
            'icon'         => $opts['icon'] ?? '🔔',
            'action_url'   => $opts['action_url'] ?? null,
            'related_type' => $opts['related_type'] ?? null,
            'related_id'   => $opts['related_id'] ?? null,
        ]);
    }

    /* ============================================================
       RECIPIENT RESOLVERS
       ============================================================ */

    /**
     * All active Admin users, explicitly including active Super Admins.
     * Super Admin is an authority record layered on top of role=admin, so
     * notification routing must never depend on that secondary table being
     * joined implicitly.
     */
    public function adminUserIds(): array
    {
        $adminIds = User::where('role', 'admin')->pluck('id')->map(fn ($id) => (int) $id)->all();
        $superAdminIds = \App\Models\SuperAdmin::where('is_active', true)
            ->pluck('user_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        return array_values(array_unique(array_merge($adminIds, $superAdminIds)));
    }

    /**
     * Notification urgency used by the PWA and notification center.
     * Keep this derived from the existing notification type so no schema
     * change is needed and older notification records remain readable.
     */
    public static function priorityForType(?string $type): string
    {
        return match ($type) {
            'lead_assigned', 'lead_reassigned', 'lead_shared', 'visit_scheduled',
            'followup_assigned_urgent', 'followup_escalated', 'team_followup_escalated', 'booking_recorded',
            'brokerage_disputed' => 'urgent',
            'followup_assigned', 'followup_due_soon', 'lead_revived', 'brokerage_received', 'lead_lost' => 'important',
            default => 'info',
        };
    }

    /** All team-manager user ids that manage the given agent's team(s) */
    public function managerUserIdsForAgent(?int $agentId): array
    {
        if (! $agentId) return [];

        $teamIds = \App\Models\TeamMember::where('agent_id', $agentId)
            ->where('is_active', true)
            ->pluck('team_id')
            ->all();

        if (empty($teamIds)) return [];

        return Team::whereIn('id', $teamIds)
            ->whereNotNull('manager_user_id')
            ->pluck('manager_user_id')
            ->unique()
            ->all();
    }

    /** Team-manager user ids for a given lead's agent */
    public function managerUserIdsForLead(Lead $lead): array
    {
        return $this->managerUserIdsForAgent($lead->agent_id);
    }

    /**
     * Team-manager user ids for the teams routed to a given project.
     * Used to alert the right managers when an unrouted lead arrives.
     */
    public function managerUserIdsForProject(?int $projectId): array
    {
        if (! $projectId) return [];

        $teamIds = \Illuminate\Support\Facades\DB::table('project_team')
            ->where('project_id', $projectId)
            ->where('is_active', 1)
            ->pluck('team_id')
            ->all();

        if (empty($teamIds)) return [];

        return Team::whereIn('id', $teamIds)
            ->whereNotNull('manager_user_id')
            ->pluck('manager_user_id')
            ->unique()
            ->values()
            ->all();
    }

    /* ============================================================
       LEAD LIFECYCLE NOTIFICATIONS
       ============================================================ */

    public function notifyLeadAssigned(Lead $lead): void
    {
        $agent = $lead->agent;
        if (! $agent || ! $agent->user_id) return;

        $this->notify($agent->user_id, 'lead_assigned', '📌 New lead assigned', [
            'body'         => $lead->customer_name . ' · ' . $lead->phone,
            'icon'         => '📌',
            'action_url'   => '/leads/' . $lead->id,
            'related_type' => 'lead',
            'related_id'   => $lead->id,
        ]);
    }

    public function notifyLeadReassigned(Lead $lead, ?int $oldAgentId = null, ?string $note = null): void
    {
        $newAgent = $lead->agent;
        if (! $newAgent || ! $newAgent->user_id) return;

        if ($oldAgentId === $newAgent->id) return;

        $byName  = \App\Models\User::find(session('user_id'))?->name ?? 'Someone';
        $oldName = $oldAgentId ? (Agent::find($oldAgentId)?->user?->name ?? 'another agent') : null;

        $bodyLines = [ $lead->customer_name . ' · ' . $lead->phone ];
        $bodyLines[] = 'From: ' . ($oldName ?? 'unassigned') . ' → To you';
        $bodyLines[] = 'By: ' . $byName;
        if ($note && trim($note) !== '') {
            $bodyLines[] = 'Reason: ' . mb_substr(trim($note), 0, 200);
        }

        $this->notify($newAgent->user_id, 'lead_reassigned', '🔄 Lead reassigned to you', [
            'body'         => implode("\n", $bodyLines),
            'icon'         => '🔄',
            'action_url'   => '/leads/' . $lead->id,
            'related_type' => 'lead',
            'related_id'   => $lead->id,
        ]);
    }

    public function notifyLeadRevived(Lead $lead): void
    {
        $agent = $lead->agent;
        if (! $agent || ! $agent->user_id) return;

        $this->notify($agent->user_id, 'lead_revived', '🔄 Lead revived', [
            'body'         => $lead->customer_name . ' is back — pick up where you left off',
            'icon'         => '🔄',
            'action_url'   => '/leads/' . $lead->id,
            'related_type' => 'lead',
            'related_id'   => $lead->id,
        ]);
    }

    /**
     * Notify agents who own the customer's other active enquiries
     * that the same person just enquired for a different project.
     *
     * @param  Lead      $newLead     The newly created enquiry
     * @param  Customer  $customer    The customer record
     * @param  iterable  $otherLeads  Other active leads for the same customer
     */
    public function notifyCrossProjectEnquiry(Lead $newLead, Customer $customer, iterable $otherLeads): void
    {
        $newProjectName = $newLead->project?->name ?? 'another project';
        $newAgentName   = $newLead->agent?->user?->name ?? 'unassigned';

        foreach ($otherLeads as $otherLead) {
            $ownerUserId = $otherLead->agent?->user?->id;
            if (! $ownerUserId) continue;

            // Skip if the same agent owns both leads — they already know
            if ((int) $otherLead->agent_id === (int) $newLead->agent_id) continue;

            $body = $customer->name
                  . ' · your lead on ' . ($otherLead->project?->name ?? 'your project')
                  . ' — also enquired for ' . $newProjectName
                  . ' (' . $newAgentName . ')';

            $this->notify(
                $ownerUserId,
                'customer_cross_enquiry',
                '👀 Same customer enquired elsewhere',
                [
                    'body'         => $body,
                    'icon'         => '👀',
                    'action_url'   => '/leads/' . $newLead->id,
                    'related_type' => 'lead',
                    'related_id'   => $newLead->id,
                ]
            );
        }
    }

    public function notifyVisitScheduled(Lead $lead): void
    {
        $agent = $lead->agent;
        if (! $agent || ! $agent->user_id) return;

        $when = $lead->visit_scheduled_at?->format('d M, h:i A') ?? 'TBD';

        $this->notify($agent->user_id, 'visit_scheduled', '🏠 Site visit scheduled', [
            'body'         => $lead->customer_name . ' · ' . $when,
            'icon'         => '🏠',
            'action_url'   => '/leads/' . $lead->id,
            'related_type' => 'lead',
            'related_id'   => $lead->id,
        ]);
    }

    public function notifyBookingRecorded(Lead $lead): void
    {
        $body = $lead->customer_name . ' · ₹' . number_format((float) $lead->booking_amount, 0);

        // Notify admins
        foreach ($this->adminUserIds() as $uid) {
            $this->notify($uid, 'booking_recorded', '🎉 New booking recorded', [
                'body'         => $body,
                'icon'         => '🎉',
                'action_url'   => '/leads/' . $lead->id,
                'related_type' => 'lead',
                'related_id'   => $lead->id,
            ]);
        }

        // Notify team managers
        foreach ($this->managerUserIdsForLead($lead) as $uid) {
            $this->notify($uid, 'booking_recorded', '🎉 Team member booked a deal', [
                'body'         => $body,
                'icon'         => '🎉',
                'action_url'   => '/leads/' . $lead->id,
                'related_type' => 'lead',
                'related_id'   => $lead->id,
            ]);
        }
    }

    public function notifyLeadLost(Lead $lead): void
    {
        $body = $lead->customer_name . ' · '
              . ($lead->lost_reason ? mb_substr($lead->lost_reason, 0, 100) : 'No reason');

        foreach ($this->managerUserIdsForLead($lead) as $uid) {
            $this->notify($uid, 'lead_lost', '🚫 Lead marked Lost', [
                'body'         => $body,
                'icon'         => '🚫',
                'action_url'   => '/leads/' . $lead->id,
                'related_type' => 'lead',
                'related_id'   => $lead->id,
            ]);
        }
    }

    public function notifyLeadShared(Lead $lead, Agent $sharedWith, ?string $note = null): void
    {
        if (! $sharedWith->user_id) return;

        $primaryName = $lead->primaryAgent?->user?->name ?? 'another agent';
        $byName      = \App\Models\User::find(session('user_id'))?->name ?? $primaryName;

        $bodyLines   = [ $lead->customer_name . ' · ' . $lead->phone ];
        $bodyLines[] = 'By: ' . $byName;
        if ($note && trim($note) !== '') {
            $bodyLines[] = 'Reason: ' . mb_substr(trim($note), 0, 200);
        }

        $this->notify($sharedWith->user_id, 'lead_shared', '👥 Lead shared with you', [
            'body'         => implode("\n", $bodyLines),
            'icon'         => '👥',
            'action_url'   => '/leads/' . $lead->id,
            'related_type' => 'lead',
            'related_id'   => $lead->id,
        ]);
    }

    /* ============================================================
       BROKERAGE NOTIFICATIONS
       ============================================================ */

    public function notifyBrokerageReceived(Lead $lead, string $kind): void
    {
        $label = $kind === 'partial' ? 'Partial brokerage received' : 'Brokerage received';
        $icon  = '💰';
        $body  = $lead->customer_name . ' · ₹' . number_format((float) $lead->brokerage_amount, 0);

        foreach ($this->adminUserIds() as $uid) {
            $this->notify($uid, 'brokerage_received', $icon . ' ' . $label, [
                'body'         => $body,
                'icon'         => $icon,
                'action_url'   => '/leads/' . $lead->id,
                'related_type' => 'lead',
                'related_id'   => $lead->id,
            ]);
        }

        foreach ($this->managerUserIdsForLead($lead) as $uid) {
            $this->notify($uid, 'brokerage_received', $icon . ' ' . $label, [
                'body'         => $body,
                'icon'         => $icon,
                'action_url'   => '/leads/' . $lead->id,
                'related_type' => 'lead',
                'related_id'   => $lead->id,
            ]);
        }
    }

    public function notifyBrokerageDisputed(Lead $lead): void
    {
        $body = $lead->customer_name . ' · ₹' . number_format((float) $lead->brokerage_amount, 0);

        foreach ($this->adminUserIds() as $uid) {
            $this->notify($uid, 'brokerage_disputed', '⚠️ Brokerage dispute raised', [
                'body'         => $body,
                'icon'         => '⚠️',
                'action_url'   => '/leads/' . $lead->id,
                'related_type' => 'lead',
                'related_id'   => $lead->id,
            ]);
        }

        foreach ($this->managerUserIdsForLead($lead) as $uid) {
            $this->notify($uid, 'brokerage_disputed', '⚠️ Brokerage dispute raised', [
                'body'         => $body,
                'icon'         => '⚠️',
                'action_url'   => '/leads/' . $lead->id,
                'related_type' => 'lead',
                'related_id'   => $lead->id,
            ]);
        }
    }

    /* ============================================================
       FOLLOW-UP NOTIFICATIONS
       ============================================================ */

    /**
     * Notify the responsible agent when a new pending work item is created.
     * This is the immediate handoff notification; the existing due-soon and
     * overdue notifications remain the later reminders/escalations.
     */
    public function notifyFollowupAssigned(Followup $followup): void
    {
        if ($followup->status !== 'pending' || ! $followup->agent_id) return;

        $followup->loadMissing(['agent.user', 'lead.project']);
        $userId = $followup->agent?->user_id;
        if (! $userId) return;

        $leadName  = $followup->lead?->customer_name ?? 'Lead';
        $taskLabel = $this->actionLabel($followup->action_type);
        $when      = $followup->scheduled_for?->format('d M, h:i A') ?? 'scheduled time pending';
        $project   = $followup->lead?->project?->name;

        // A newly-created lead is already handed to the agent by the lead
        // assignment path. If the first automatic follow-up is created in the
        // same hand-off, do not create a second alert. Upgrade the existing
        // lead alert into one complete "new lead + first task" alert.
        if ($followup->auto_created && $this->coalesceImmediateLeadAssignment($followup, $userId, $leadName, $taskLabel, $when, $project)) {
            return;
        }

        $body = $leadName . ' · ' . $taskLabel . ' · ' . $when;
        if ($project) {
            $body .= ' · ' . $project;
        }

        $type = ($followup->scheduled_for && $followup->scheduled_for->lte(now()->addMinutes(30)))
            ? 'followup_assigned_urgent'
            : 'followup_assigned';

        $this->notify($userId, $type, '🎯 New task assigned to you', [
            'body'         => $body,
            'icon'         => '🎯',
            'action_url'   => '/leads/' . $followup->lead_id,
            'related_type' => 'followup',
            'related_id'   => $followup->id,
        ]);
    }

    /**
     * Merge the automatic first task into the immediately preceding new-lead
     * alert. This keeps one notification as the unit of work rather than
     * generating two alerts for one system decision.
     */
    private function coalesceImmediateLeadAssignment(
        Followup $followup,
        int $userId,
        string $leadName,
        string $taskLabel,
        string $when,
        ?string $project
    ): bool {
        $windowStart = now()->subMinutes(2);

        $notification = AppNotification::query()
            ->where('user_id', $userId)
            ->where('type', 'lead_assigned')
            ->where('related_type', 'lead')
            ->where('related_id', $followup->lead_id)
            ->where('created_at', '>=', $windowStart)
            ->orderByDesc('id')
            ->first();

        if (! $notification) return false;

        // If the user somehow opened the lead in the tiny gap between lead
        // assignment and automatic task creation, the lead alert already
        // delivered the hand-off. Do not immediately fire a second alert for
        // the same system-generated task.
        if ($notification->read_at) {
            $this->audit->record(
                'notification',
                'Suppressed duplicate task notification',
                null,
                [
                    'lead_id' => (int) $followup->lead_id,
                    'followup_id' => (int) $followup->id,
                    'source_notification_id' => (int) $notification->id,
                    'reason' => 'Automatic first follow-up was created immediately after a new-lead alert that the user had already opened.',
                ],
                'lead',
                $followup->lead_id,
                $userId,
                User::find($userId)?->name,
                User::find($userId)?->role,
            );
            return true;
        }

        $body = $leadName . ' · first task: ' . $taskLabel . ' · ' . $when;
        if ($project) $body .= ' · ' . $project;

        $notification->forceFill([
            'title' => '📌 New lead assigned · first task ready',
            'body' => $body,
            'icon' => '📌',
            'updated_at' => now(),
        ])->save();

        return true;
    }

    public function notifyFollowupDueSoon(Followup $followup): void
    {
        $agent = $followup->agent;
        if (! $agent || ! $agent->user_id) return;
        if ($followup->status !== 'pending') return;
        if (! $followup->scheduled_for) return;

        // Never alert before the scheduled time. One due notification is
        // allowed per scheduled occurrence.
        $now = now();
        if ($followup->scheduled_for->gt($now)) return;

        $alreadySent = AppNotification::where('user_id', $agent->user_id)
            ->where('type', 'followup_due_soon')
            ->where('related_type', 'followup')
            ->where('related_id', $followup->id)
            ->where('created_at', '>=', $followup->scheduled_for)
            ->exists();

        if ($alreadySent) return;

        $leadName  = $followup->lead?->customer_name ?? 'Lead';
        $taskLabel = $this->actionLabel($followup->action_type);
        $when      = $followup->scheduled_for->format('h:i A');

        $this->notify($agent->user_id, 'followup_due_soon', '⏰ Follow-up due now', [
            'body'         => $leadName . ' · ' . $taskLabel . ' · ' . $when,
            'icon'         => '⏰',
            'action_url'   => '/leads/' . $followup->lead_id,
            'related_type' => 'followup',
            'related_id'   => $followup->id,
        ]);
    }

    public function notifyFollowupEscalated(Followup $followup): void
    {
        $leadName  = $followup->lead?->customer_name ?? 'Lead';
        $taskLabel = $this->actionLabel($followup->action_type);

        // Notify the assigned agent
        $agent = $followup->agent;
        if ($agent && $agent->user_id) {
            $this->notify($agent->user_id, 'followup_escalated', '🚨 Task overdue', [
                'body'         => $leadName . ' · ' . $taskLabel . ' is overdue',
                'icon'         => '🚨',
                'action_url'   => '/leads/' . $followup->lead_id,
                'related_type' => 'followup',
                'related_id'   => $followup->id,
            ]);
        }

        // Notify the team manager
        $body = ($agent?->user?->name ?? 'Agent') . ' · ' . $leadName . ' · ' . $taskLabel;

        foreach ($this->managerUserIdsForAgent($followup->agent_id) as $uid) {
            $this->notify($uid, 'team_followup_escalated', '🚨 Team task overdue', [
                'body'         => $body,
                'icon'         => '🚨',
                'action_url'   => '/leads/' . $followup->lead_id,
                'related_type' => 'followup',
                'related_id'   => $followup->id,
            ]);
        }
    }

    /* ============================================================
       PERIODIC DIGESTS
       ============================================================ */

    public function generateOverdueDigest(int $userId): ?AppNotification
    {
        // Repeated overdue digests are intentionally disabled. Overdue escalation
        // is handled once per follow-up by notifyFollowupEscalated().
        return null;

        $agent = Agent::where('user_id', $userId)->first();
        if (! $agent) return null;

        $count = Followup::where('agent_id', $agent->id)
            ->where('status', 'pending')
            ->where('scheduled_for', '<', now())
            ->count();

        if ($count === 0) return null;

        $recent = AppNotification::where('user_id', $userId)
            ->where('type', 'overdue_digest')
            ->where('created_at', '>', now()->subHour())
            ->exists();

        if ($recent) return null;

        return $this->notify(
            $userId,
            'overdue_digest',
            "🚨 You have {$count} overdue task" . ($count === 1 ? '' : 's'),
            [
                'body'       => 'Open your dashboard to review them.',
                'icon'       => '🚨',
                'action_url' => '/',
            ]
        );
    }

    public function generateTeamOverdueDigest(int $managerUserId): ?AppNotification
    {
        // Repeated team overdue digests are intentionally disabled. Escalation
        // is sent once per overdue follow-up instead.
        return null;

        $agentIds = app(TeamService::class)->agentIdsForManager($managerUserId);
        if (empty($agentIds)) return null;

        $count = Followup::whereIn('agent_id', $agentIds)
            ->where('status', 'pending')
            ->where('scheduled_for', '<', now())
            ->count();

        if ($count === 0) return null;

        $recent = AppNotification::where('user_id', $managerUserId)
            ->where('type', 'team_overdue_digest')
            ->where('created_at', '>', now()->subHour())
            ->exists();

        if ($recent) return null;

        return $this->notify(
            $managerUserId,
            'team_overdue_digest',
            "🚨 Team has {$count} overdue task" . ($count === 1 ? '' : 's'),
            [
                'body'       => 'Review your team\'s pending work.',
                'icon'       => '🚨',
                'action_url' => '/',
            ]
        );
    }

    /* ============================================================
       HELPERS
       ============================================================ */

    /**
     * Reconcile the unread queue against the current CRM truth before it is
     * shown or pushed. A notification is an attention request, not a permanent
     * task. If the underlying work is already resolved, the alert becomes a
     * historical read event and the reason is written to the audit ledger.
     *
     * @return array<int,array<string,mixed>>
     */
    public function reconcileUnread(int $userId): array
    {
        $actionableTypes = [
            'lead_assigned', 'lead_reassigned', 'lead_shared', 'lead_revived',
            'visit_scheduled', 'followup_assigned', 'followup_assigned_urgent',
            'followup_due_soon', 'followup_escalated', 'team_followup_escalated',
            'lead_unassigned_urgent', 'team_overdue_digest',
        ];

        $cleared = [];
        $actor = User::find($userId);
        AppNotification::query()
            ->where('user_id', $userId)
            ->whereNull('read_at')
            ->whereIn('type', $actionableTypes)
            ->orderBy('id')
            ->chunkById(200, function ($notifications) use ($userId, $actor, &$cleared): void {
                foreach ($notifications as $notification) {
                    $decision = $this->staleDecision($notification, $userId);
                    if (! $decision) continue;

                    $readAt = now();
                    $notification->forceFill(['read_at' => $readAt])->save();

                    $event = [
                        'notification_id' => (int) $notification->id,
                        'notification_type' => $notification->type,
                        'notification_title' => $notification->title,
                        'lead_id' => $decision['lead_id'],
                        'reason_code' => $decision['reason_code'],
                        'reason' => $decision['reason'],
                        'state' => $decision['state'],
                        'read_at' => $readAt->toIso8601String(),
                        'automatic' => true,
                    ];
                    $cleared[] = $event;

                    $this->audit->record(
                        'notification',
                        'Auto-cleared stale notification',
                        null,
                        $event,
                        $decision['lead_id'] ? 'lead' : 'notification',
                        $decision['lead_id'] ?: $notification->id,
                        $userId,
                        $actor?->name,
                        $actor?->role?->value ?? $actor?->role,
                    );
                }
            });

        return $cleared;
    }

    private function staleDecision(AppNotification $notification, int $userId): ?array
    {
        $leadId = $this->leadIdForNotification($notification);
        if (! $leadId) return null;

        $lead = Lead::query()->find($leadId);
        if (! $lead) {
            return [
                'lead_id' => $leadId,
                'reason_code' => 'lead_no_longer_exists',
                'reason' => 'The lead no longer exists in the CRM.',
                'state' => ['lead_exists' => false],
            ];
        }

        // Final/closed leads no longer need action-oriented alerts. Event
        // notifications such as "Lead marked Lost" are deliberately excluded
        // from reconciliation by actionableTypes above, so the event itself is
        // never erased as stale.
        if ($lead->isFinal()) {
            return [
                'lead_id' => $leadId,
                'reason_code' => 'lead_already_closed',
                'reason' => 'The lead was already in a final/closed state before this alert was opened.',
                'state' => [
                    'status' => $lead->statusKey(),
                    'lost_reason_key' => $lead->lost_reason_key,
                    'lost_reason' => $lead->lost_reason,
                ],
            ];
        }

        // A follow-up alert is stale as soon as the underlying task is no
        // longer pending. This catches work completed from the dashboard,
        // lead page, mobile actions, or another workflow before the bell was
        // opened.
        if ($notification->related_type === 'followup' && $notification->related_id) {
            $followup = Followup::query()->find($notification->related_id);
            if (! $followup) {
                return [
                    'lead_id' => $leadId,
                    'reason_code' => 'task_no_longer_exists',
                    'reason' => 'The task referenced by this alert no longer exists.',
                    'state' => ['followup_id' => (int) $notification->related_id],
                ];
            }
            if ($followup->status !== 'pending') {
                $reasonCode = $followup->status === 'done' ? 'task_already_completed' : 'task_no_longer_pending';
                $reason = $followup->status === 'done'
                    ? 'The task was completed before the alert was opened.'
                    : 'The task was cancelled or otherwise removed from pending work before the alert was opened.';
                return [
                    'lead_id' => $leadId,
                    'reason_code' => $reasonCode,
                    'reason' => $reason,
                    'state' => [
                        'followup_id' => (int) $followup->id,
                        'followup_status' => $followup->status,
                    ],
                ];
            }
        }

        // A lead assignment alert becomes stale if the lead was moved to a
        // different primary owner before the recipient opened it.
        if (in_array($notification->type, ['lead_assigned', 'lead_reassigned'], true)) {
            $currentUserId = $lead->agent?->user_id;
            if ($currentUserId && (int) $currentUserId !== $userId) {
                return [
                    'lead_id' => $leadId,
                    'reason_code' => 'lead_reassigned_before_open',
                    'reason' => 'The lead was reassigned to another user before this alert was opened.',
                    'state' => ['current_agent_user_id' => (int) $currentUserId],
                ];
            }
        }

        // Strongest evidence that somebody already acted: a human activity
        // was recorded after the alert was generated. Automated system notes
        // are ignored so intake/automation does not clear its own alerts.
        // Escalation alerts are different: a manager may still need visibility
        // while a task remains pending, so those are cleared by task state
        // above rather than by any ordinary human activity.
        if (! in_array($notification->type, ['followup_escalated', 'team_followup_escalated'], true)) {
            $humanActivity = \App\Models\Activity::query()
                ->where('lead_id', $leadId)
                ->where('logged_at', '>', $notification->created_at)
                ->where(function ($q) {
                    $q->whereNull('action_source')->orWhere('action_source', '!=', 'automated');
                })
                ->orderBy('logged_at')
                ->first();
        } else {
            $humanActivity = null;
        }

        if ($humanActivity) {
            return [
                'lead_id' => $leadId,
                'reason_code' => 'lead_already_worked',
                'reason' => 'Human lead activity was recorded after this alert was generated, so the requested work was already acted upon.',
                'state' => [
                    'activity_id' => (int) $humanActivity->id,
                    'activity_type' => $humanActivity->type,
                    'activity_logged_at' => optional($humanActivity->logged_at)->toIso8601String(),
                ],
            ];
        }

        return null;
    }

    public function unreadCount(int $userId): int
    {
        $this->reconcileUnread($userId);
        return AppNotification::where('user_id', $userId)->whereNull('read_at')->count();
    }

    /**
     * Resolve the lead represented by a notification without changing the
     * notification schema. Follow-up reminders point at the follow-up id,
     * while lead notifications point directly at the lead. Action URLs are
     * retained as a final compatibility fallback.
     */
    public function leadIdForNotification(AppNotification $notification): ?int
    {
        if ($notification->related_type === 'lead' && $notification->related_id) {
            return (int) $notification->related_id;
        }

        if ($notification->related_type === 'followup' && $notification->related_id) {
            $leadId = Followup::whereKey($notification->related_id)->value('lead_id');
            if ($leadId) return (int) $leadId;
        }

        if ($notification->action_url && preg_match('#/leads/(\d+)#', $notification->action_url, $m)) {
            return (int) $m[1];
        }

        return null;
    }

    public function recent(int $userId, int $limit = 10)
    {
        return AppNotification::where('user_id', $userId)
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();
    }

    public function markAllRead(int $userId, ?\Carbon\CarbonInterface $readAt = null): int
    {
        $readAt ??= now();

        return AppNotification::where('user_id', $userId)
            ->whereNull('read_at')
            ->update(['read_at' => $readAt]);
    }

    private function actionLabel(?string $key): string
    {
        if (! $key) return 'Task';
        $t = app(SettingsService::class)->actionTypeByKey($key);
        return $t?->label ?? $key;
    }
}