<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class GenerateNotifications extends Command
{
    protected $signature   = 'notifications:generate';
    protected $description = 'Legacy notification digest generator (disabled by policy).';

    public function handle(): int
    {
        $this->info('ℹ️ Repeated overdue digests are disabled.');
        return self::SUCCESS;
    }
}
