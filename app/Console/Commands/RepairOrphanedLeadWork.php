<?php

namespace App\Console\Commands;

use App\Models\Followup;
use App\Models\Lead;
use App\Services\LeadAssignmentService;
use App\Services\NotificationService;
use App\Services\SettingsService;
use App\Services\SmartFollowupService;
use Illuminate\Console\Command;
use Throwable;

class RepairOrphanedLeadWork extends Command
{
    protected $signature = 'leads:repair-orphans {--limit=200 : Maximum live leads to inspect per run}';
    protected $description = 'Recover live leads and pending work stranded by missing or inactive ownership';

    public function __construct(
        private LeadAssignmentService $assignment,
        private NotificationService $notifications,
        private SettingsService $settings,
        private SmartFollowupService $smartFollowups,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $limit = max(1, min(1000, (int) $this->option('limit')));
        $finalStatuses = $this->settings->statuses()
            ->where('is_final', true)
            ->pluck('key')
            ->all();

        $leads = Lead::query()
            ->with(['agent.user', 'project'])
            ->when(! empty($finalStatuses), fn ($q) => $q->whereNotIn('status', $finalStatuses))
            ->where(function ($q) {
                $q->whereNull('agent_id')
                    ->orWhereHas('agent', fn ($agent) => $agent->where('status', '!=', 'active'))
                    ->orWhere(function ($missingPivot) {
                        $missingPivot->whereNotNull('agent_id')
                            ->whereDoesntHave('assignments', function ($assignment) {
                                $assignment->whereColumn('agent_id', 'leads.agent_id')
                                    ->where('is_active', true)
                                    ->where('is_primary', true);
                            });
                    });
            })
            ->orderBy('created_at')
            ->limit($limit)
            ->get();

        $recovered = 0;
        $stillUnassigned = 0;
        $tasksRepaired = 0;

        foreach ($leads as $lead) {
            try {
                $before = $lead->agent_id;
                $owner = $this->assignment->assign($lead->fresh(['agent', 'project']));

                if ($owner) {
                    if (! $before || (int) $before !== (int) $owner->id) {
                        $recovered++;
                    }

                    $tasksRepaired += $this->repairPendingWork($lead->id, (int) $owner->id);
                    $this->info("Lead {$lead->id}: recovered to {$owner->user?->name}");
                    continue;
                }

                $stillUnassigned++;
                $this->cancelUnactionableTasks($lead->id);
                $this->alertUnroutedLead($lead);
                $this->warn("Lead {$lead->id}: still unassigned; routing alert sent/throttled.");
            } catch (Throwable $e) {
                // One damaged lead must never stop recovery of the remaining batch.
                report($e);
                $this->error("Lead {$lead->id}: recovery failed safely; continuing.");
            }
        }

        // Existing next-action repair is now run only after ownership recovery,
        // so it can never create a customer task attributed to a stale/fallback
        // agent when a live owner is available.
        $nextActionsFixed = $this->smartFollowups->ensureAllLeadsHaveNextAction();

        $this->info(sprintf(
            'Done. Recovered %d lead(s), repaired %d pending task(s), left %d lead(s) awaiting routing, created %d missing next action(s).',
            $recovered,
            $tasksRepaired,
            $stillUnassigned,
            $nextActionsFixed
        ));

        return self::SUCCESS;
    }

    private function repairPendingWork(int $leadId, int $ownerId): int
    {
        // Only repair tasks whose assigned agent is missing/inactive. Active
        // co-agent work and external site-team check-backs are left untouched.
        return Followup::query()
            ->where('lead_id', $leadId)
            ->where('status', 'pending')
            ->whereNotIn('action_type', ['check_shared_agent', 'check_site_team'])
            ->where(function ($q) {
                $q->whereNull('agent_id')
                    ->orWhereDoesntHave('agent', fn ($agent) => $agent->where('status', 'active'));
            })
            ->update([
                'agent_id' => $ownerId,
                'updated_at' => now(),
            ]);
    }

    private function cancelUnactionableTasks(int $leadId): void
    {
        Followup::query()
            ->where('lead_id', $leadId)
            ->where('status', 'pending')
            ->whereNotIn('action_type', ['check_site_team'])
            ->where(function ($q) {
                $q->whereNull('agent_id')
                    ->orWhereDoesntHave('agent', fn ($agent) => $agent->where('status', 'active'));
            })
            ->update([
                'status' => 'cancelled',
                'updated_at' => now(),
            ]);
    }

    private function alertUnroutedLead(Lead $lead): void
    {
        $repeatMinutes = max(1, (int) $this->settings->get('unassigned_alert_repeat_minutes', 15));
        $lastAlert = $lead->unassigned_alerted_at;

        if ($lastAlert && $lastAlert->greaterThan(now()->subMinutes($repeatMinutes))) {
            return;
        }

        $recipients = array_values(array_unique(array_merge(
            $this->notifications->adminUserIds(),
            $this->notifications->managerUserIdsForProject($lead->project_id)
        )));

        if (empty($recipients)) {
            return;
        }

        $body = $lead->customer_name . ' · ' . ($lead->project?->name ?? 'No project') . ' · no active owner available';

        foreach ($recipients as $userId) {
            $this->notifications->notify(
                $userId,
                'lead_unassigned_urgent',
                '🚨 Lead needs routing NOW',
                [
                    'body' => $body,
                    'icon' => '🚨',
                    'action_url' => '/leads/' . $lead->id,
                    'related_type' => 'lead',
                    'related_id' => $lead->id,
                ]
            );
        }

        $lead->forceFill(['unassigned_alerted_at' => now()])->save();
    }
}
