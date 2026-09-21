<?php

namespace App\Console\Commands;

use App\Models\Lead;
use App\Services\NotificationService;
use App\Services\SettingsService;
use Illuminate\Console\Command;

class AlertUnassignedLeads extends Command
{
    protected $signature   = 'leads:alert-unassigned';
    protected $description = 'Alert admins + project managers about leads that have no agent assigned';

    public function __construct(
        private NotificationService $notifications,
        private SettingsService $settings,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $minAgeMinutes = max(1, (int) $this->settings->get('unassigned_alert_min_age_minutes', 5));
        $repeatMinutes = max(1, (int) $this->settings->get('unassigned_alert_repeat_minutes', 15));

        $finalStatuses = $this->settings->statuses()
            ->where('is_final', true)
            ->pluck('key')
            ->all();

        $leads = Lead::query()
            ->whereNull('agent_id')
            ->when(! empty($finalStatuses), fn ($q) => $q->whereNotIn('status', $finalStatuses))
            ->where('created_at', '<', now()->subMinutes($minAgeMinutes))
            ->where(function ($q) use ($repeatMinutes) {
                $q->whereNull('unassigned_alerted_at')
                  ->orWhere('unassigned_alerted_at', '<', now()->subMinutes($repeatMinutes));
            })
            ->orderBy('created_at')
            ->limit(100)
            ->get();

        if ($leads->isEmpty()) {
            $this->info('No unassigned leads to alert on.');
            return self::SUCCESS;
        }

        $admins     = $this->notifications->adminUserIds();
        $alertsSent = 0;

        foreach ($leads as $lead) {
            $managers   = $this->notifications->managerUserIdsForProject($lead->project_id);
            $recipients = array_values(array_unique(array_merge($admins, $managers)));

            if (empty($recipients)) {
                $this->warn("Lead {$lead->id}: no admins or managers to notify.");
                continue;
            }

            $age     = $lead->created_at?->diffForHumans(null, true) ?? '?';
            $project = $lead->project?->name ?? 'No project';

            $body = sprintf(
                '%s · %s · waiting %s',
                $lead->customer_name,
                $project,
                $age
            );

            foreach ($recipients as $uid) {
                $this->notifications->notify(
                    $uid,
                    'lead_unassigned_urgent',
                    '🚨 Lead needs routing NOW',
                    [
                        'body'         => $body,
                        'icon'         => '🚨',
                        'action_url'   => '/leads/' . $lead->id,
                        'related_type' => 'lead',
                        'related_id'   => $lead->id,
                    ]
                );
                $alertsSent++;
            }

            // Bypass mass-assignment guards
            $lead->forceFill(['unassigned_alerted_at' => now()])->save();

            $this->info("Alerted lead {$lead->id}: {$lead->customer_name} ({$project})");
        }

        $this->info("Done. Alerts sent: {$alertsSent} for " . $leads->count() . ' lead(s).');
        return self::SUCCESS;
    }
}