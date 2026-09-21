<?php

namespace App\Console\Commands;

use App\Models\Followup;
use App\Models\Lead;
use App\Models\Project;
use App\Services\ProjectRoutingReconciler;
use App\Services\SettingsService;
use Illuminate\Console\Command;
use Throwable;

class ReconcileProjectRouting extends Command
{
    protected $signature = 'projects:reconcile-routing {--limit=500 : Maximum projects to inspect per run}';
    protected $description = 'Keep project routing, lead ownership, and pending work synchronized';

    public function __construct(
        private ProjectRoutingReconciler $reconciler,
        private SettingsService $settings,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $limit = max(1, min(2000, (int) $this->option('limit')));
        $processed = 0;
        $changed = 0;
        $failed = 0;

        // Only inspect projects that can actually have routing/ownership work.
        // The command is scheduled repeatedly, so no manual reconciliation is needed
        // after an admin changes routing.
        Project::query()
            ->where(function ($q) {
                $q->whereHas('leads')
                    ->orWhereExists(function ($routing) {
                        $routing->selectRaw('1')
                            ->from('project_agent')
                            ->whereColumn('project_agent.project_id', 'projects.id')
                            ->where('project_agent.is_active', 1);
                    })
                    ->orWhereExists(function ($routing) {
                        $routing->selectRaw('1')
                            ->from('project_team')
                            ->whereColumn('project_team.project_id', 'projects.id')
                            ->where('project_team.is_active', 1);
                    });
            })
            ->orderBy('id')
            ->limit($limit)
            ->get()
            ->each(function (Project $project) use (&$processed, &$changed, &$failed) {
                try {
                    $before = $project->fresh()->eligibleAgentIdsBySource();
                    $effective = ! empty($before['direct'])
                        ? $before['direct']
                        : ($before['team'] ?? []);

                    $result = $this->reconciler->reconcile($project, $effective);
                    $processed++;

                    if (($result['removed_assignments'] ?? 0) > 0
                        || ($result['rerouted_leads'] ?? 0) > 0
                        || ($result['unassigned_leads'] ?? 0) > 0) {
                        $changed++;
                    }
                } catch (Throwable $e) {
                    $failed++;
                    report($e);
                    $this->error("Project {$project->id}: routing reconciliation failed; continuing.");
                }
            });

        // Safety net for an already-reconciled lead whose old pending task survived
        // a previous routing change. This is deliberately limited to ordinary work;
        // site-team/customer-share check-backs are not project-routing ownership.
        $taskResult = $this->repairStalePendingWork($limit);

        $this->info(sprintf(
            'Routing reconciliation complete. Projects inspected: %d, projects changed: %d, failures: %d, stale tasks repaired: %d.',
            $processed,
            $changed,
            $failed,
            $taskResult
        ));

        return $failed > 0 ? self::SUCCESS : self::SUCCESS;
    }

    private function repairStalePendingWork(int $limit): int
    {
        $finalStatuses = $this->settings->statuses()
            ->where('is_final', true)
            ->pluck('key')
            ->all();

        $repaired = 0;

        Followup::query()
            ->with(['lead.project'])
            ->where('status', 'pending')
            ->whereNotIn('action_type', ['check_site_team'])
            ->whereNotNull('agent_id')
            ->whereHas('lead', function ($q) use ($finalStatuses) {
                if (! empty($finalStatuses)) {
                    $q->whereNotIn('status', $finalStatuses);
                }
            })
            ->orderBy('id')
            ->limit($limit)
            ->get()
            ->each(function (Followup $followup) use (&$repaired) {
                $lead = $followup->lead;
                $project = $lead?->project;
                if (! $lead || ! $project) {
                    return;
                }

                $routing = $project->fresh()->eligibleAgentIdsBySource();
                $eligible = ! empty($routing['direct'])
                    ? $routing['direct']
                    : ($routing['team'] ?? []);
                $eligible = array_values(array_unique(array_map('intval', $eligible)));

                if (in_array((int) $followup->agent_id, $eligible, true)) {
                    return;
                }

                // Shared-agent check-backs are tied to internal sharing provenance,
                // not ordinary project work. Once that agent is no longer routed to
                // the project, the check-back must not remain overdue in their work.
                if ($followup->action_type === 'check_shared_agent') {
                    $followup->update(['status' => 'cancelled', 'updated_at' => now()]);
                    $repaired++;
                    return;
                }

                $ownerId = (int) ($lead->agent_id ?? 0);
                if ($ownerId && in_array($ownerId, $eligible, true)) {
                    $followup->update(['agent_id' => $ownerId, 'updated_at' => now()]);
                    $repaired++;
                }
            });

        return $repaired;
    }
}
