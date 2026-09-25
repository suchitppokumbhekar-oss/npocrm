<?php

namespace App\Http\Controllers;

use App\Services\NudgeService;
use App\Models\Agent;
use App\Models\Followup;
use App\Models\Lead;
use App\Models\LeadAgent;
use App\Models\Project;
use App\Models\User;
use App\Services\DelegatedAccessService;
use App\Services\SettingsService;
use App\Services\TeamService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class DashboardController extends Controller
{
    public function __construct(
        private SettingsService $settings,
        private DelegatedAccessService $delegatedAccess
    ) {}

    public function index(Request $request)
    {
        if (! session('user_id')) {
            return redirect('/login');
        }

        $userId   = (int) session('user_id');
        $userRole = session('user_role');
        $userName = session('user_name');

        $agentRecord = Agent::where('user_id', $userId)->first();
        $agentId     = $agentRecord?->id;

        /* ============================================================
           SCOPE
           ============================================================ */

        $scopedAgentIds = null;
        $scopedLeadIds  = null;
        $requestedScope = $request->input("scope", "team");
        $workScope = $userRole === "team_manager"
            ? ($requestedScope === "delegated" ? "delegated" : "team")
            : null;

        /*
         * Delegated Admin / Team Manager
         *
         * Delegated scope takes priority over the broad role.
         *
         * visibleAgentIds() includes:
         * - delegated team members
         * - explicitly included users
         * - delegated user's own agent
         * - minus explicitly excluded users
         */
        if ($this->delegatedAccess->hasProfile($userId) && ! ($userRole === "team_manager" && $workScope === "team")) {

            $scopedAgentIds = $this->delegatedAccess->visibleAgentIds($userId);

            if (empty($scopedAgentIds)) {
                $scopedAgentIds = [-1];
            }

        } elseif ($userRole === 'team_manager') {

            $scopedAgentIds = app(TeamService::class)
                ->agentIdsForManager($userId);

            if ($this->delegatedAccess->hasProfile($userId)) {
                $allowedAgentIds = $this->delegatedAccess->visibleAgentIds($userId);
                $scopedAgentIds = array_values(array_intersect($scopedAgentIds, $allowedAgentIds));
            }

            if (empty($scopedAgentIds)) {
                $scopedAgentIds = [-1];
            }

        } elseif ($userRole === 'agent' && $agentId) {

            $scopedAgentIds = [$agentId];
        }

        /*
         * Lead visibility uses lead_agents rather than only
         * leads.agent_id.
         *
         * This is important because your CRM supports shared leads.
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
           PROJECTS + SOURCES
           ============================================================ */

        /*
         * Normal unrestricted Admin:
         * preserve existing project visibility behaviour.
         *
         * Delegated user:
         * projects are derived ONLY from accessible leads.
         *
         * There is intentionally no project permission table or
         * project checkbox system.
         */
        if ($this->delegatedAccess->hasProfile($userId)) {

            $visibleProjectIds = $scopedLeadIds === null
                ? []
                : Lead::whereIn('id', $scopedLeadIds)
                    ->whereNotNull('project_id')
                    ->distinct()
                    ->pluck('project_id')
                    ->map(fn ($id) => (int) $id)
                    ->values()
                    ->all();

            $projects = empty($visibleProjectIds)
                ? collect()
                : Project::query()
                    ->whereIn('id', $visibleProjectIds)
                    ->orderBy('name')
                    ->get();

        } else {

            $projects = Project::query()
                ->visibleTo($userId, $userRole)
                ->orderBy('name')
                ->get();
        }

        $sources = $this->settings->sources();

        /* ============================================================
           LEADS
           ============================================================ */

        $leadsQuery = Lead::with([
                'agent.user',
                'project',
                'activeAgents.user',
                'tag',
                'labels',
            ])
            ->orderByDesc('id');

        if ($scopedLeadIds !== null) {
            $leadsQuery->whereIn('id', $scopedLeadIds);
        }

        $leads = $leadsQuery->get();

        // Personal untouched work: Agents and Team Managers with an Agent identity.
        // Scope directly through active lead_agents assignments, never manager team scope.
        $personalUntouchedLeadIds = $agentId
            ? LeadAgent::where('agent_id', $agentId)
                ->where('is_active', true)
                ->pluck('lead_id')
            : collect();

        $untouchedLeads = $agentId
            ? Lead::with(['agent.user', 'project', 'followups' => fn ($q) => $q->where('status', 'pending')->orderBy('scheduled_for')->orderBy('id')])
                ->whereIn('id', $personalUntouchedLeadIds)
                ->where('status', 'new')
                ->orderBy('created_at')
                ->orderBy('id')
                ->get()
            : collect();

        $untouchedLeadCount = $untouchedLeads->count();

        // Team Manager new-lead scope.
        // Personal work stays in $untouchedLeads. Team/delegated views exclude self.
        $managerScopeAgents = collect();
        $managerScopeUntouchedLeads = collect();
        $selectedManagerAgentId = null;

        if ($userRole === 'team_manager') {
            $managerScopeAgentIds = collect($scopedAgentIds ?? [])
                ->map(fn ($id) => (int) $id)
                ->filter(fn ($id) => $id > 0 && $id !== (int) $agentId)
                ->unique()
                ->values();

            // "All Delegated" means only additional delegated agents:
            // exclude self and everyone already belonging to My Team.
            if ($workScope === 'delegated') {
                $directTeamAgentIds = collect(
                    app(TeamService::class)->agentIdsForManager($userId)
                )
                    ->map(fn ($id) => (int) $id)
                    ->filter(fn ($id) => $id > 0)
                    ->unique();

                $managerScopeAgentIds = $managerScopeAgentIds
                    ->reject(fn ($id) => $directTeamAgentIds->contains((int) $id))
                    ->values();
            }

            $requestedManagerAgentId = (int) $request->input('agent_id', 0);

            if ($requestedManagerAgentId > 0 && $managerScopeAgentIds->contains($requestedManagerAgentId)) {
                $selectedManagerAgentId = $requestedManagerAgentId;
            }

            $agentsWithUntouchedIds = LeadAgent::whereIn('agent_id', $managerScopeAgentIds)
                ->where('is_active', true)
                ->whereHas('lead', fn ($lead) => $lead->where('status', 'new'))
                ->pluck('agent_id')
                ->unique()
                ->values();

            $managerScopeAgents = Agent::with('user')
                ->whereIn('id', $agentsWithUntouchedIds)
                ->get()
                ->map(function ($agent) {
                    $agent->untouched_count = LeadAgent::where('agent_id', $agent->id)
                        ->where('is_active', true)
                        ->whereHas('lead', fn ($lead) => $lead->where('status', 'new'))
                        ->count();

                    return $agent;
                })
                ->sortBy(fn ($agent) => strtolower((string) ($agent->user?->name ?? '')))
                ->values();

            $managerScopeLeadIds = LeadAgent::whereIn(
                    'agent_id',
                    $selectedManagerAgentId ? [$selectedManagerAgentId] : $managerScopeAgentIds
                )
                ->where('is_active', true)
                ->pluck('lead_id')
                ->unique()
                ->values();

            $managerScopeUntouchedLeads = Lead::with([
                    'agent.user',
                    'project',
                    'activeAgents.user',
                    'followups' => fn ($q) => $q->where('status', 'pending')->orderBy('scheduled_for')->orderBy('id'),
                ])
                ->whereIn('id', $managerScopeLeadIds)
                ->where('status', 'new')
                ->orderBy('created_at')
                ->orderBy('id')
                ->get();
        }
        /* ============================================================
           PENDING FOLLOWUPS
           ============================================================ */

        $followupsQuery = Followup::with([
                'lead.project',
                'agent.user',
            ])
            ->where('status', 'pending')
            ->orderBy('scheduled_for', 'asc');

        if (
            $userRole === 'team_manager'
            && in_array($workScope, ['team', 'delegated'], true)
            && $selectedManagerAgentId
        ) {
            $followupsQuery->where('agent_id', $selectedManagerAgentId);
        } elseif ($scopedAgentIds !== null) {
            $followupsQuery->whereIn('agent_id', $scopedAgentIds);
        }

        $allPendingFollowups = $followupsQuery->get();

        /*
         * Future reactivation reminders are nurture, not current work.
         */
        $reactivationTasks = $allPendingFollowups
            ->filter(fn ($t) =>
                $t->action_type === 'reactivation_call'
                && $t->scheduled_for
                && $t->scheduled_for->isFuture()
            )
            ->values();

        $pendingFollowups = $allPendingFollowups
            ->reject(fn ($t) =>
                $reactivationTasks->contains('id', $t->id)
            )
            ->values();

        /* ============================================================
           PERSONAL WORK QUEUE
           ============================================================

           Management dashboards intentionally use the team-scoped
           $pendingFollowups collection above. My Work is different: it is
           always the signed-in user's own operational queue. This matters
           especially for Team Managers who also handle leads themselves.
        */
        $personalPendingFollowups = $agentId
            ? Followup::with(['lead.project', 'agent.user'])
                ->where('status', 'pending')
                ->where('agent_id', $agentId)
                ->orderBy('scheduled_for', 'asc')
                ->get()
                ->filter(fn ($t) => $t->lead && app(\App\Services\AccessService::class)->canWorkLead($t->lead))
                ->values()
            : collect();

        $personalOverdueCount = $personalPendingFollowups->filter(fn ($t) =>
            $t->scheduled_for && $t->scheduled_for->isPast()
        )->count();
        $personalTodayCount = $personalPendingFollowups->filter(fn ($t) =>
            $t->scheduled_for && $t->scheduled_for->isToday()
        )->count();

        /* ============================================================
           PRIORITY BUCKETS
           ============================================================ */

        $endOfToday      = now()->endOfDay();
        $endOf2h         = now()->addHours(2);
        $startOfTomorrow = now()->addDay()->startOfDay();
        $endOfTomorrow   = now()->addDay()->endOfDay();

        $overdueTasks = $pendingFollowups->filter(
            fn ($t) =>
                $t->scheduled_for
                && $t->scheduled_for->isPast()
        )->values();

        $dueSoonTasks = $pendingFollowups->filter(
            fn ($t) =>
                $t->scheduled_for
                && $t->scheduled_for->isFuture()
                && $t->scheduled_for->lessThanOrEqualTo($endOf2h)
        )->values();

        $restOfTodayTasks = $pendingFollowups->filter(
            fn ($t) =>
                $t->scheduled_for
                && $t->scheduled_for->greaterThan($endOf2h)
                && $t->scheduled_for->lessThanOrEqualTo($endOfToday)
        )->values();

        $tomorrowTasks = $pendingFollowups->filter(
            fn ($t) =>
                $t->scheduled_for
                && $t->scheduled_for->greaterThanOrEqualTo($startOfTomorrow)
                && $t->scheduled_for->lessThanOrEqualTo($endOfTomorrow)
        )->values();

        /* ============================================================
           TEAM MANAGER ATTENTION SUMMARY

           Dashboard-only management scope. Authorization remains based
           on the normal scopedAgentIds above, but the manager's own work
           is excluded here because it belongs in MY WORK.
           ============================================================ */

        $managerAttentionSummary = collect();

        if (
            $userRole === 'team_manager'
            && in_array($workScope, ['team', 'delegated'], true)
        ) {
            $managerAttentionAgentIds = collect($scopedAgentIds ?? [])
                ->map(fn ($id) => (int) $id)
                ->filter(fn ($id) => $id > 0 && $id !== (int) $agentId)
                ->unique()
                ->values();

            // Delegated means additional staff only, not direct-team members.
            if ($workScope === 'delegated') {
                $directTeamAgentIds = collect(
                    app(TeamService::class)->agentIdsForManager($userId)
                )
                    ->map(fn ($id) => (int) $id)
                    ->filter(fn ($id) => $id > 0)
                    ->unique();

                $managerAttentionAgentIds = $managerAttentionAgentIds
                    ->reject(fn ($id) => $directTeamAgentIds->contains((int) $id))
                    ->values();
            }

            $managerAttentionTasks = $pendingFollowups
                ->filter(fn ($t) =>
                    $managerAttentionAgentIds->contains((int) $t->agent_id)
                )
                ->values();

            $managerAttentionAgents = Agent::with('user')
                ->whereIn('id', $managerAttentionAgentIds)
                ->get()
                ->keyBy('id');

            $managerNewCounts = LeadAgent::whereIn(
                    'agent_id',
                    $managerAttentionAgentIds
                )
                ->where('is_active', true)
                ->whereHas('lead', fn ($lead) => $lead->where('status', 'new'))
                ->selectRaw('agent_id, COUNT(*) as total')
                ->groupBy('agent_id')
                ->pluck('total', 'agent_id');

            $managerAttentionSummary = $managerAttentionAgentIds
                ->map(function ($aId) use (
                    $managerAttentionTasks,
                    $managerAttentionAgents,
                    $managerNewCounts
                ) {
                    $agent = $managerAttentionAgents->get($aId);

                    $items = $managerAttentionTasks
                        ->where('agent_id', $aId)
                        ->values();

                    $overdue = $items->filter(fn ($t) =>
                        $t->scheduled_for
                        && $t->scheduled_for->isPast()
                    );

                    $today = $items->filter(fn ($t) =>
                        $t->scheduled_for
                        && $t->scheduled_for->isToday()
                        && $t->scheduled_for->isFuture()
                    );

                    $dueSoon = $items->filter(fn ($t) =>
                        $t->scheduled_for
                        && $t->scheduled_for->isFuture()
                        && $t->scheduled_for->lessThanOrEqualTo(now()->addHours(2))
                    );

                    $escalated = $items->filter(
                        fn ($t) => $t->escalated_flag
                    );

                    return (object) [
                        'agent_id'      => (int) $aId,
                        'name'          => $agent?->user?->name ?? 'Unassigned',
                        'phone'         => $agent?->phone,
                        'overdue'       => $overdue->count(),
                        'today'         => $today->count(),
                        'due_soon'      => $dueSoon->count(),
                        'new'           => (int) ($managerNewCounts[$aId] ?? 0),
                        'escalated'     => $escalated->count(),
                        'total'         => $items->count(),
                        'overdue_items' => $overdue
                            ->sortBy(fn ($t) => $t->scheduled_for?->timestamp ?? PHP_INT_MAX)
                            ->values(),
                        'today_items'   => $today
                            ->sortBy(fn ($t) => $t->scheduled_for?->timestamp ?? PHP_INT_MAX)
                            ->values(),
                        'nudge_count'   => app(NudgeService::class)
                            ->countForAgent((int) $aId),
                    ];
                })
                ->filter(fn ($a) =>
                    $a->overdue > 0
                    || $a->today > 0
                    || $a->new > 0
                    || $a->escalated > 0
                )
                ->sortByDesc(fn ($a) =>
                    ($a->overdue * 1000000)
                    + ($a->escalated * 10000)
                    + ($a->today * 100)
                    + $a->new
                )
                ->values();
        }

        /* ============================================================
           AGENT-WISE OVERDUE SUMMARY
           ============================================================ */

        $agentOverdueSummary = collect();

        if (in_array($userRole, ['admin', 'team_manager'], true)) {

            $agentOverdueSummary = $pendingFollowups
                ->groupBy('agent_id')
                ->map(function ($items, $aId) {

                    $agent     = $items->first()->agent;
                    $overdue   = $items->filter(
                        fn ($t) =>
                            $t->scheduled_for
                            && $t->scheduled_for->isPast()
                    );

                    $dueSoon = $items->filter(
                        fn ($t) =>
                            $t->scheduled_for
                            && $t->scheduled_for->isFuture()
                            && $t->scheduled_for->lessThanOrEqualTo(
                                now()->addHours(2)
                            )
                    );

                    $escalated = $items->filter(
                        fn ($t) => $t->escalated_flag
                    );

                    return (object) [
                        'agent_id'      => $aId,
                        'name'          => $agent?->user?->name ?? 'Unassigned',
                        'phone'         => $agent?->phone,
                        'overdue'       => $overdue->count(),
                        'due_soon'      => $dueSoon->count(),
                        'escalated'     => $escalated->count(),
                        'total'         => $items->count(),
                        'overdue_items' => $overdue->values(),
                        'nudge_url'     => null,
                        'nudge_phone'   => null,
                        'nudge_message' => null,
                        'nudge_count'   => app(NudgeService::class)->countForAgent((int) $aId),
                    ];
                })
                ->filter(
                    fn ($a) =>
                        $a->overdue > 0
                        || $a->due_soon > 0
                        || $a->escalated > 0
                )
                ->sortByDesc(
                    fn ($a) =>
                        $a->overdue * 1000
                        + $a->escalated * 100
                        + $a->due_soon * 10
                )
                ->values();

            /*
             * Build WhatsApp message data.
             */
            $agentOverdueSummary->each(function ($summary) {

                if ($summary->overdue < 1 || ! $summary->phone) {
                    return;
                }

                $first = explode(' ', trim($summary->name))[0];

                $lines = [];

                $lines[] = "Hi {$first},";
                $lines[] = '';

                $lines[] =
                    "You have {$summary->overdue} overdue follow-up"
                    . ($summary->overdue === 1 ? '' : 's')
                    . ":";

                foreach ($summary->overdue_items->take(10) as $t) {

                    $leadName = $t->lead?->customer_name ?? 'Lead';
                    $project  = $t->lead?->project?->name;

                    $overdueBy =
                        $t->scheduled_for->diffForHumans(null, true)
                        . ' ago';

                    $line = "• {$leadName}";

                    if ($project) {
                        $line .= " ({$project})";
                    }

                    $line .= " — {$overdueBy}";

                    if ($t->lead_id) {
                        $line .= "\n  🔗 "
                            . url('/leads/' . $t->lead_id);
                    }

                    $lines[] = $line;
                }

                if ($summary->overdue > 10) {
                    $lines[] =
                        '… and '
                        . ($summary->overdue - 10)
                        . ' more.';
                }

                $lines[] = '';
                $lines[] = 'Please attend today.';
                $lines[] = '';
                $lines[] = '— ' . session('user_name', 'Manager');

                $msg = implode("\n", $lines);

                $wa = phone_wa($summary->phone);

                $summary->nudge_url     = null;
                $summary->nudge_phone   = $wa;
                $summary->nudge_message = $msg;
            });
        }

        /* ============================================================
           BUSINESS PULSE
           ============================================================ */

        $businessPulse = null;

        if (in_array($userRole, ['admin', 'team_manager'], true)) {
            $businessPulse = $this->getBusinessPulse(
                $scopedAgentIds,
                $scopedLeadIds
            );
        }

        /* ============================================================
           GREETING
           ============================================================ */

        $hour = (int) now()->format('H');

        if ($hour < 12) {
            $greeting = 'Good morning';
        } elseif ($hour < 17) {
            $greeting = 'Good afternoon';
        } else {
            $greeting = 'Good evening';
        }

        /* ============================================================
           STATS
           ============================================================ */

        $totalLeads       = $leads->count();
        $totalPending     = $pendingFollowups->count();
        $totalNurture     = $reactivationTasks->count();
        $overdueCount     = $overdueTasks->count();
        $dueSoonCount     = $dueSoonTasks->count();
        $restOfTodayCount = $restOfTodayTasks->count();
        $tomorrowCount    = $tomorrowTasks->count();

        $agentCountQuery = Agent::active();

        if ($scopedAgentIds !== null) {
            $agentCountQuery->whereIn('id', $scopedAgentIds);
        }

        $agentCount = $agentCountQuery->count();

        $statusCounts = Lead::select(
                'status',
                DB::raw('count(*) as total')
            )
            ->when(
                $scopedLeadIds !== null,
                fn ($q) => $q->whereIn('id', $scopedLeadIds)
            )
            ->groupBy('status')
            ->pluck('total', 'status')
            ->toArray();

        /* ============================================================
           SITE VISITS TODAY
           ============================================================ */

        $todayStart = now()->startOfDay();
        $todayEnd   = now()->endOfDay();

        $todayVisitsQuery = Lead::with([
                'agent.user',
                'project',
                'tag',
                'labels',
            ])
            ->whereNotNull('visit_scheduled_at')
            ->whereBetween(
                'visit_scheduled_at',
                [$todayStart, $todayEnd]
            );

        if ($scopedLeadIds !== null) {
            $todayVisitsQuery->whereIn('id', $scopedLeadIds);
        }

        $todayVisits = $todayVisitsQuery
            ->orderBy('visit_scheduled_at')
            ->get();

        // Attach structured outcomes recorded on each scheduled visit date.
        // A lead may have multiple actual outings on the same day, so preserve
        // all matching records rather than assuming a one-to-one appointment.
        if ($todayVisits->isNotEmpty()) {
            $todayVisitOutcomes = DB::table('site_visits')
                ->whereIn('lead_id', $todayVisits->pluck('id'))
                ->whereBetween('visit_at', [$todayStart, $todayEnd])
                ->orderBy('visit_at')
                ->get()
                ->groupBy('lead_id');

            $todayVisits->each(function ($lead) use ($todayVisitOutcomes) {
                $outcomes = $todayVisitOutcomes->get($lead->id, collect());
                $lead->today_visit_outcomes = $outcomes;
                $lead->today_visit_recorded = $outcomes->isNotEmpty();
                $lead->today_visit_attended = $outcomes->contains(
                    fn ($visit) => (bool) $visit->client_attended
                );
            });
        }

        /* ============================================================
           RECENT LEADS
           ============================================================ */

        $recentLeads = $leads->take(5);

        /* ============================================================
           ROLE-AWARE ASSIGNMENT
           ============================================================ */

        $currentUser = User::find($userId);

        $assignableAgents = $currentUser
            ? app(TeamService::class)->assignableAgentsFor($currentUser)
            : collect();

        /*
         * Delegated users must not receive an unrestricted agent
         * selector merely because TeamService knows about their
         * underlying Admin role.
         */
        if ($this->delegatedAccess->hasProfile($userId)
            && $scopedAgentIds !== null
        ) {
            $assignableAgents = $assignableAgents
                ->whereIn('id', $scopedAgentIds)
                ->values();
        }

        $agentsByTeam = $assignableAgents->groupBy(function ($agent) {

            $team = $agent->teams->first();

            return $team
                ? $team->name
                : 'Other Agents';
        });

        $selfAgentId = $agentRecord?->id;

        /* ============================================================
           BUSINESS METRICS
           ============================================================ */

        $scoped = function ($query) use ($scopedLeadIds) {
            return $scopedLeadIds === null
                ? $query
                : $query->whereIn('id', $scopedLeadIds);
        };

        /* ---------- Leads ---------- */

        $statNewToday = $scoped(Lead::query())
            ->whereDate('created_at', now()->toDateString())
            ->count();

        $statNewWeek = $scoped(Lead::query())
            ->where(
                'created_at',
                '>=',
                now()->startOfWeek()
            )
            ->count();

        $statActive = $scoped(Lead::query())
            ->whereNotIn('status', ['booking', 'lost'])
            ->count();

        /* ---------- Site Visits ---------- */

        // Scheduled = leads currently sitting in the Visit Scheduled stage.
        // A past scheduled datetime alone does NOT mean the customer actually visited.
        $statVisitsScheduled = $scoped(Lead::query())
            ->where('status', 'visit_scheduled')
            ->whereNotNull('visit_scheduled_at')
            ->count();

        // Actual visits come from structured site visit records.
        // Only attended customer outings count as completed visits.
        $structuredVisits = DB::table('site_visits')
            ->where('client_attended', true);

        if ($scopedLeadIds !== null) {
            $structuredVisits->whereIn('lead_id', $scopedLeadIds);
        }

        $statVisitsDoneActual = (clone $structuredVisits)->count();

        $statVisitsDoneActualWeek = (clone $structuredVisits)
            ->whereBetween('visit_at', [now()->startOfWeek(), now()->endOfWeek()])
            ->count();

        // Every physical project visited during an attended outing counts once
        // through its site_visit_projects row.
        $statVisitProjectsDone = DB::table('site_visit_projects as svp')
            ->join('site_visits as sv', 'sv.id', '=', 'svp.site_visit_id')
            ->where('sv.client_attended', true)
            ->when(
                $scopedLeadIds !== null,
                fn ($q) => $q->whereIn('sv.lead_id', $scopedLeadIds)
            )
            ->count();
        $statVisitsNext7d = $scoped(Lead::query())
            ->whereNotNull('visit_scheduled_at')
            ->whereBetween(
                'visit_scheduled_at',
                [now(), now()->addDays(7)]
            )
            ->count();

        $statVisitsToday = $scoped(Lead::query())
            ->whereNotNull('visit_scheduled_at')
            ->whereBetween(
                'visit_scheduled_at',
                [now()->startOfDay(), now()->endOfDay()]
            )
            ->count();

        /* ---------- Bookings ---------- */

        $statBookedTotal = $scoped(Lead::query())
            ->where('status', 'booking')
            ->count();

        $statBookedMonth = $scoped(Lead::query())
            ->where('status', 'booking')
            ->whereBetween(
                'booking_date',
                [now()->startOfMonth(), now()->endOfMonth()]
            )
            ->count();

        /* ---------- In-flight pipeline ---------- */

        $statNegotiation = $scoped(Lead::query())
            ->where('status', 'negotiation')
            ->count();

        $statVisitDone = $scoped(Lead::query())
            ->where('status', 'visit_done')
            ->count();

        $isSuperAdmin = app(\App\Services\SuperAdminService::class)->isSuperAdmin();
        $ownerCommandCenter = $isSuperAdmin
            ? app(\App\Services\SuperAdminCommandCenterService::class)->snapshot()
            : null;

        /* ============================================================
           VIEW
           ============================================================ */

        return view('dashboard', compact(
            'userName',
            'userRole',
            'agentId',
            'scopedAgentIds',
            'scopedLeadIds',
            'workScope',
            'greeting',
            'projects',
            'sources',
            'leads',
            'untouchedLeads',
            'untouchedLeadCount',
            'managerScopeAgents',
            'managerScopeUntouchedLeads',
            'selectedManagerAgentId',
            'recentLeads',
            'pendingFollowups',
            'overdueTasks',
            'dueSoonTasks',
            'restOfTodayTasks',
            'tomorrowTasks',
            'reactivationTasks',
            'totalLeads',
            'totalPending',
            'totalNurture',
            'overdueCount',
            'dueSoonCount',
            'restOfTodayCount',
            'tomorrowCount',
            'personalPendingFollowups',
            'personalOverdueCount',
            'personalTodayCount',
            'agentCount',
            'statusCounts',
            'todayVisits',
            'agentOverdueSummary',
            'managerAttentionSummary',
            'businessPulse',
            'statNewToday',
            'statNewWeek',
            'statActive',
            'statVisitsScheduled',
            'statVisitsDoneActual',
            'statVisitProjectsDone',
            'statVisitsDoneActualWeek',
            'statVisitsNext7d',
            'statVisitsToday',
            'statBookedTotal',
            'statBookedMonth',
            'statNegotiation',
            'statVisitDone',
            'assignableAgents',
            'agentsByTeam',
            'isSuperAdmin',
            'ownerCommandCenter',
            'selfAgentId'
        ));
    }

    /* ================================================================
       BUSINESS PULSE — activity / aging / projects
       ================================================================ */

    private function getBusinessPulse(
        ?array $scopedAgentIds,
        ?array $scopedLeadIds
    ): array {

        /* ============================================================
           1. TEAM ACTIVITY TODAY
           ============================================================ */

        $agentQuery = Agent::with('user')
            ->where('status', 'active');

        if ($scopedAgentIds !== null) {
            $agentQuery->whereIn('id', $scopedAgentIds);
        }

        $agents = $agentQuery->get();

        $agentIds = $agents->pluck('id')->all();
        $todayStart = now()->startOfDay();

        /*
         * System-generated activity types must not count as agent work.
         */
        $systemTypes = [
            'lead_shared',
            'lead_reassigned',
            'status_change',
            'shared_agent_report',
            'site_team_report',
            'external_share',
        ];

        /*
         * System-generated outcome strings.
         */
        $systemOutcomePatterns = [
            'Meta Ads lead',
            'Meta re-enquiry',
            'Website lead captured',
            'Website re-enquiry',
            'Website Chat lead captured',
            'Website Chat re-enquiry',
            'Lead revived via new enquiry',
            'Customer denied enquiry',
            'Customer confirmed enquiry',
            'Customer replied on WhatsApp',
            'Callback requested via WhatsApp',
            'Site visit requested via WhatsApp',
            'Brochure requested via WhatsApp',
        ];

        $lastActivityRows = empty($agentIds)
            ? collect()
            : \App\Models\Activity::select('agent_id')
                ->selectRaw(
                    'MAX(logged_at) AS last_at'
                )
                ->selectRaw(
                    'SUM(CASE WHEN logged_at >= ? THEN 1 ELSE 0 END) AS today_count',
                    [$todayStart]
                )
                ->whereIn('agent_id', $agentIds)
                ->whereNotIn('type', $systemTypes)
                ->where(function ($q) use ($systemOutcomePatterns) {

                    foreach ($systemOutcomePatterns as $pattern) {
                        $q->where(
                            'outcome',
                            'not like',
                            '%' . $pattern . '%'
                        );
                    }
                })
                ->groupBy('agent_id')
                ->get()
                ->keyBy('agent_id');

        $teamActivity = $agents
            ->map(function ($agent) use ($lastActivityRows) {

                $row = $lastActivityRows[$agent->id] ?? null;

                $lastAt = $row?->last_at
                    ? Carbon::parse($row->last_at)
                    : null;

                $todayCount = (int) ($row->today_count ?? 0);

                $minutesAgo = $lastAt
                    ? (int) $lastAt->diffInMinutes(now())
                    : null;

                if ($minutesAgo === null) {
                    $status = 'silent';
                } elseif ($minutesAgo <= 60) {
                    $status = 'active';
                } elseif ($minutesAgo <= 180) {
                    $status = 'idle';
                } else {
                    $status = 'silent';
                }

                return (object) [
                    'agent_id'    => $agent->id,
                    'name'        => $agent->user?->name
                        ?? ('Agent #' . $agent->id),
                    'last_at'     => $lastAt,
                    'minutes_ago' => $minutesAgo,
                    'today_count' => $todayCount,
                    'status'      => $status,
                ];
            })
            ->sortByDesc('today_count')
            ->sortBy('name')
            ->values();

        /* ============================================================
           2. PIPELINE AGING
           ============================================================ */

        $ageQuery = Lead::query()
            ->select(
                'id',
                'customer_name',
                'status',
                'updated_at',
                'created_at'
            );

        if ($scopedLeadIds !== null) {
            $ageQuery->whereIn('id', $scopedLeadIds);
        }

        $allLeads = $ageQuery->get();

        /*
         * Get last status-change timestamp per lead.
         */
        $statusChangeMap = [];

        if ($allLeads->isNotEmpty()) {

            $rows = \App\Models\Activity::whereIn(
                    'lead_id',
                    $allLeads->pluck('id')->all()
                )
                ->where('type', 'status_change')
                ->selectRaw(
                    'lead_id, MAX(logged_at) AS last_at'
                )
                ->groupBy('lead_id')
                ->get();

            foreach ($rows as $r) {
                $statusChangeMap[$r->lead_id] =
                    Carbon::parse($r->last_at);
            }
        }

        $statuses = $this->settings->statuses()
            ->where('is_final', false)
            ->sortBy('sort_order')
            ->values();

        $pipelineAging = $statuses->map(
            function ($s) use ($allLeads, $statusChangeMap) {

                $subset = $allLeads->where(
                    'status',
                    $s->key
                );

                $aged = $subset->map(
                    function ($l) use ($statusChangeMap) {

                        $at =
                            $statusChangeMap[$l->id]
                            ?? $l->updated_at
                            ?? $l->created_at;

                        return [
                            'lead' => $l,
                            'age'  => $at
                                ? (int) Carbon::parse($at)
                                    ->diffInDays(now())
                                : 0,
                        ];
                    }
                );

                $aged = $aged->sortByDesc('age')->values();
                $oldest = $aged->first();
                $staleLeads = $aged
                    ->filter(fn ($item) => $item['age'] >= 7)
                    ->values();

                return (object) [
                    'key'         => $s->key,
                    'label'       => $s->label,
                    'color'       => $s->color ?? 'blue',
                    'count'       => $subset->count(),
                    'oldest_days' => $oldest['age'] ?? null,
                    'oldest_lead' => $oldest['lead']->customer_name ?? null,
                    'oldest_id'   => $oldest['lead']->id ?? null,
                    'stale_count' => $staleLeads->count(),
                    'stale_leads' => $staleLeads->take(10)->map(fn ($item) => (object) [
                        'id' => $item['lead']->id,
                        'name' => $item['lead']->customer_name ?: ('Lead #' . $item['lead']->id),
                        'age_days' => $item['age'],
                    ])->values(),
                ];
            }
        );

        /* ============================================================
           3. TOP + STALE PROJECTS
           ============================================================ */

        $projectLeadCounts = Lead::query()
            ->select('project_id')
            ->selectRaw('COUNT(*) AS cnt')
            ->selectRaw('MAX(last_activity_at) AS last_seen')
            ->whereNotNull('project_id')
            ->when(
                $scopedLeadIds !== null,
                fn ($q) => $q->whereIn('id', $scopedLeadIds)
            )
            ->groupBy('project_id')
            ->get();

        $projectIds = $projectLeadCounts
            ->pluck('project_id')
            ->all();

        $projectNames = empty($projectIds)
            ? collect()
            : Project::whereIn('id', $projectIds)
                ->pluck('name', 'id');

        /* ---------- TOP ---------- */

        $topProjects = $projectLeadCounts
            ->sortByDesc('cnt')
            ->take(10)
            ->map(fn ($r) => (object) [
                'id' => $r->project_id,
                'name' =>
                    $projectNames[$r->project_id]
                    ?? ('Project #' . $r->project_id),
                'lead_count' => (int) $r->cnt,
            ])
            ->values();

        /* ---------- STALE ---------- */

        $staleCutoff = now()->subDays(14);

        $nonFinalCounts = Lead::query()
            ->select('project_id')
            ->selectRaw(
                'COUNT(*) AS non_final_count'
            )
            ->whereNotNull('project_id')
            ->whereNotIn('status', ['booking', 'lost'])
            ->when(
                $scopedLeadIds !== null,
                fn ($q) => $q->whereIn('id', $scopedLeadIds)
            )
            ->groupBy('project_id')
            ->pluck(
                'non_final_count',
                'project_id'
            );

        $staleProjects = $projectLeadCounts
            ->filter(function ($r) use (
                $staleCutoff,
                $nonFinalCounts
            ) {

                if (empty($nonFinalCounts[$r->project_id])) {
                    return false;
                }

                if (empty($r->last_seen)) {
                    return true;
                }

                return Carbon::parse($r->last_seen)
                    ->lt($staleCutoff);
            })
            ->sortBy(
                fn ($r) =>
                    $r->last_seen ?? '1970-01-01'
            )
            ->take(10)
            ->map(fn ($r) => (object) [
                'id' => $r->project_id,

                'name' =>
                    $projectNames[$r->project_id]
                    ?? ('Project #' . $r->project_id),

                'lead_count' => (int) $r->cnt,

                'non_final' =>
                    (int) (
                        $nonFinalCounts[$r->project_id]
                        ?? 0
                    ),

                'last_seen' =>
                    $r->last_seen
                        ? Carbon::parse($r->last_seen)
                        : null,

                'days_silent' =>
                    $r->last_seen
                        ? (int) Carbon::parse($r->last_seen)
                            ->diffInDays(now())
                        : null,
            ])
            ->values();

        return [
            'team_activity'  => $teamActivity,
            'pipeline_aging' => $pipelineAging,
            'top_projects'   => $topProjects,
            'stale_projects' => $staleProjects,
        ];
    }
}