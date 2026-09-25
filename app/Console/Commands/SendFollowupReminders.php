<?php

namespace App\Console\Commands;

use App\Models\Followup;
use App\Services\NotificationService;
use Illuminate\Console\Command;

class SendFollowupReminders extends Command
{
    protected $signature   = 'followup:remind';
    protected $description = 'Send one reminder when a pending follow-up becomes due.';

    public function handle(NotificationService $notifications): int
    {
        $now = now();

        // Never notify before the scheduled time. Include all pending work
        // whose due time has arrived so scheduler downtime cannot cause a
        // missed due alert. Per-follow-up deduplication below prevents repeats.
        $due = Followup::where('status', 'pending')
            ->whereNotNull('scheduled_for')
            ->where('scheduled_for', '<=', $now)
            ->with(['lead', 'agent.user'])
            ->get();

        $count = 0;
        foreach ($due as $followup) {
            $before = \App\Models\AppNotification::where('user_id', $followup->agent?->user_id)
                ->where('type', 'followup_due_soon')
                ->where('related_type', 'followup')
                ->where('related_id', $followup->id)
                ->where('created_at', '>=', $followup->scheduled_for)
                ->exists();

            if ($before) {
                continue;
            }

            $notifications->notifyFollowupDueSoon($followup);
            $count++;
        }

        $this->info("✅ Sent {$count} due reminder(s).");
        return self::SUCCESS;
    }
}
