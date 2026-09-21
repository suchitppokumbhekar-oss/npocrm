<?php

namespace App\Http\Controllers;

use App\Models\Agent;
use App\Models\Followup;
use App\Services\DelegatedAccessService;
use App\Services\TeamService;
use App\Services\NudgeService;
use App\Models\AuditLog;
use App\Models\Activity;
use Illuminate\Http\Request;

class TeamStatusController extends Controller
{
    public function __construct(
        private DelegatedAccessService $delegatedAccess
    ) {}

    public function index(Request $request)
    {
        if (! session('user_id')) {
            return redirect('/login');
        }

        $role = session('user_role');

        if (! in_array($role, ['admin', 'team_manager'], true)) {
            abort(403, 'Only admins and team managers can access Team Status.');
        }

        $userId = (int) session('user_id');
        $isAdmin = $role === 'admin';

        /*
         * ------------------------------------------------------------
         * AGENT ACCESS SCOPE
         * ------------------------------------------------------------
         *
         * A delegated profile is configured by the Super Admin (AJ).
         *
         * If this user has an active delegated profile, ALWAYS use
         * DelegatedAccessService as the source of truth.
         *
         * This prevents an Admin from bypassing delegated access simply
         * because their underlying role is "admin".
         */
        if ($this->delegatedAccess->hasProfile($userId)) {

            $scopedAgentIds = $this->delegatedAccess->visibleAgentIds($userId);

        } elseif ($isAdmin) {

            /*
             * Existing unrestricted Admin behaviour.
             *
             * This remains unchanged for Admins who do not have a
             * delegated profile.
             */
            $scopedAgentIds = Agent::active()
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();

        } else {

            /*
             * Existing Team Manager behaviour.
             */
            $scopedAgentIds = app(TeamService::class)
                ->agentIdsForManager($userId);
        }

        /*
         * Always normalize the scope.
         */
        $scopedAgentIds = collect($scopedAgentIds)
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();

        if (empty($scopedAgentIds)) {
            return view('team-status.index', [
                'rows' => collect(),
                'selectedAgent' => null,
                'selectedTasks' => collect(),
                'filter' => 'help',
                'totals' => (object) [
                    'agents' => 0,
                    'overdue' => 0,
                    'today' => 0,
                    'escalated' => 0,
                ],
                'isAdmin' => $isAdmin,
                'escalationHistory' => collect(),
            ]);
        }

        /*
         * ------------------------------------------------------------
         * URL agent_id SECURITY CHECK
         * ------------------------------------------------------------
         *
         * agent_id is only a filter.
         * It must NEVER expand the user's authorization scope.
         *
         * A delegated Admin cannot access another agent by manually
         * changing:
         *
         * /team-status?agent_id=123
         *
         * If the requested agent is outside the delegated scope,
         * reject the request.
         */
        if ($request->filled('agent_id')) {
            $requestedAgentId = (int) $request->query('agent_id');

            if (! in_array($requestedAgentId, $scopedAgentIds, true)) {
                abort(403, 'You do not have access to this agent.');
            }
        }

        $now = now();
        $endToday = now()->endOfDay();
        $end2h = now()->addHours(2);

        /*
         * ------------------------------------------------------------
         * FOLLOW-UP / TASK DATA
         * ------------------------------------------------------------
         *
         * Only tasks belonging to agents inside the authorized scope
         * are loaded.
         */
        $tasks = Followup::with(['lead.project', 'agent.user'])
            ->whereIn('agent_id', $scopedAgentIds)
            ->where('status', 'pending')
            ->where(function ($q) use ($now) {
                $q->where('action_type', '!=', 'reactivation_call')
                  ->orWhere(function ($qq) use ($now) {
                      $qq->where('action_type', 'reactivation_call')
                         ->where('scheduled_for', '<=', $now);
                  });
            })
            ->orderBy('scheduled_for')
            ->get();

        /*
         * Only authorized active agents are displayed.
         */
        $agents = Agent::with('user')
            ->whereIn('id', $scopedAgentIds)
            ->where('status', 'active')
            ->orderBy('id')
            ->get();

        $rows = $agents->map(function ($agent) use ($tasks, $now, $endToday, $end2h) {
            $items = $tasks->where('agent_id', $agent->id);

            $overdue = $items->filter(
                fn ($t) => $t->scheduled_for && $t->scheduled_for->lt($now)
            );

            $today = $items->filter(
                fn ($t) =>
                    $t->scheduled_for &&
                    $t->scheduled_for->gte($now) &&
                    $t->scheduled_for->lte($endToday)
            );

            $dueSoon = $items->filter(
                fn ($t) =>
                    $t->scheduled_for &&
                    $t->scheduled_for->gt($now) &&
                    $t->scheduled_for->lte($end2h)
            );

            $escalated = $items->filter(
                fn ($t) => $t->escalated_flag
            );

            $oldest = $overdue
                ->sortBy('scheduled_for')
                ->first();

            $ageHours = $oldest?->scheduled_for
                ? $oldest->scheduled_for->diffInMinutes($now) / 60
                : 0;

            $severity = match (true) {
                $ageHours >= 24 => 'critical',
                $ageHours >= 6 => 'high',
                $ageHours >= 2 => 'medium',
                $overdue->count() > 0 => 'watch',
                $escalated->count() > 0 => 'high',
                default => 'ok',
            };

            return (object) [
                'agent_id' => $agent->id,
                'name' => $agent->user?->name ?? ('Agent #' . $agent->id),
                'phone' => $agent->phone,
                'overdue' => $overdue->count(),
                'today' => $today->count(),
                'due_soon' => $dueSoon->count(),
                'escalated' => $escalated->count(),
                'pending' => $items->count(),
                'oldest_overdue' => $oldest,
                'severity' => $severity,
                'needs_help' => $overdue->count() > 0 || $escalated->count() > 0,
                'nudge_count' => app(NudgeService::class)->countForAgent((int) $agent->id),
            ];
        });

        $filter = (string) $request->query('filter', 'help');

        if (! in_array($filter, ['help', 'overdue', 'today', 'all'], true)) {
            $filter = 'help';
        }

        $rows = $rows->filter(function ($row) use ($filter) {
            return match ($filter) {
                'overdue' => $row->overdue > 0,
                'today' => $row->overdue > 0 || $row->today > 0,
                'all' => $row->pending > 0,
                default => $row->needs_help,
            };
        })->sortByDesc(function ($row) {
            $severityWeight = [
                'critical' => 4000,
                'high' => 3000,
                'medium' => 2000,
                'watch' => 1000,
                'ok' => 0,
            ];

            return
                ($severityWeight[$row->severity] ?? 0)
                + ($row->overdue * 100)
                + ($row->escalated * 50)
                + $row->today;
        })->values();

        $totals = (object) [
            'agents' => $agents->count(),

            'overdue' => $tasks->filter(
                fn ($t) =>
                    $t->scheduled_for &&
                    $t->scheduled_for->lt($now)
            )->count(),

            'today' => $tasks->filter(
                fn ($t) =>
                    $t->scheduled_for &&
                    $t->scheduled_for->gte($now) &&
                    $t->scheduled_for->lte($endToday)
            )->count(),

            'escalated' => $tasks
                ->where('escalated_flag', true)
                ->count(),
        ];

        /*
         * ------------------------------------------------------------
         * SELECTED AGENT
         * ------------------------------------------------------------
         *
         * We already verified agent_id against $scopedAgentIds above.
         * Therefore this cannot expose an unauthorized agent.
         */
        $selectedAgent = null;
        $selectedTasks = collect();

        if ($request->filled('agent_id')) {
            $selectedAgentId = (int) $request->query('agent_id');

            $selectedAgent = $agents->firstWhere(
                'id',
                $selectedAgentId
            );

            if ($selectedAgent) {
                $selectedTasks = $tasks
                    ->where('agent_id', $selectedAgentId)
                    ->values();
            }
        }

        $escalationHistory = app(NudgeService::class)->recentEscalationsForAgents($scopedAgentIds, 30);

        return view('team-status.index', compact(
            'rows',
            'selectedAgent',
            'selectedTasks',
            'filter',
            'totals',
            'isAdmin',
            'escalationHistory'
        ));
    }
}