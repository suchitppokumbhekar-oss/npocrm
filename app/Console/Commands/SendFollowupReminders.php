<?php

namespace App\Console\Commands;

use App\Models\Followup;
use App\Services\NotificationService;
use Illuminate\Console\Command;

class SendFollowupReminders extends Command
{
    protected $signature   = 'followup:remind';
    protected $description = 'Send one reminder about 30 minutes before a follow-up is due.';

    public function handle(NotificationService $notifications): int
    {
        $now = now();

        // The scheduler runs every five minutes. Keep a tight occurrence window
        // so a follow-up gets at most one reminder for each scheduled occurrence.
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
                ->whereBetween('created_at', [
                    $followup->scheduled_for->copy()->subMinutes(35),
                    $followup->scheduled_for->copy()->subMinutes(25),
                ])
                ->exists();

            if ($before) {
                continue;
            }

            $notifications->notifyFollowupDueSoon($followup);
            $count++;
        }

        $this->info("✅ Sent {$count} due-soon reminder(s).");
        return self::SUCCESS;
    }
}
