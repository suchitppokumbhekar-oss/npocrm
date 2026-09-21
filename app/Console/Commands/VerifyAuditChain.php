<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use Illuminate\Console\Command;

class VerifyAuditChain extends Command
{
    protected $signature = 'audit:verify-chain {--repair : Reserved for future migration tooling; never changes audit records}';
    protected $description = 'Verify the tamper-evident audit log hash chain without modifying it';

    public function handle(): int
    {
        $previous = null;
        $checked = 0;
        $errors = [];

        AuditLog::query()->orderBy('id')->chunkById(500, function ($logs) use (&$previous, &$checked, &$errors): void {
            foreach ($logs as $log) {
                $checked++;
                if ($log->previous_hash !== $previous) {
                    $errors[] = "ID {$log->id}: previous_hash does not match the preceding audit event.";
                }
                if (! $log->hashMatches()) {
                    $errors[] = "ID {$log->id}: stored hash does not match event contents.";
                }
                $previous = $log->hash;
            }
        });

        if ($errors !== []) {
            $this->error("Audit chain FAILED: {$checked} event(s) checked.");
            foreach (array_slice($errors, 0, 20) as $error) {
                $this->line(" - {$error}");
            }
            if (count($errors) > 20) {
                $this->line(' - ... additional integrity errors omitted from console output.');
            }
            return self::FAILURE;
        }

        $this->info("Audit chain verified: {$checked} event(s), no integrity errors.");
        return self::SUCCESS;
    }
}
