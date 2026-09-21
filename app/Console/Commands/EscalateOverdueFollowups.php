<?php

namespace App\Console\Commands;

use App\Models\Followup;
use App\Services\NotificationService;
use App\Services\AuditLogService;
use Illuminate\Console\Command;

class EscalateOverdueFollowups extends Command
{
    protected $signature   = 'followup:escalate';
    protected $description = 'Flag follow-ups overdue by more than 2 hours as escalated.';

    public function handle(NotificationService $notifications, AuditLogService $audit): int
    {
        $overdue = Followup::where('status', 'pending')
            ->where('scheduled_for', '<', now()->subHours(2))
            ->where('escalated_flag', false)
            ->with(['lead', 'agent.user'])
            ->get();

        $count = 0;
        foreach ($overdue as $followup) {
            $followup->update(['escalated_flag' => true, 'updated_at' => now()]);
            $managerIds = $notifications->managerUserIdsForAgent($followup->agent_id);
            $managerNames = \App\Models\User::whereIn('id', $managerIds)->pluck('name', 'id')->map(fn ($name) => (string) $name)->all();
            $recipients = [];
            if ($followup->agent?->user_id) {
                $recipients[] = [
                    'user_id' => (int) $followup->agent->user_id,
                    'name' => (string) ($followup->agent->user?->name ?? 'Assigned agent'),
                    'role' => 'assigned_agent',
                ];
            }
            foreach ($managerIds as $managerId) {
                $recipients[] = [
                    'user_id' => (int) $managerId,
                    'name' => (string) ($managerNames[$managerId] ?? 'Team manager'),
                    'role' => 'team_manager',
                ];
            }
            $audit->record(
                'followup',
                'followup_escalated',
                request(),
                [
                    'followup_id' => (int) $followup->id,
                    'lead_id' => (int) $followup->lead_id,
                    'lead_name' => $followup->lead?->customer_name,
                    'assigned_agent_id' => $followup->agent_id ? (int) $followup->agent_id : null,
                    'assigned_agent_name' => $followup->agent?->user?->name,
                    'recipients' => $recipients,
                    'escalated_to_user_ids' => array_values(array_map(fn ($r) => (int) $r['user_id'], $recipients)),
                    'escalated_to_names' => array_values(array_map(fn ($r) => $r['name'], $recipients)),
                    'reason' => 'Follow-up overdue by more than 2 hours.',
                    'task_action_type' => $followup->action_type,
                ],
                'followup',
                $followup->id,
            );
            $notifications->notifyFollowupEscalated($followup);
            $count++;
        }

        $this->info("✅ Escalated {$count} follow-up(s).");
        return self::SUCCESS;
    }
}