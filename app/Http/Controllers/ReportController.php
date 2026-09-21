<?php

namespace App\Http\Controllers;

use App\Models\Activity;
use App\Models\Agent;
use App\Models\Followup;
use App\Models\Lead;
use App\Models\LeadAgent;
use App\Models\Project;
use App\Services\DelegatedAccessService;
use App\Services\SettingsService;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class ReportController extends Controller
{
    public function __construct(
        private SettingsService $settings,
        private DelegatedAccessService $delegatedAccess
    ) {}

    /* ============================================================
       AUTHORIZATION
       ============================================================ */

    private function requireAdmin(): void
    {
        if (session('user_role') !== 'admin') {
            abort(403, 'Admin only.');
        }
    }

    /* ============================================================
       DELEGATED REPORT SCOPE
       ============================================================ */

    /**
     * Return the effective report scope for the current user.
     *
     * null = unrestricted Admin / Super Admin
     * array = restricted delegated scope
     */
    private function reportAgentIds(): ?array
    {
        $userId = (int) session('user_id');

        if (! $userId) {
            return [];
        }

        if (! $this->delegatedAccess->hasProfile($userId)) {
            return null;
        }

        return $this->delegatedAccess->visibleAgentIds($userId);
    }

    /**
     * Return accessible lead IDs for the current report user.
     *
     * IMPORTANT:
     * Uses lead_agents + is_active so shared leads are respected.
     *
     * null = unrestricted
     * []   = no accessible leads
     */
    private function reportLeadIds(?array $agentIds = null): ?array
    {
        if ($agentIds === null) {
            return null;
        }

        if ($agentIds === []) {
            return [];
        }

        return LeadAgent::query()
            ->whereIn('agent_id', $agentIds)
            ->where('is_active', true)
            ->pluck('lead_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Apply the current report lead scope to a Lead query.
     */
    private function scopeLeads($query, ?array $leadIds)
    {
        if ($leadIds === null) {
            return $query;
        }

        /*
         * Empty scope must return no rows.
         */
        if ($leadIds === []) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereIn('id', $leadIds);
    }

    /* ============================================================
       PAGINATION
       ============================================================ */

    private function paginateArray(
        array $rows,
        int $perPage,
        string $pageName,
        array $query = []
    ): LengthAwarePaginator {
        $page       = LengthAwarePaginator::resolveCurrentPage($pageName);
        $collection = collect($rows);

        return new LengthAwarePaginator(
            $collection->forPage($page, $perPage)->values(),
            $collection->count(),
            $perPage,
            $page,
            [
                'path'     => request()->url(),
                'pageName' => $pageName,
                'query'    => array_merge(request()->query(), $query),
            ]
        );
    }

    /* ============================================================
       SORTING
       ============================================================ */

    private function sortRows(
        array &$rows,
        array $allowedFields,
        string $defaultField
    ): void {
        $sort = request()->input('sort');
        $dir  = request()->input('dir', 'asc') === 'desc'
            ? 'desc'
            : 'asc';

        if (! $sort || ! in_array($sort, $allowedFields, true)) {
            $sort = $defaultField;
            $dir  = 'desc';
        }

        usort($rows, function ($a, $b) use ($sort, $dir) {

            $av = $a[$sort] ?? null;
            $bv = $b[$sort] ?? null;

            if (is_numeric($av) && is_numeric($bv)) {
                $cmp = (float) $av <=> (float) $bv;
            } else {
                $cmp = strcasecmp(
                    (string) $av,
                    (string) $bv
                );
            }

            return $dir === 'desc'
                ? -$cmp
                : $cmp;
        });
    }

    /* ============================================================
       REPORT HUB
       ============================================================ */

    public function index(Request $request)
    {
        $this->requireAdmin();

        $tab = $request->input('tab', 'conversion');

        $data = [
            'tab'      => $tab,
            'statuses' => $this->settings->statuses(),
        ];

        if ($tab === 'conversion') {

            $data['conversion'] = $this->conversionData();

        } elseif ($tab === 'sources') {

            $data['sources'] = $this->sourceData();

        } elseif ($tab === 'agents') {

            $data['agents'] = $this->paginateArray(
                $this->agentData(),
                50,
                'agents_page',
                ['tab' => 'agents']
            );

        } elseif ($tab === 'projects') {

            $data['projects'] = $this->paginateArray(
                $this->projectData(),
                50,
                'projects_page',
                ['tab' => 'projects']
            );

        } elseif ($tab === 'brokerage') {

            return redirect('/reports/brokerage');
        }

        return view('reports.index', $data);
    }

    /* ============================================================
       BROKERAGE
       ============================================================ */

    public function brokerage(Request $request)
    {
        $this->requireAdmin();

        $statusFilter = $request->input('brokerage_status');
        $agentFilter  = $request->input('agent_id');
        $fromFilter   = $request->input('from');
        $toFilter     = $request->input('to');

        $scopedAgentIds = $this->reportAgentIds();
        $scopedLeadIds  = $this->reportLeadIds($scopedAgentIds);

        /*
         * Base booking query.
         */
        $query = Lead::with([
                'agent.user',
                'project',
            ])
            ->where('status', 'booking')
            ->whereNotNull('booking_amount');

        /*
         * Delegated lead scope.
         */
        $query = $this->scopeLeads(
            $query,
            $scopedLeadIds
        );

        /*
         * Agent filter.
         *
         * A delegated Admin must not be able to submit:
         *
         * /reports/brokerage?agent_id=999
         *
         * and receive another agent's data.
         */
        if ($agentFilter) {

            $agentFilter = (int) $agentFilter;

            if (
                $scopedAgentIds !== null
                && ! in_array(
                    $agentFilter,
                    $scopedAgentIds,
                    true
                )
            ) {
                abort(403, 'You do not have access to this agent.');
            }

            $query->where('agent_id', $agentFilter);
        }

        if ($statusFilter) {
            $query->where(
                'brokerage_status',
                $statusFilter
            );
        }

        if ($fromFilter) {
            $query->whereDate(
                'booking_date',
                '>=',
                $fromFilter
            );
        }

        if ($toFilter) {
            $query->whereDate(
                'booking_date',
                '<=',
                $toFilter
            );
        }

        /* ---------- TOTALS ---------- */

        $totalsQuery = clone $query;

        $totals = [
            'count' => (clone $totalsQuery)->count(),

            'booking_value' =>
                (clone $totalsQuery)
                    ->sum('booking_amount'),

            'brokerage' =>
                (clone $totalsQuery)
                    ->sum('brokerage_amount'),

            'received' =>
                (clone $totalsQuery)
                    ->where(
                        'brokerage_status',
                        'received'
                    )
                    ->sum('brokerage_amount'),

            'pending' =>
                (clone $totalsQuery)
                    ->whereIn(
                        'brokerage_status',
                        ['pending', 'invoiced']
                    )
                    ->sum('brokerage_amount'),

            'disputed' =>
                (clone $totalsQuery)
                    ->where(
                        'brokerage_status',
                        'disputed'
                    )
                    ->sum('brokerage_amount'),
        ];

        /* ---------- PAGINATED BOOKINGS ---------- */

        $bookings = $query
            ->orderByDesc('booking_date')
            ->paginate(
                50,
                ['*'],
                'bookings_page'
            )
            ->appends([
                'brokerage_status' => $statusFilter,
                'agent_id'         => $agentFilter,
                'from'             => $fromFilter,
                'to'               => $toFilter,
            ]);

        /* ---------- AGENT FILTER OPTIONS ---------- */

        $agentsQuery = Agent::with('user')
            ->orderBy('id');

        if ($scopedAgentIds !== null) {
            $agentsQuery->whereIn(
                'id',
                $scopedAgentIds
            );
        }

        $agents = $agentsQuery->get();

        return view(
            'reports.brokerage',
            compact(
                'bookings',
                'totals',
                'agents',
                'statusFilter',
                'agentFilter',
                'fromFilter',
                'toFilter'
            )
        );
    }

    /* ============================================================
       CONVERSION FUNNEL
       ============================================================ */

    private function conversionData(): array
    {
        $scopedAgentIds = $this->reportAgentIds();
        $scopedLeadIds  = $this->reportLeadIds($scopedAgentIds);

        $pipeline = $this->settings->statuses()
            ->where('is_final', false)
            ->sortBy('sort_order')
            ->values();

        $currentCountsQuery = Lead::select(
                'status',
                DB::raw('count(*) as total')
            );

        $currentCountsQuery = $this->scopeLeads(
            $currentCountsQuery,
            $scopedLeadIds
        );

        $currentCounts = $currentCountsQuery
            ->groupBy('status')
            ->pluck('total', 'status')
            ->toArray();

        $reachedCounts = [];

        $running = 0;

        foreach ($pipeline->reverse() as $s) {

            $running +=
                $currentCounts[$s->key] ?? 0;

            $reachedCounts[$s->key] = $running;
        }

        $reachedCounts['booking'] =
            $currentCounts['booking'] ?? 0;

        $reachedCounts['lost'] =
            $currentCounts['lost'] ?? 0;

        /* ---------- TOTAL ---------- */

        $totalLeadsQuery = $this->scopeLeads(
            Lead::query(),
            $scopedLeadIds
        );

        $totalLeads = $totalLeadsQuery->count();

        /* ---------- STEPS ---------- */

        $steps = [];

        $steps[] = [
            'label' => '📋 All Leads',
            'key'   => 'all',
            'count' => $totalLeads,
            'pct'   => 100,
        ];

        $previous = $totalLeads;

        foreach ($pipeline as $s) {

            $count =
                $reachedCounts[$s->key] ?? 0;

            $pct = $totalLeads > 0
                ? round(
                    ($count / $totalLeads) * 100,
                    1
                )
                : 0;

            $drop = $previous - $count;

            $dropPct = $previous > 0
                ? round(
                    ($drop / $previous) * 100,
                    1
                )
                : 0;

            $steps[] = [
                'label'    => $s->label,
                'key'      => $s->key,
                'color'    => $s->color,
                'count'    => $count,
                'pct'      => $pct,
                'drop'     => $drop,
                'drop_pct' => $dropPct,
            ];

            $previous = $count;
        }

        $lostCount =
            $currentCounts['lost'] ?? 0;

        $bookedCount =
            $currentCounts['booking'] ?? 0;

        return [
            'steps' => $steps,

            'total_leads' =>
                $totalLeads,

            'lost_count' =>
                $lostCount,

            'booked_count' =>
                $bookedCount,

            'lost_pct' =>
                $totalLeads > 0
                    ? round(
                        ($lostCount / $totalLeads) * 100,
                        1
                    )
                    : 0,

            'booked_pct' =>
                $totalLeads > 0
                    ? round(
                        ($bookedCount / $totalLeads) * 100,
                        1
                    )
                    : 0,
        ];
    }

    /* ============================================================
       SOURCE ANALYSIS
       ============================================================ */

    private function sourceData(): array
    {
        $scopedAgentIds = $this->reportAgentIds();
        $scopedLeadIds  = $this->reportLeadIds($scopedAgentIds);

        $sources = $this->settings->sources(false);

        $rows = [];

        foreach ($sources as $src) {

            $leads = Lead::query()
                ->where('source', $src->key);

            $leads = $this->scopeLeads(
                $leads,
                $scopedLeadIds
            );

            $total = (clone $leads)->count();

            $booked = (clone $leads)
                ->where('status', 'booking')
                ->count();

            $lost = (clone $leads)
                ->where('status', 'lost')
                ->count();

            $active =
                $total
                - $booked
                - $lost;

            $bookedValue = (clone $leads)
                ->where('status', 'booking')
                ->sum('booking_amount');

            $brokerage = (clone $leads)
                ->where('status', 'booking')
                ->sum('brokerage_amount');

            $rows[] = [
                'key' =>
                    $src->key,

                'label' =>
                    $src->label,

                'total' =>
                    $total,

                'active' =>
                    $active,

                'booked' =>
                    $booked,

                'lost' =>
                    $lost,

                'conv_pct' =>
                    $total > 0
                        ? round(
                            ($booked / $total) * 100,
                            1
                        )
                        : 0,

                'lost_pct' =>
                    $total > 0
                        ? round(
                            ($lost / $total) * 100,
                            1
                        )
                        : 0,

                'booked_value' =>
                    (float) $bookedValue,

                'brokerage' =>
                    (float) $brokerage,
            ];
        }

        $this->sortRows(
            $rows,
            [
                'label',
                'total',
                'active',
                'booked',
                'lost',
                'conv_pct',
                'lost_pct',
                'booked_value',
                'brokerage',
            ],
            'total'
        );

        return $rows;
    }

    /* ============================================================
       AGENT PERFORMANCE
       ============================================================ */

    private function agentData(): array
    {
        $scopedAgentIds = $this->reportAgentIds();

        $agentsQuery = Agent::with('user')
            ->orderBy('id');

        /*
         * Delegated Admin sees only permitted agents.
         */
        if ($scopedAgentIds !== null) {

            if ($scopedAgentIds === []) {
                return [];
            }

            $agentsQuery->whereIn(
                'id',
                $scopedAgentIds
            );
        }

        $agents = $agentsQuery->get();

        $rows = [];

        foreach ($agents as $agent) {

            /*
             * Agent performance continues to use the lead's
             * primary agent assignment, which is how the existing
             * performance report was calculated.
             */
            $totalAssigned = Lead::query()
                ->where('agent_id', $agent->id)
                ->count();

            $activeLeads = Lead::query()
                ->where('agent_id', $agent->id)
                ->whereNotIn(
                    'status',
                    ['booking', 'lost']
                )
                ->count();

            $booked = Lead::query()
                ->where('agent_id', $agent->id)
                ->where('status', 'booking')
                ->count();

            $lost = Lead::query()
                ->where('agent_id', $agent->id)
                ->where('status', 'lost')
                ->count();

            $activities30 = Activity::query()
                ->where('agent_id', $agent->id)
                ->where(
                    'logged_at',
                    '>=',
                    now()->subDays(30)
                )
                ->count();

            $bookedValue = Lead::query()
                ->where('agent_id', $agent->id)
                ->where('status', 'booking')
                ->sum('booking_amount');

            $brokerage = Lead::query()
                ->where('agent_id', $agent->id)
                ->where('status', 'booking')
                ->sum('brokerage_amount');

            $pendingTasks = Followup::query()
                ->where('agent_id', $agent->id)
                ->where('status', 'pending')
                ->count();

            $overdueTasks = Followup::query()
                ->where('agent_id', $agent->id)
                ->where('status', 'pending')
                ->where(
                    'scheduled_for',
                    '<',
                    now()
                )
                ->count();

            $rows[] = [
                'agent' =>
                    $agent,

                'name' =>
                    $agent->user?->name
                    ?? ('Agent #' . $agent->id),

                'total_assigned' =>
                    $totalAssigned,

                'active_leads' =>
                    $activeLeads,

                'booked' =>
                    $booked,

                'lost' =>
                    $lost,

                'conv_pct' =>
                    $totalAssigned > 0
                        ? round(
                            ($booked / $totalAssigned) * 100,
                            1
                        )
                        : 0,

                'activities_30' =>
                    $activities30,

                'booked_value' =>
                    (float) $bookedValue,

                'brokerage' =>
                    (float) $brokerage,

                'pending_tasks' =>
                    $pendingTasks,

                'overdue_tasks' =>
                    $overdueTasks,

                'load' =>
                    $agent->current_load,

                'max_load' =>
                    $agent->max_daily_leads,
            ];
        }

        $this->sortRows(
            $rows,
            [
                'name',
                'load',
                'total_assigned',
                'active_leads',
                'booked',
                'lost',
                'conv_pct',
                'activities_30',
                'pending_tasks',
                'overdue_tasks',
                'booked_value',
                'brokerage',
            ],
            'booked'
        );

        return $rows;
    }

    /* ============================================================
       PROJECT PERFORMANCE
       ============================================================ */

    private function projectData(): array
    {
        $scopedAgentIds = $this->reportAgentIds();
        $scopedLeadIds  = $this->reportLeadIds($scopedAgentIds);

        /*
         * Normal Admin / Super Admin:
         * all projects represented in the projects table.
         */
        if ($scopedLeadIds === null) {

            $projects = Project::query()
                ->orderBy('name')
                ->get();

        } else {

            /*
             * Delegated:
             *
             * accessible agents
             *       ↓
             * active lead_agents
             *       ↓
             * accessible leads
             *       ↓
             * projects represented by those leads
             *
             * No project permission table is needed.
             */
            if ($scopedLeadIds === []) {
                return [];
            }

            $visibleProjectIds = Lead::query()
                ->whereIn(
                    'id',
                    $scopedLeadIds
                )
                ->whereNotNull('project_id')
                ->distinct()
                ->pluck('project_id')
                ->map(fn ($id) => (int) $id)
                ->values()
                ->all();

            if ($visibleProjectIds === []) {
                return [];
            }

            $projects = Project::query()
                ->whereIn(
                    'id',
                    $visibleProjectIds
                )
                ->orderBy('name')
                ->get();
        }

        $rows = [];

        foreach ($projects as $proj) {

            $leads = Lead::query()
                ->where(
                    'project_id',
                    $proj->id
                );

            /*
             * For delegated users the lead scope comes from
             * active lead_agents.
             */
            $leads = $this->scopeLeads(
                $leads,
                $scopedLeadIds
            );

            $total = (clone $leads)->count();

            $active = (clone $leads)
                ->whereNotIn(
                    'status',
                    ['booking', 'lost']
                )
                ->count();

            $booked = (clone $leads)
                ->where('status', 'booking')
                ->count();

            $lost = (clone $leads)
                ->where('status', 'lost')
                ->count();

            $bookedValue = (clone $leads)
                ->where('status', 'booking')
                ->sum('booking_amount');

            $brokerage = (clone $leads)
                ->where('status', 'booking')
                ->sum('brokerage_amount');

            $upcomingVisits = (clone $leads)
                ->whereNotNull('visit_scheduled_at')
                ->where(
                    'visit_scheduled_at',
                    '>',
                    now()
                )
                ->count();

            if ($total === 0) {
                continue;
            }

            $rows[] = [
                'project' =>
                    $proj,

                'name' =>
                    $proj->name,

                'location' =>
                    $proj->location,

                'total' =>
                    $total,

                'active' =>
                    $active,

                'booked' =>
                    $booked,

                'lost' =>
                    $lost,

                'conv_pct' =>
                    $total > 0
                        ? round(
                            ($booked / $total) * 100,
                            1
                        )
                        : 0,

                'booked_value' =>
                    (float) $bookedValue,

                'brokerage' =>
                    (float) $brokerage,

                'upcoming_visits' =>
                    $upcomingVisits,
            ];
        }

        $this->sortRows(
            $rows,
            [
                'name',
                'location',
                'total',
                'active',
                'booked',
                'lost',
                'conv_pct',
                'upcoming_visits',
                'booked_value',
                'brokerage',
            ],
            'total'
        );

        return $rows;
    }
}