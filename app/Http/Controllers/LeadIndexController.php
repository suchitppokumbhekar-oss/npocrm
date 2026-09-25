<?php

namespace App\Http\Controllers;

use App\Models\Agent;
use App\Models\Lead;
use App\Models\LeadAgent;
use App\Models\LeadLabel;
use App\Models\Project;
use App\Services\LeadTagService;
use App\Services\SettingsService;
use App\Services\TeamService;
use Carbon\Carbon;
use Illuminate\Http\Request;

class LeadIndexController extends Controller
{
    public function __construct(
        private SettingsService $settings,
        private \App\Services\AccessService $access,
    ) {}

    /* ============================================================
       ALL LEADS — role-scoped list with filters
       ============================================================ */
    public function index(Request $request)
    {
        if (! session('user_id')) {
            return redirect('/login');
        }

        $userId   = session('user_id');
        $userRole = session('user_role');
        $agentId  = Agent::where('user_id', $userId)->value('id');

                /* ============================================================
           SCOPE — which agents / leads can this user see?
           ============================================================ */
        $scopedAgentIds = null;   // null = unrestricted user
        $scopedLeadIds  = null;
        $requestedScope = $request->query("scope", "team");
        $workScope = $userRole === "team_manager"
            ? ($requestedScope === "delegated" ? "delegated" : "team")
            : null;

        /*
        |--------------------------------------------------------------------------
        | Delegated Admin
        |--------------------------------------------------------------------------
        | A delegated Admin must see ONLY leads belonging to the agents
        | permitted by the delegated-access profile.
        |
        | We deliberately use AccessService here rather than hard-coding
        | individual users/agents. This keeps the lead list synchronized
        | with the delegated-access configuration.
        */
        if ($userRole === 'admin' && $this->access->hasDelegatedProfile()) {
            $scopedAgentIds = $this->access->visibleAgentIds();

            if (empty($scopedAgentIds)) {
                $scopedAgentIds = [-1];
            }
        } elseif ($userRole === 'team_manager') {
            if ($workScope === 'delegated') {
                $scopedAgentIds = $this->access->visibleAgentIds();
            } else {
                $scopedAgentIds = app(\App\Services\TeamService::class)->agentIdsForManager($userId);
                $allowedAgentIds = $this->access->visibleAgentIds();
                $scopedAgentIds = array_values(array_intersect($scopedAgentIds, $allowedAgentIds));
            }

            if (empty($scopedAgentIds)) {
                $scopedAgentIds = [-1];
            }
        } elseif ($userRole === 'agent' && $agentId) {
            $scopedAgentIds = [$agentId];
        }

        /*
        |--------------------------------------------------------------------------
        | Convert agent scope into lead scope
        |--------------------------------------------------------------------------
        | Only ACTIVE lead-agent relationships count.
        */
        if ($scopedAgentIds !== null) {
            $scopedLeadIds = LeadAgent::whereIn('agent_id', $scopedAgentIds)
                ->where('is_active', true)
                ->pluck('lead_id')
                ->unique()
                ->values()
                ->all();

            if (empty($scopedLeadIds)) {
                $scopedLeadIds = [-1];
            }
        }

        /* ============================================================
           FILTERS
           ============================================================ */
        $statusFilter  = $request->input('status');
        $searchFilter  = trim((string) $request->input('search'));
        $agentFilter   = $request->input('agent_id');
        $projectFilter = $request->input('project');
        $tagFilter     = $request->input('tag_id');
        $labelFilter   = $request->input('label_id');
        $dateRange   = $request->input('date_range', 'all');
        $createdFrom = $request->input('date_from');
        $createdTo   = $request->input('date_to');
        [$createdFrom, $createdTo] = $this->resolveCreatedDateRange($dateRange, $createdFrom, $createdTo);
        $sort        = $request->input('sort', 'newest');
        $preset      = $request->input('preset');
        $viewMode    = $request->input('view');

        // My Leads is a lead-finding workspace. These three views are the
        // primary mobile entry points; task work stays on My Work / Tasks.
        if ($viewMode === 'working') {
            $preset = 'active';
        } elseif ($viewMode === 'booked') {
            $statusFilter = 'booking';
            $preset = null;
        } elseif ($viewMode === 'all') {
            $preset = null;
            $statusFilter = 'all';
        }

        $statuses = $this->settings->statuses();
        $terminalStatusKeys = $statuses->where('is_final', true)->pluck('key')->values()->all();

        /* ============================================================
           BASE QUERY
           ============================================================ */
        $query = Lead::with([
                'agent.user',
                'project',
                'activeAgents.user',
                'latestActivity.agent.user',
                'tag',
                'labels',
                'pendingFollowup',
            ])
            ->withCount('activities')
            ->orderByDesc('id');

        // Role scoping
        if ($scopedLeadIds !== null) {
            $query->whereIn('id', $scopedLeadIds);
        }

        /* ============================================================
           PRESET FILTERS — set by dashboard stat tiles
           Applied AFTER the base query is built
           ============================================================ */
        switch ($preset) {
            case 'new_today':
                $query->whereDate('created_at', now()->toDateString());
                break;
            case 'new_week':
                $query->where('created_at', '>=', now()->startOfWeek());
                break;
            case 'active':
                $query->whereNotIn('status', ['booking', 'lost']);
                break;
            case 'visits_scheduled':
                $query->where('status', 'visit_scheduled')
                      ->whereNotNull('visit_scheduled_at');
                break;
            case 'visits_done_actual':
                $query->whereHas('activities', function ($activityQuery) {
                    $activityQuery->where('type', 'site_visit')
                        ->whereIn('outcome_key', [
                            'visit_booked_spot', 'visit_interested', 'visit_needs_family',
                            'visit_wants_negotiate', 'visit_wants_other_project',
                            'visit_not_interested', 'visit_no_show', 'site_visit_with_family',
                            'site_visit_arrived_late', 'site_visit_cancelled', 'wants_second_visit',
                        ]);
                });
                break;
            case 'visits_done_actual_week':
                $query->whereHas('activities', function ($activityQuery) {
                    $activityQuery->where('type', 'site_visit')
                        ->whereIn('outcome_key', [
                            'visit_booked_spot', 'visit_interested', 'visit_needs_family',
                            'visit_wants_negotiate', 'visit_wants_other_project',
                            'visit_not_interested', 'visit_no_show', 'site_visit_with_family',
                            'site_visit_arrived_late', 'site_visit_cancelled', 'wants_second_visit',
                        ])
                        ->whereBetween('logged_at', [now()->startOfWeek(), now()]);
                });
                break;
            case 'visits_next_7d':
                $query->whereNotNull('visit_scheduled_at')
                      ->whereBetween('visit_scheduled_at', [now(), now()->addDays(7)]);
                break;
            case 'visits_today':
                $query->whereNotNull('visit_scheduled_at')
                      ->whereBetween('visit_scheduled_at', [now()->startOfDay(), now()->endOfDay()]);
                break;
            case 'bookings_month':
                $query->where('status', 'booking')
                      ->whereBetween('booking_date', [now()->startOfMonth(), now()->endOfMonth()]);
                break;
            // 'negotiation' and 'visit_done' use the existing ?status= param
            // 'booking' uses ?status=booking
        }

        // Default directory view is ACTIVE leads only. Closed/terminal leads are
        // shown when the user explicitly filters by a terminal status.
        if ($statusFilter && $statusFilter !== 'all') {
            $query->where('status', $statusFilter);
        } elseif ($statusFilter === 'all') {
            // Explicit All view: include terminal/closed leads too.
        } elseif (($preset === null || $preset === '') && ! $projectFilter) {
            if (! empty($terminalStatusKeys)) {
                $query->whereNotIn('status', $terminalStatusKeys);
            }
        }

        // Search
        if ($searchFilter !== '') {
            $query->where(function ($q) use ($searchFilter) {
                $q->where('customer_name', 'like', "%{$searchFilter}%")
                  ->orWhere('phone',          'like', "%{$searchFilter}%")
                  ->orWhere('email',          'like', "%{$searchFilter}%");
            });
        }

        // Agent filter — only admins and team managers can use it
        if ($agentFilter && in_array($userRole, ['admin', 'team_manager'], true)) {
            if ($scopedAgentIds === null || in_array((int) $agentFilter, $scopedAgentIds, true)) {
                $query->where('agent_id', (int) $agentFilter);
            }
        }

        // Project filter
        if ($projectFilter) {
            $query->where('project_id', (int) $projectFilter);
        }

        // Tag filter (single-select)
        if ($tagFilter) {
            $query->where('tag_id', (int) $tagFilter);
        }

        // Label filter (single-select)
        if ($labelFilter) {
            $query->whereHas('labels', function ($q) use ($labelFilter) {
                $q->where('lead_labels.id', (int) $labelFilter);
            });
        }

        if ($createdFrom) $query->whereDate('created_at', '>=', $createdFrom);
        if ($createdTo)   $query->whereDate('created_at', '<=', $createdTo);

        $sortMap = [
            'newest'  => ['created_at', 'desc'],
            'oldest'  => ['created_at', 'asc'],
            'updated' => ['updated_at', 'desc'],
            'active'  => ['last_activity_at', 'desc'],
            'visit'   => ['visit_scheduled_at', 'asc'],
            'booking' => ['booking_date', 'desc'],
        ];
        [$sortColumn, $sortDirection] = $sortMap[$sort] ?? $sortMap['newest'];
        $query->reorder($sortColumn, $sortDirection)->orderByDesc('id');

        /* ============================================================
           PAGINATE
           ============================================================ */
        $leads = $query->paginate(50, ['*'], 'leads_page')
            ->appends($request->query());

        /* ============================================================
           FILTER DROPDOWN DATA
           ============================================================ */
        // Agent dropdown — for admin/manager only
        $agents = collect();
        if (in_array($userRole, ['admin', 'team_manager'], true)) {
            $agentsQuery = Agent::with('user')
                ->where('status', 'active')
                ->orderBy('id');

            if ($scopedAgentIds !== null) {
                $agentsQuery->whereIn('id', $scopedAgentIds);
            }

            $agents = $agentsQuery->get();
        }

        // Projects list — only needed when a project filter is active (for the banner)
        $projects = collect();
        if ($projectFilter) {
            $projectsQuery = Project::where('id', (int) $projectFilter);

            if ($scopedLeadIds !== null) {
                $projectsQuery->whereHas('leads', function ($leadQuery) use ($scopedLeadIds) {
                    $leadQuery->whereIn('id', $scopedLeadIds);
                });
            }

            $projects = $projectsQuery->get(['id', 'name']);
        }

        // Tags + labels for filter dropdowns
        $tags       = app(LeadTagService::class)->tags();
        $labelsFlat = LeadLabel::active()->ordered()->get();

        return view('leads.index', compact(
            'leads',
            'statuses',
            'agents',
            'projects',
            'statusFilter',
            'searchFilter',
            'projectFilter',
            'dateRange', 'createdFrom', 'createdTo', 'sort',
            'tags',
            'labelsFlat',
            'workScope'
        ));
    }

    /** Resolve the simple Leads date filter against lead creation date. */
    private function resolveCreatedDateRange(?string $range, ?string $from, ?string $to): array
    {
        $range = in_array($range, ['all', 'today', '3d', '7d', '30d', '1y', 'custom'], true) ? $range : 'all';

        if ($range === 'custom') {
            $from = $from && strtotime($from) ? Carbon::parse($from)->toDateString() : null;
            $to   = $to && strtotime($to) ? Carbon::parse($to)->toDateString() : null;
            if ($from && $to && $from > $to) [$from, $to] = [$to, $from];
            return [$from, $to];
        }
        if ($range === 'all') return [null, null];

        $today = now();
        return match ($range) {
            'today' => [$today->toDateString(), $today->toDateString()],
            '3d'    => [$today->copy()->subDays(2)->toDateString(), $today->toDateString()],
            '7d'    => [$today->copy()->subDays(6)->toDateString(), $today->toDateString()],
            '30d'   => [$today->copy()->subDays(29)->toDateString(), $today->toDateString()],
            '1y'    => [$today->copy()->subYear()->toDateString(), $today->toDateString()],
            default => [null, null],
        };
    }

    /** Admin-only bulk Lost reason classification correction. */
    public function bulkUpdateLostReason(Request $request)
    {
        if (! $this->access->can('leads.status_change')) {
            return back()->with('error', '🚫 Only admins can bulk edit Lost reasons.');
        }

        $validated = $request->validate([
            'lead_ids'            => 'required|string',
            'lost_reason_key'    => 'required|string|max:80',
        ]);

        $ids = collect(explode(',', $validated['lead_ids']))
            ->map(fn ($id) => (int) trim($id))
            ->filter(fn ($id) => $id > 0)
            ->unique()
            ->values()
            ->all();

        if (! $ids) {
            return back()->with('error', '🚫 No leads were selected.');
        }
        if (count($ids) > 100) {
            return back()->with('error', '🚫 Please edit a maximum of 100 leads at a time.');
        }

        if (! $this->access->isUnrestrictedAdmin()) {
            $accessibleLeadIds = $this->accessibleLeadIds();
            $ids = array_values(array_filter($ids, fn ($id) => in_array((int) $id, $accessibleLeadIds, true)));
            if (empty($ids)) {
                return back()->with('error', '🚫 None of the selected leads are within your permitted lead scope.');
            }
        }

        try {
            $changed = app(\App\Services\LeadStatusService::class)
                ->updateLostReasonBulk($ids, $validated['lost_reason_key']);
        } catch (\DomainException $e) {
            return back()->with('error', '🚫 ' . $e->getMessage());
        }

        return back()->with('success', "✅ Lost reason updated for {$changed} lead" . ($changed === 1 ? '' : 's') . '.');
    }

    /* ============================================================
       MY LEADS — only the leads assigned to the current user
       ============================================================ */
    public function mine(Request $request)
    {
        if (! session('user_id')) return redirect('/login');

        $userId = session('user_id');
        $agent  = Agent::where('user_id', $userId)->first();

        if (! $agent) {
            return redirect('/')->with('error', 'You do not have an agent record.');
        }

        $agentId = (int) $agent->id;

        /* ---------- FILTERS ---------- */
        $statusFilter  = $request->input('status');
        $searchFilter  = trim((string) $request->input('search'));
        $projectFilter = $request->input('project');
        $tagFilter     = $request->input('tag_id');
        $labelFilter   = $request->input('label_id');
        $dateRange   = $request->input('date_range', 'all');
        $createdFrom = $request->input('date_from');
        $createdTo   = $request->input('date_to');
        [$createdFrom, $createdTo] = $this->resolveCreatedDateRange($dateRange, $createdFrom, $createdTo);
        $sort        = $request->input('sort', 'newest');
        $preset      = $request->input('preset');
        $viewMode    = $request->input('view');

        // My Leads is a lead-finding workspace. These three views are the
        // primary mobile entry points; task work stays on My Work / Tasks.
        if ($viewMode === 'working') {
            $preset = 'active';
        } elseif ($viewMode === 'booked') {
            $statusFilter = 'booking';
            $preset = null;
        } elseif ($viewMode === 'all') {
            $preset = null;
            $statusFilter = 'all';
        }

        /* ---------- LEAD IDS for this agent (primary + shared) ---------- */
        $leadIds = LeadAgent::where('agent_id', $agentId)
            ->where('is_active', true)
            ->pluck('lead_id')
            ->unique()
            ->values()
            ->all();

        if (empty($leadIds)) {
            $leadIds = [-1];
        }

        $statuses = $this->settings->statuses();
        $terminalStatusKeys = $statuses->where('is_final', true)->pluck('key')->values()->all();

        /* ---------- QUERY ---------- */
        $query = Lead::with([
                'agent.user',
                'project',
                'activeAgents.user',
                'latestActivity.agent.user',
                'tag',
                'labels',
                'pendingFollowup',
            ])
            ->withCount('activities')
            ->whereIn('id', $leadIds)
            ->orderByDesc('id');

        /* ---------- PRESET FILTERS ---------- */
        switch ($preset) {
            case 'new_today':
                $query->whereDate('created_at', now()->toDateString());
                break;
            case 'new_week':
                $query->where('created_at', '>=', now()->startOfWeek());
                break;
            case 'active':
                $query->whereNotIn('status', ['booking', 'lost']);
                break;
            case 'visits_scheduled':
                $query->where('status', 'visit_scheduled')
                      ->whereNotNull('visit_scheduled_at');
                break;
            case 'visits_done_actual':
                $query->whereHas('activities', function ($activityQuery) {
                    $activityQuery->where('type', 'site_visit')
                        ->whereIn('outcome_key', [
                            'visit_booked_spot', 'visit_interested', 'visit_needs_family',
                            'visit_wants_negotiate', 'visit_wants_other_project',
                            'visit_not_interested', 'visit_no_show', 'site_visit_with_family',
                            'site_visit_arrived_late', 'site_visit_cancelled', 'wants_second_visit',
                        ]);
                });
                break;
            case 'visits_done_actual_week':
                $query->whereHas('activities', function ($activityQuery) {
                    $activityQuery->where('type', 'site_visit')
                        ->whereIn('outcome_key', [
                            'visit_booked_spot', 'visit_interested', 'visit_needs_family',
                            'visit_wants_negotiate', 'visit_wants_other_project',
                            'visit_not_interested', 'visit_no_show', 'site_visit_with_family',
                            'site_visit_arrived_late', 'site_visit_cancelled', 'wants_second_visit',
                        ])
                        ->whereBetween('logged_at', [now()->startOfWeek(), now()]);
                });
                break;
            case 'visits_next_7d':
                $query->whereNotNull('visit_scheduled_at')
                      ->whereBetween('visit_scheduled_at', [now(), now()->addDays(7)]);
                break;
            case 'visits_today':
                $query->whereNotNull('visit_scheduled_at')
                      ->whereBetween('visit_scheduled_at', [now()->startOfDay(), now()->endOfDay()]);
                break;
            case 'bookings_month':
                $query->where('status', 'booking')
                      ->whereBetween('booking_date', [now()->startOfMonth(), now()->endOfMonth()]);
                break;
        }

        if ($statusFilter && $statusFilter !== 'all') {
            $query->where('status', $statusFilter);
        } elseif ($statusFilter === 'all') {
            // Explicit All view: include terminal/closed leads too.
        } elseif (($preset === null || $preset === '') && ! $projectFilter) {
            if (! empty($terminalStatusKeys)) {
                $query->whereNotIn('status', $terminalStatusKeys);
            }
        }

        if ($searchFilter !== '') {
            $query->where(function ($q) use ($searchFilter) {
                $q->where('customer_name', 'like', "%{$searchFilter}%")
                  ->orWhere('phone',          'like', "%{$searchFilter}%")
                  ->orWhere('email',          'like', "%{$searchFilter}%");
            });
        }

        if ($projectFilter) {
            $query->where('project_id', (int) $projectFilter);
        }

        if ($tagFilter) {
            $query->where('tag_id', (int) $tagFilter);
        }

        if ($labelFilter) {
            $query->whereHas('labels', function ($q) use ($labelFilter) {
                $q->where('lead_labels.id', (int) $labelFilter);
            });
        }

        if ($createdFrom) $query->whereDate('created_at', '>=', $createdFrom);
        if ($createdTo)   $query->whereDate('created_at', '<=', $createdTo);

        $sortMap = [
            'newest'  => ['created_at', 'desc'],
            'oldest'  => ['created_at', 'asc'],
            'updated' => ['updated_at', 'desc'],
            'active'  => ['last_activity_at', 'desc'],
            'visit'   => ['visit_scheduled_at', 'asc'],
            'booking' => ['booking_date', 'desc'],
        ];
        [$sortColumn, $sortDirection] = $sortMap[$sort] ?? $sortMap['newest'];
        $query->reorder($sortColumn, $sortDirection)->orderByDesc('id');

        $leads    = $query->paginate(50, ['*'], 'leads_page')->appends($request->query());

        /* ---------- STATS ---------- */
        $stats = [
            'total'  => count($leadIds) === 1 && $leadIds[0] === -1 ? 0 : count($leadIds),
            'active' => Lead::whereIn('id', $leadIds)
                ->whereNotIn('status', ['booking', 'lost'])
                ->count(),
            'booked' => Lead::whereIn('id', $leadIds)
                ->where('status', 'booking')
                ->count(),
            'overdue_tasks' => \App\Models\Followup::where('agent_id', $agentId)
                ->where('status', 'pending')
                ->whereIn('lead_id', $leadIds)
                ->where('scheduled_for', '<', now())
                ->count(),
            'pending_tasks' => \App\Models\Followup::where('agent_id', $agentId)
                ->where('status', 'pending')
                ->whereIn('lead_id', $leadIds)
                ->count(),
        ];

        // Projects list — for the active project banner
        $projects = collect();
        if ($projectFilter) {
            $projects = Project::where('id', (int) $projectFilter)
                ->whereHas('leads', function ($leadQuery) use ($leadIds) {
                    $leadQuery->whereIn('id', $leadIds);
                })
                ->get(['id', 'name']);
        }

        // Tags + labels for filter dropdowns
        $tags       = app(LeadTagService::class)->tags();
        $labelsFlat = LeadLabel::active()->ordered()->get();

        return view('leads.mine', compact(
            'leads', 'statuses', 'projects',
            'statusFilter', 'searchFilter', 'projectFilter', 'dateRange', 'createdFrom', 'createdTo', 'sort',
            'stats', 'agent', 'viewMode',
            'tags', 'labelsFlat'
        ));
    }
}