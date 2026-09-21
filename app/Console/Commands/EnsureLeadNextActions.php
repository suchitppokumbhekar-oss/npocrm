<?php

namespace App\Console\Commands;

use App\Services\SmartFollowupService;
use Illuminate\Console\Command;

class EnsureLeadNextActions extends Command
{
    protected $signature   = 'leads:ensure-next-actions';
    protected $description = 'Ensure every active lead has a pending next action.';

    public function handle(SmartFollowupService $service): int
    {
        $count = $service->ensureAllLeadsHaveNextAction();
        $this->info("✅ Fixed {$count} lead(s) missing a next action.");
        return self::SUCCESS;
    }
}