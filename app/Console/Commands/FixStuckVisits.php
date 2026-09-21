<?php

namespace App\Console\Commands;

use App\Models\Activity;
use App\Models\Config\CallOutcome;
use App\Models\Lead;
use App\Services\LeadStatusService;
use App\Services\SettingsService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class FixStuckStatuses extends Command
{
    protected $signature   = 'leads:fix-stuck-statuses {--apply : Actually make changes}';
    protected $description = 'Advance leads whose activity history implies a higher status than they currently have';

    public function handle(SettingsService $settings, LeadStatusService $statusService): int
    {
        $dryRun = ! $this->option('apply');

        $leads = Lead::whereNotIn('status', ['booking', 'lost'])
            ->orderBy('id')
            ->get();

        $fixable = 0;
        $skipped = 0;

        foreach ($leads as $lead) {
            $activity = Activity::where('lead_id', $lead->id)
                ->whereNotNull('outcome_key')
                ->orderByDesc('logged_at')
                ->first();

            if (! $activity) { $skipped++; continue; }

            $outcome = CallOutcome::where('key', $activity->outcome_key)->first();
            if (! $outcome || ! $outcome->suggestedStatus) { $skipped++; continue; }

            $targetKey = $outcome->suggestedStatus->key;

            if ($targetKey === $lead->statusKey()) { $skipped++; continue; }

            $allowed = $settings->allowedTransitionsFrom($lead->statusKey());
            if (! in_array($targetKey, $allowed, true)) {
                $this->line("· [{$lead->id}] {$lead->customer_name}: {$lead->statusKey()} → {$targetKey} NOT ALLOWED — skipping");
                $skipped++;
                continue;
            }

            $this->info("→ [{$lead->id}] {$lead->customer_name}: {$lead->statusKey()} → {$targetKey}  (last outcome: {$activity->outcome_key})");

            if ($dryRun) { $fixable++; continue; }

            try {
                DB::transaction(function () use ($lead, $targetKey, $statusService) {
                    $statusService->change(
                        $lead,
                        $targetKey,
                        null,
                        null,
                        'Auto-advanced by leads:fix-stuck-statuses',
                        true,
                        [],
                        [],
                        'automated'
                    );
                });
                $fixable++;
            } catch (\Throwable $e) {
                $this->error("   Failed: " . $e->getMessage());
            }
        }

        $this->newLine();
        $this->info($dryRun
            ? "Dry-run complete. Would fix {$fixable}, skipped {$skipped}."
            : "Fixed {$fixable} lead(s), skipped {$skipped}.");

        return self::SUCCESS;
    }
}