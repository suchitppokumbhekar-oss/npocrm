<?php

namespace App\Console\Commands;

use App\Models\Lead;
use App\Services\FollowupService;
use Illuminate\Console\Command;

class BackfillVisitFollowups extends Command
{
    protected $signature   = 'leads:backfill-visit-followups';
    protected $description = 'Create visit-specific followups for leads at visit_scheduled that are missing them.';

    public function handle(FollowupService $followups): int
    {
        $leads = Lead::where('status', 'visit_scheduled')
            ->whereNotNull('visit_scheduled_at')
            ->whereDoesntHave('followups', function ($q) {
                $q->where('status', 'pending')
                  ->whereIn('action_type', ['visit_reminder', 'visit_feedback_call', 'visit_outcome_call']);
            })
            ->get();

        $fixed = 0;
        foreach ($leads as $lead) {
            $followups->createVisitFollowups($lead);
            $this->line("✅ Backfilled: {$lead->customer_name} (ID {$lead->id})");
            $fixed++;
        }

        $this->info("✅ Backfilled {$fixed} lead(s).");
        return self::SUCCESS;
    }
}