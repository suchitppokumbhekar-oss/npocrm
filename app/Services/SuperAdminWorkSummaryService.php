<?php

namespace App\Services;

use App\Models\Activity;
use App\Models\Agent;
use App\Models\Followup;
use App\Models\Lead;
use App\Models\LeadAgent;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Super Admin operational work ledger.
 *
 * Counts are deliberately defined from existing CRM records rather than
 * browser/session activity. Every metric is backed by lead/task records and
 * can therefore drill down to the underlying leads.
 */
class SuperAdminWorkSummaryService
{
    public const PERIODS = ['today', 'week', 'month'];

    public const METRICS = [
        'new_leads' => 'New leads received',
        'calls' => 'Leads called',
        'whatsapp' => 'Leads contacted on WhatsApp',
        'external_share' => 'Leads shared externally',
        'internal_share' => 'Leads shared internally',
        'tasks_completed' => 'Tasks completed',
        'overdue' => 'Overdue tasks now',
        'tomorrow' => 'Tasks due tomorrow',
        'bookings' => 'Bookings recorded',
        'site_visits' => 'Site visits recorded',
    ];

    public function __construct(private SuperAdminService $superAdmins) {}

    public function requireAccess(): void
    {
        $this->superAdmins->requireSuperAdmin();
    }

    public function period(string $key): array
    {
        $now = now();

        return match ($key) {
            'week' => [$now->copy()->startOfWeek(), $now->copy()->endOfWeek()],
            'month' => [$now->copy()->startOfMonth(), $now->copy()->endOfMonth()],
            default => [$now->copy()->startOfDay(), $now->copy()->endOfDay()],
        };
    }

    public function agents(): Collection
    {
        return Agent::with('user')
            ->where('status', 'active')
            ->orderBy('id')
            ->get();
    }

    public function summary(string $period = 'today'): Collection
    {
        $period = in_array($period, self::PERIODS, true) ? $period : 'today';
        [$from, $to] = $this->period($period);
        $agents = $this->agents();
        $agentIds = $agents->pluck('id')->map(fn ($id) => (int) $id)->all();

        if (empty($agentIds)) return collect();

        $newLeads = LeadAgent::query()
            ->whereIn('agent_id', $agentIds)
            ->where('is_active', true)
            ->where('is_primary', true)
            ->whereBetween('created_at', [$from, $to])
            ->select('agent_id')
            ->selectRaw('COUNT(DISTINCT lead_id) AS total')
            ->groupBy('agent_id')
            ->pluck('total', 'agent_id');

        $activityCounts = Activity::query()
            ->whereIn('agent_id', $agentIds)
            ->whereBetween('logged_at', [$from, $to])
            ->whereIn('type', ['call', 'whatsapp', 'external_share', 'lead_shared'])
            ->where(function ($q) {
                $q->where('type', '!=', 'lead_shared')
                  ->orWhere('outcome', 'like', 'Shared with %');
            })
            ->select('agent_id', 'type')
            ->selectRaw('COUNT(DISTINCT lead_id) AS total')
            ->groupBy('agent_id', 'type')
            ->get()
            ->groupBy('agent_id');

        $completedTasks = Followup::query()
            ->whereIn('agent_id', $agentIds)
            ->where('status', 'done')
            ->whereBetween('updated_at', [$from, $to])
            ->select('agent_id')
            ->selectRaw('COUNT(*) AS total')
            ->groupBy('agent_id')
            ->pluck('total', 'agent_id');

        $overdue = Followup::query()
            ->whereIn('agent_id', $agentIds)
            ->where('status', 'pending')
            ->whereNotNull('scheduled_for')
            ->where('scheduled_for', '<', now())
            ->where('action_type', '!=', 'reactivation_call')
            ->select('agent_id')
            ->selectRaw('COUNT(*) AS total')
            ->groupBy('agent_id')
            ->pluck('total', 'agent_id');

        $tomorrow = Followup::query()
            ->whereIn('agent_id', $agentIds)
            ->where('status', 'pending')
            ->whereNotNull('scheduled_for')
            ->whereBetween('scheduled_for', [now()->addDay()->startOfDay(), now()->addDay()->endOfDay()])
            ->where('action_type', '!=', 'reactivation_call')
            ->select('agent_id')
            ->selectRaw('COUNT(*) AS total')
            ->groupBy('agent_id')
            ->pluck('total', 'agent_id');

        $bookings = Activity::query()
            ->whereIn('agent_id', $agentIds)
            ->where('type', 'status_change')
            ->where('outcome_key', 'booking')
            ->whereBetween('logged_at', [$from, $to])
            ->select('agent_id')
            ->selectRaw('COUNT(DISTINCT lead_id) AS total')
            ->groupBy('agent_id')
            ->pluck('total', 'agent_id');

        $siteVisits = DB::table('site_visits')
            ->whereIn('agent_id', $agentIds)
            ->whereBetween('visit_at', [$from, $to])
            ->select('agent_id')
            ->selectRaw('COUNT(DISTINCT id) AS total')
            ->groupBy('agent_id')
            ->pluck('total', 'agent_id');

        return $agents->map(function ($agent) use ($activityCounts, $newLeads, $completedTasks, $overdue, $tomorrow, $bookings, $siteVisits) {
            $rows = collect($activityCounts->get($agent->id, []))->keyBy('type');
            $name = $agent->user?->name ?? ('Agent #' . $agent->id);

            return (object) [
                'agent_id' => (int) $agent->id,
                'name' => $name,
                'new_leads' => (int) ($newLeads[$agent->id] ?? 0),
                'calls' => (int) (($rows['call']->total ?? 0)),
                'whatsapp' => (int) (($rows['whatsapp']->total ?? 0)),
                'external_share' => (int) (($rows['external_share']->total ?? 0)),
                'internal_share' => (int) (($rows['lead_shared']->total ?? 0)),
                'tasks_completed' => (int) ($completedTasks[$agent->id] ?? 0),
                'overdue' => (int) ($overdue[$agent->id] ?? 0),
                'tomorrow' => (int) ($tomorrow[$agent->id] ?? 0),
                'bookings' => (int) ($bookings[$agent->id] ?? 0),
                'site_visits' => (int) ($siteVisits[$agent->id] ?? 0),
            ];
        })->values();
    }

    public function details(int $agentId, string $metric, string $period = 'today'): array
    {
        $period = in_array($period, self::PERIODS, true) ? $period : 'today';
        if (! array_key_exists($metric, self::METRICS)) abort(404, 'Unknown work metric.');
        $agent = Agent::with('user')->findOrFail($agentId);
        [$from, $to] = $this->period($period);

        $rows = collect();

        switch ($metric) {
            case 'new_leads':
                $rows = LeadAgent::with(['lead.project'])
                    ->where('agent_id', $agentId)->where('is_active', true)->where('is_primary', true)
                    ->whereBetween('created_at', [$from, $to])
                    ->orderByDesc('created_at')->get()
                    ->unique('lead_id')->values()
                    ->map(fn ($r) => $this->leadRow($r->lead, $r->created_at, 'Received as primary lead'));
                break;
            case 'calls':
            case 'whatsapp':
            case 'external_share':
            case 'internal_share':
                $type = $metric === 'internal_share' ? 'lead_shared' : $metric;
                $rows = Activity::with(['lead.project'])
                    ->where('agent_id', $agentId)->where('type', $type)
                    ->when($type === 'lead_shared', fn ($q) => $q->where('outcome', 'like', 'Shared with %'))
                    ->whereBetween('logged_at', [$from, $to])
                    ->orderByDesc('logged_at')->get()
                    ->unique('lead_id')->values()
                    ->map(fn ($r) => $this->leadRow($r->lead, $r->logged_at, $r->displayLabel(140)));
                break;
            case 'tasks_completed':
                $rows = Followup::with(['lead.project'])
                    ->where('agent_id', $agentId)->where('status', 'done')
                    ->whereBetween('updated_at', [$from, $to])
                    ->orderByDesc('updated_at')->get()
                    ->map(fn ($r) => $this->leadRow($r->lead, $r->updated_at, 'Completed: ' . ($r->action_type ?: 'task')));
                break;
            case 'overdue':
                $rows = Followup::with(['lead.project'])
                    ->where('agent_id', $agentId)->where('status', 'pending')
                    ->whereNotNull('scheduled_for')->where('scheduled_for', '<', now())
                    ->where('action_type', '!=', 'reactivation_call')
                    ->orderBy('scheduled_for')->get()
                    ->map(fn ($r) => $this->leadRow($r->lead, $r->scheduled_for, 'Overdue: ' . ($r->action_type ?: 'task')));
                break;
            case 'tomorrow':
                $rows = Followup::with(['lead.project'])
                    ->where('agent_id', $agentId)->where('status', 'pending')
                    ->whereNotNull('scheduled_for')
                    ->whereBetween('scheduled_for', [now()->addDay()->startOfDay(), now()->addDay()->endOfDay()])
                    ->where('action_type', '!=', 'reactivation_call')
                    ->orderBy('scheduled_for')->get()
                    ->map(fn ($r) => $this->leadRow($r->lead, $r->scheduled_for, 'Tomorrow: ' . ($r->action_type ?: 'task')));
                break;
            case 'bookings':
                $rows = Activity::with(['lead.project'])
                    ->where('agent_id', $agentId)->where('type', 'status_change')->where('outcome_key', 'booking')
                    ->whereBetween('logged_at', [$from, $to])->orderByDesc('logged_at')->get()
                    ->unique('lead_id')->values()
                    ->map(fn ($r) => $this->leadRow($r->lead, $r->logged_at, 'Booking recorded'));
                break;
            case 'site_visits':
                $rows = DB::table('site_visits as sv')
                    ->leftJoin('leads as l', 'l.id', '=', 'sv.lead_id')
                    ->leftJoin('projects as p', 'p.id', '=', 'l.project_id')
                    ->where('sv.agent_id', $agentId)->whereBetween('sv.visit_at', [$from, $to])
                    ->orderByDesc('sv.visit_at')
                    ->get([
                        'sv.id as visit_id', 'sv.lead_id', 'sv.visit_at', 'sv.client_attended',
                        'l.customer_name', 'l.status', 'p.name as project_name',
                    ])
                    ->map(fn ($r) => (object) [
                        'lead_id' => (int) $r->lead_id,
                        'name' => $r->customer_name ?: ('Lead #' . $r->lead_id),
                        'project' => $r->project_name,
                        'at' => Carbon::parse($r->visit_at),
                        'detail' => 'Site visit · ' . ((int) $r->client_attended ? 'customer attended' : 'customer did not attend'),
                    ]);
                break;
        }

        return [
            'agent' => $agent,
            'metric' => $metric,
            'label' => self::METRICS[$metric],
            'period' => $period,
            'from' => $from,
            'to' => $to,
            'rows' => $rows->values(),
        ];
    }


    /**
     * Reconcile the operational work report against raw CRM history and
     * compare the selected agent's dashboard lead scope with the legacy
     * primary-agent report logic. This is intentionally read-only.
     */
    public function reconciliation(int $agentId, string $period = 'today'): array
    {
        $period = in_array($period, self::PERIODS, true) ? $period : 'today';
        $agent = Agent::with('user')->findOrFail($agentId);
        [$from, $to] = $this->period($period);

        $definitions = [];
        foreach (self::METRICS as $metric => $label) {
            $reportCount = (int) (($this->summary($period)->firstWhere('agent_id', $agentId)?->{$metric}) ?? 0);
            $rawCount = 0;
            $reason = 'Same CRM records and counting grain.';

            switch ($metric) {
                case 'new_leads':
                    $rawCount = (int) LeadAgent::query()
                        ->where('agent_id', $agentId)
                        ->whereBetween('created_at', [$from, $to])
                        ->where('is_primary', true)
                        ->where('is_active', true)
                        ->count();
                    $allHistory = (int) LeadAgent::query()
                        ->where('agent_id', $agentId)
                        ->whereBetween('created_at', [$from, $to])
                        ->where('is_primary', true)
                        ->count();
                    if ($allHistory !== $rawCount) {
                        $reason = "Report counts primary assignments that are still active; {$allHistory} assignment history row(s) exist, but " . ($allHistory - $rawCount) . " became inactive.";
                    }
                    break;
                case 'calls':
                case 'whatsapp':
                case 'external_share':
                case 'internal_share':
                    $type = $metric === 'internal_share' ? 'lead_shared' : $metric;
                    $q = Activity::query()
                        ->where('agent_id', $agentId)
                        ->where('type', $type)
                        ->whereBetween('logged_at', [$from, $to]);
                    if ($type === 'lead_shared') $q->where('outcome', 'like', 'Shared with %');
                    $rawCount = (int) (clone $q)->count();
                    $distinct = (int) (clone $q)->distinct('lead_id')->count('lead_id');
                    if ($rawCount !== $distinct) {
                        $reason = "Report says distinct leads worked. Raw CRM history has {$rawCount} event(s) across {$distinct} distinct lead(s); repeated actions on the same lead are not additional leads.";
                    } else {
                        $reason = 'Report counts distinct leads; raw CRM history currently has one matching event per lead.';
                    }
                    break;
                case 'tasks_completed':
                    $rawCount = (int) Followup::query()
                        ->where('agent_id', $agentId)->where('status', 'done')
                        ->whereBetween('updated_at', [$from, $to])->count();
                    $reason = 'Both use completed follow-up task rows; multiple tasks on one lead are intentionally counted separately here.';
                    break;
                case 'overdue':
                    $rawCount = (int) Followup::query()
                        ->where('agent_id', $agentId)->where('status', 'pending')
                        ->whereNotNull('scheduled_for')->where('scheduled_for', '<', now())
                        ->where('action_type', '!=', 'reactivation_call')->count();
                    $reason = 'Current snapshot, not historical work: pending tasks overdue right now.';
                    break;
                case 'tomorrow':
                    $rawCount = (int) Followup::query()
                        ->where('agent_id', $agentId)->where('status', 'pending')
                        ->whereNotNull('scheduled_for')
                        ->whereBetween('scheduled_for', [now()->addDay()->startOfDay(), now()->addDay()->endOfDay()])
                        ->where('action_type', '!=', 'reactivation_call')->count();
                    $reason = 'Current snapshot, not historical work: pending tasks scheduled for tomorrow.';
                    break;
                case 'bookings':
                    $rawCount = (int) Activity::query()
                        ->where('agent_id', $agentId)->where('type', 'status_change')->where('outcome_key', 'booking')
                        ->whereBetween('logged_at', [$from, $to])->count();
                    $distinct = (int) Activity::query()
                        ->where('agent_id', $agentId)->where('type', 'status_change')->where('outcome_key', 'booking')
                        ->whereBetween('logged_at', [$from, $to])->distinct('lead_id')->count('lead_id');
                    if ($rawCount !== $distinct) {
                        $reason = "Report counts distinct booked leads. Raw booking status history has {$rawCount} event(s) across {$distinct} lead(s).";
                    } else {
                        $reason = 'Report counts distinct leads with a booking status event.';
                    }
                    break;
                case 'site_visits':
                    $rawCount = (int) DB::table('site_visits')
                        ->where('agent_id', $agentId)->whereBetween('visit_at', [$from, $to])->count();
                    $reason = 'Both use site_visit rows recorded for the agent in the selected period.';
                    break;
            }

            $definitions[] = [
                'metric' => $metric,
                'label' => $label,
                'report_count' => $reportCount,
                'raw_count' => $rawCount,
                'difference' => $reportCount - $rawCount,
                'reason' => $reason,
            ];
        }

        // Dashboard lead scope: active lead_agents for this agent.
        $dashboardLeadIds = LeadAgent::query()
            ->where('agent_id', $agentId)->where('is_active', true)
            ->pluck('lead_id')->unique()->values()->all();

        // Legacy agent report scope: leads.agent_id primary pointer.
        $primaryLeadIds = Lead::query()->where('agent_id', $agentId)->pluck('id')->map(fn ($id) => (int) $id)->all();

        $dashboardCounts = Lead::query()
            ->when(empty($dashboardLeadIds), fn ($q) => $q->whereRaw('1 = 0'), fn ($q) => $q->whereIn('id', $dashboardLeadIds))
            ->select('status')->selectRaw('COUNT(*) AS total')->groupBy('status')->pluck('total', 'status')->toArray();

        $primaryCounts = Lead::query()
            ->where('agent_id', $agentId)
            ->select('status')->selectRaw('COUNT(*) AS total')->groupBy('status')->pluck('total', 'status')->toArray();

        $allStatuses = collect(array_unique(array_merge(array_keys($dashboardCounts), array_keys($primaryCounts))))->sort()->values();
        $pipeline = $allStatuses->map(function ($status) use ($dashboardCounts, $primaryCounts) {
            $d = (int) ($dashboardCounts[$status] ?? 0);
            $p = (int) ($primaryCounts[$status] ?? 0);
            return [
                'status' => $status,
                'dashboard_count' => $d,
                'agent_report_count' => $p,
                'difference' => $d - $p,
                'reason' => $d === $p
                    ? 'Same lead population for this status.'
                    : 'Dashboard uses active lead_agents (including active shared participation); Agent Performance uses leads.agent_id (primary lead pointer). Shared/non-primary participation and historical assignment changes can therefore produce a difference.',
            ];
        })->all();

        $dashboardOnly = array_values(array_diff($dashboardLeadIds, $primaryLeadIds));
        $primaryOnly = array_values(array_diff($primaryLeadIds, $dashboardLeadIds));

        $metricEvidence = [];
        foreach ($definitions as $definition) {
            if ($definition['difference'] === 0 && $definition['metric'] !== 'tasks_completed') {
                continue;
            }
            $metricEvidence[$definition['metric']] = $this->reconciliationMetricEvidence($agentId, $definition['metric'], $from, $to);
        }

        return [
            'agent' => $agent,
            'period' => $period,
            'from' => $from,
            'to' => $to,
            'metrics' => $definitions,
            'metric_evidence' => $metricEvidence,
            'pipeline' => $pipeline,
            'dashboard_lead_count' => count($dashboardLeadIds),
            'primary_report_lead_count' => count($primaryLeadIds),
            'dashboard_only_count' => count($dashboardOnly),
            'primary_only_count' => count($primaryOnly),
            'scope_reason' => 'Dashboard/operational scope follows active lead_agents. The existing Agent Performance report follows leads.agent_id. These are intentionally not treated as the same ownership definition.',
            'dashboard_only_leads' => Lead::with('project')->whereIn('id', $dashboardOnly)->orderByDesc('id')->get()->map(fn ($lead) => $this->leadRow($lead, $lead->updated_at ?? $lead->created_at, 'In Dashboard active assignment scope; not the current leads.agent_id pointer.'))->values(),
            'primary_only_leads' => Lead::with('project')->whereIn('id', $primaryOnly)->orderByDesc('id')->get()->map(fn ($lead) => $this->leadRow($lead, $lead->updated_at ?? $lead->created_at, 'In Agent Performance primary-pointer scope; not currently active in lead_agents for this agent.'))->values(),
        ];
    }


    /**
     * Return the actual CRM rows behind a metric when reconciliation finds a
     * difference. This keeps reconciliation traceable to individual records.
     */
    private function reconciliationMetricEvidence(int $agentId, string $metric, Carbon $from, Carbon $to): array
    {
        switch ($metric) {
            case 'new_leads':
                $rows = LeadAgent::with(['lead.project'])
                    ->where('agent_id', $agentId)->where('is_primary', true)
                    ->whereBetween('created_at', [$from, $to])
                    ->orderByDesc('created_at')->get()
                    ->unique('lead_id')->values();

                return [
                    'mode' => 'assignments',
                    'title' => 'Lead assignment history',
                    'columns' => ['Lead', 'Assigned', 'Current assignment', 'Reason'],
                    'rows' => $rows->map(fn ($r) => [
                        'lead' => $this->leadRow($r->lead, $r->created_at, 'Lead assignment'),
                        'at' => Carbon::parse($r->created_at),
                        'state' => $r->is_active ? 'Included' : 'Excluded',
                        'reason' => $r->is_active ? 'Primary assignment is active, so the report includes it.' : 'Primary assignment was later made inactive, so the report excludes it.' . ($r->lead?->agent_id === $agentId ? ' The lead still points to this agent as primary.' : ''),
                    ])->all(),
                ];

            case 'calls':
            case 'whatsapp':
            case 'external_share':
            case 'internal_share':
                $type = $metric === 'internal_share' ? 'lead_shared' : $metric;
                $events = Activity::with(['lead.project'])
                    ->where('agent_id', $agentId)->where('type', $type)
                    ->when($type === 'lead_shared', fn ($q) => $q->where('outcome', 'like', 'Shared with %'))
                    ->whereBetween('logged_at', [$from, $to])
                    ->orderByDesc('logged_at')->get();

                $seen = [];
                return [
                    'mode' => 'activities',
                    'title' => 'CRM activity history',
                    'columns' => ['Lead', 'When', 'CRM event', 'Report treatment'],
                    'rows' => $events->map(function ($r) use (&$seen) {
                        $leadId = (int) $r->lead_id;
                        $first = !isset($seen[$leadId]);
                        $seen[$leadId] = true;
                        return [
                            'lead' => $this->leadRow($r->lead, $r->logged_at, $r->displayLabel(140)),
                            'at' => Carbon::parse($r->logged_at),
                            'state' => $first ? 'Counted' : 'Repeat',
                            'reason' => $first ? 'First matching activity for this lead; counted once.' : 'Same lead already counted; repeated activity does not increase the distinct-lead report count.',
                        ];
                    })->all(),
                ];

            case 'tasks_completed':
                $tasks = Followup::with(['lead.project', 'sourceActivity'])
                    ->where('agent_id', $agentId)->where('status', 'done')
                    ->whereBetween('updated_at', [$from, $to])
                    ->orderByDesc('updated_at')->get();

                return [
                    'mode' => 'tasks',
                    'title' => 'Completed follow-up history',
                    'columns' => ['Lead', 'Completed', 'Task', 'CRM provenance'],
                    'rows' => $tasks->map(function ($task) {
                        $source = $task->sourceActivity;
                        $provenance = $task->auto_created ? 'Auto-created task' : 'Manually scheduled task';
                        $provenance .= $source ? ' · source activity #' . $source->id . ' (' . ($source->outcome_key ?: $source->type ?: 'activity') . ')' : ' · no source activity link';
                        return [
                            'lead' => $this->leadRow($task->lead, $task->updated_at, 'Completed: ' . ($task->action_type ?: 'task')),
                            'at' => Carbon::parse($task->updated_at),
                            'state' => $task->action_type ?: 'task',
                            'reason' => $provenance,
                        ];
                    })->all(),
                ];

            case 'bookings':
                $events = Activity::with(['lead.project'])
                    ->where('agent_id', $agentId)->where('type', 'status_change')->where('outcome_key', 'booking')
                    ->whereBetween('logged_at', [$from, $to])->orderByDesc('logged_at')->get();
                $seen = [];
                return [
                    'mode' => 'activities',
                    'title' => 'Booking status history',
                    'columns' => ['Lead', 'When', 'CRM event', 'Report treatment'],
                    'rows' => $events->map(function ($r) use (&$seen) {
                        $leadId = (int) $r->lead_id;
                        $first = !isset($seen[$leadId]);
                        $seen[$leadId] = true;
                        return [
                            'lead' => $this->leadRow($r->lead, $r->logged_at, 'Booking status recorded'),
                            'at' => Carbon::parse($r->logged_at),
                            'state' => $first ? 'Counted' : 'Repeat',
                            'reason' => $first ? 'First booking status event for this lead; counted once.' : 'Repeated booking status event on the same lead; report counts distinct booked leads.',
                        ];
                    })->all(),
                ];
        }

        return ['mode' => 'none', 'title' => 'CRM history', 'columns' => [], 'rows' => []];
    }

    private function leadRow($lead, $at, string $detail): object
    {
        return (object) [
            'lead_id' => (int) ($lead?->id ?? 0),
            'name' => $lead?->customer_name ?: ('Lead #' . ($lead?->id ?? '—')),
            'project' => $lead?->project?->name,
            'at' => $at instanceof Carbon ? $at : Carbon::parse($at),
            'detail' => $detail,
        ];
    }
}
