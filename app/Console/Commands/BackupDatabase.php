<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class BackupDatabase extends Command
{
    protected $signature   = 'db:backup';
    protected $description = 'Create a compressed MySQL backup and keep the last 7.';

    public function handle(): int
    {
        $db       = config('database.connections.mysql.database');
        $user     = config('database.connections.mysql.username');
        $pass     = config('database.connections.mysql.password');
        $host     = config('database.connections.mysql.host', '127.0.0.1');
        $filename = 'backup_' . now()->format('Y-m-d_His') . '.sql.gz';
        $path     = storage_path('app/backups/' . $filename);

        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }

        // Use mysqldump with --single-transaction for InnoDB consistency
        $cmd = sprintf(
            'mysqldump --single-transaction --quick --host=%s --user=%s --password=%s %s | gzip > %s 2>&1',
            escapeshellarg($host),
            escapeshellarg($user),
            escapeshellarg($pass),
            escapeshellarg($db),
            escapeshellarg($path)
        );

        exec($cmd, $output, $returnCode);

        if ($returnCode !== 0 || ! file_exists($path) || filesize($path) < 100) {
            $this->error('Backup failed: ' . implode("\n", $output));
            return self::FAILURE;
        }

        $this->info('✅ Backup created: ' . $filename . ' (' . round(filesize($path) / 1024) . ' KB)');

        // Keep only the last 7 backups
        $this->pruneOldBackups();

        return self::SUCCESS;
    }

    private function pruneOldBackups(): void
    {
        $dir = storage_path('app/backups');

        if (! is_dir($dir)) {
            return;
        }

        $files = glob($dir . '/backup_*.sql.gz');

        if (count($files) <= 7) {
            return;
        }

        usort($files, fn ($a, $b) => filemtime($b) - filemtime($a));
        $toDelete = array_slice($files, 7);

        foreach ($toDelete as $file) {
            @unlink($file);
            $this->line('  🗑️  Removed old: ' . basename($file));
        }
    }
}