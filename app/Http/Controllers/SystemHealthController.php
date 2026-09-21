<?php

namespace App\Http\Controllers;

use App\Support\NpoRelease;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

class SystemHealthController extends Controller
{
    public function index()
    {
        if (session('user_role') !== 'admin') {
            abort(403, 'Only admins can access system health.');
        }

        $checks = [];
        $checks[] = $this->check('Application', 'Laravel application is running.', true);
        $checks[] = $this->check('PHP version', PHP_VERSION, version_compare(PHP_VERSION, '8.3.0', '>='));
        $checks[] = $this->check('Laravel version', app()->version(), version_compare(app()->version(), '13.0.0', '>='));

        $appKeyConfigured = (bool) config('app.key');
        $checks[] = $this->check('Application key', $appKeyConfigured ? 'Configured' : 'Missing', $appKeyConfigured);

        try {
            DB::connection()->getPdo();
            $checks[] = $this->check('Database connection', 'Connected', true);
        } catch (Throwable $e) {
            $checks[] = $this->check('Database connection', 'Failed to connect', false);
        }

        $requiredTables = [
            'users' => 'Users',
            'leads' => 'Leads',
            'projects' => 'Projects',
            'customers' => 'Customers',
            'followups' => 'Follow-ups',
            'activities' => 'Activities',
            'agents' => 'Agents',
            'teams' => 'Teams',
            'settings' => 'Settings',
        ];

        foreach ($requiredTables as $table => $label) {
            $exists = false;
            try {
                $exists = Schema::hasTable($table);
            } catch (Throwable $e) {
                $exists = false;
            }
            $checks[] = $this->check("Database: {$label}", $exists ? 'Table found' : 'Table missing', $exists);
        }

        $storagePath = storage_path();
        $cachePath = storage_path('framework/cache');
        $logsPath = storage_path('logs');

        $checks[] = $this->check('Storage writable', is_writable($storagePath) ? 'Writable' : 'Not writable', is_writable($storagePath));
        $checks[] = $this->check('Cache directory', is_dir($cachePath) && is_writable($cachePath) ? 'Writable' : 'Missing or not writable', is_dir($cachePath) && is_writable($cachePath));
        $checks[] = $this->check('Log directory', is_dir($logsPath) && is_writable($logsPath) ? 'Writable' : 'Missing or not writable', is_dir($logsPath) && is_writable($logsPath));

        $metaTable = false;
        try {
            $metaTable = Schema::hasTable('facebook_integrations');
        } catch (Throwable $e) {
            $metaTable = false;
        }
        $checks[] = $this->check('Meta/Facebook integration storage', $metaTable ? 'Available' : 'Table not found', $metaTable);

        $queueConnection = (string) config('queue.default', 'unknown');
        $checks[] = $this->check('Queue configuration', Str::upper($queueConnection), $queueConnection !== '' && $queueConnection !== 'unknown');

        $scheduledCommands = [
            'followup:remind',
            'followup:escalate',
            'leads:ensure-next-actions',
            'notifications:generate',
            'db:backup',
            'leads:alert-unassigned',
        ];
        // Laravel 13 no longer exposes Kernel::has() through the Artisan facade.
        // Load the registered command list once and check the command names directly.
        $registeredCommands = Artisan::all();
        $availableCommands = [];
        foreach ($scheduledCommands as $command) {
            $availableCommands[$command] = array_key_exists($command, $registeredCommands);
        }
        $scheduleOk = ! in_array(false, $availableCommands, true);
        $checks[] = $this->check('CRM scheduled commands', $scheduleOk ? count($scheduledCommands) . ' commands available' : 'One or more commands missing', $scheduleOk);

        $stats = [];
        foreach ([
            'users' => 'Users',
            'agents' => 'Agents',
            'teams' => 'Teams',
            'projects' => 'Projects',
            'leads' => 'Leads',
            'customers' => 'Customers',
            'followups' => 'Follow-ups',
            'activities' => 'Activities',
        ] as $table => $label) {
            try {
                if (Schema::hasTable($table)) {
                    $stats[] = ['label' => $label, 'value' => number_format((int) DB::table($table)->count())];
                }
            } catch (Throwable $e) {
                // Counts are informational only; a failed count must not break health page.
            }
        }

        $passCount = count(array_filter($checks, fn (array $check) => $check['ok']));
        $failCount = count($checks) - $passCount;

        return view('system.health', [
            'checks' => $checks,
            'stats' => $stats,
            'passCount' => $passCount,
            'failCount' => $failCount,
            'release' => [
                'version' => NpoRelease::version(),
                'date' => NpoRelease::releaseDate(),
                'name' => NpoRelease::releaseName(),
            ],
            'serverCronNote' => 'The browser can verify that CRM scheduled commands exist, but it cannot prove that the hosting provider is actually running the cPanel cron job. We will add a safe heartbeat check in a later release.',
        ]);
    }

    private function check(string $name, string $value, bool $ok): array
    {
        return ['name' => $name, 'value' => $value, 'ok' => $ok];
    }
}
