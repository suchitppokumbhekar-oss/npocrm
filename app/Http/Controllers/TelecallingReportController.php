<?php

namespace App\Http\Controllers;

use App\Models\Agent;
use App\Models\Team;
use App\Models\TeamMember;
use App\Services\CallService;
use App\Services\DelegatedAccessService;
use App\Services\ReportScopeService;
use Carbon\Carbon;
use Illuminate\Http\Request;

class TelecallingReportController extends Controller
{
    public function __construct(
        private ReportScopeService $scope,
        private CallService $calls,
        private DelegatedAccessService $delegatedAccess,
    ) {}

    private function requireAccess(): void
    {
        if (! $this->scope->canSeeReports()) {
            abort(403, 'Admin or team manager only.');
        }
    }

    public function index(Request $request)
    {
        $this->requireAccess();

        $to = $request->filled('to')
            ? Carbon::parse($request->input('to'))->endOfDay()
            : now()->endOfDay();

        $from = $request->filled('from')
            ? Carbon::parse($request->input('from'))->startOfDay()
            : (clone $to)->subDays(29)->startOfDay();

        if ($from->greaterThan($to)) {
            [$from, $to] = [
                $to->copy()->startOfDay(),
                $from->copy()->endOfDay(),
            ];
        }

        $userId = (int) session('user_id');

        /*
         * ============================================================
         * AGENT SCOPE
         * ============================================================
         *
         * Delegated Admin:
         *     use DelegatedAccessService::visibleAgentIds()
         *
         * Normal Admin / Team Manager:
         *     preserve existing ReportScopeService behaviour.
         */
        if ($this->delegatedAccess->hasProfile($userId)) {

            $agentIds = $this->delegatedAccess->visibleAgentIds(
                $userId
            );

            /*
             * Never allow an empty delegated scope to become
             * unrestricted.
             */
            if ($agentIds === []) {
                $agentIds = [-1];
            }

        } else {

            $agentIds = $this->scope->agentIds();
        }

        $agentIds = array_values(
            array_unique(
                array_map('intval', $agentIds)
            )
        );

        /*
         * ============================================================
         * OPTIONAL TEAM FILTER
         * ============================================================
         *
         * The requested team must intersect with the already
         * authorized agent scope.
         */
        $teamFilter = $request->input('team_id');

        if ($teamFilter) {

            $teamFilter = (int) $teamFilter;

            $teamAgents = TeamMember::query()
                ->where('team_id', $teamFilter)
                ->where('is_active', true)
                ->pluck('agent_id')
                ->map(fn ($id) => (int) $id)
                ->all();

            $agentIds = array_values(
                array_intersect(
                    $agentIds,
                    $teamAgents
                )
            );

            /*
             * Keep the scope safely empty rather than accidentally
             * allowing all agents.
             */
            if ($agentIds === []) {
                $agentIds = [-1];
            }
        }

        /*
         * ============================================================
         * CALL STATISTICS
         * ============================================================
         *
         * CallService receives ONLY the permitted agent IDs.
         */
        $stats = $this->calls->agentCallStats(
            $agentIds,
            $from,
            $to
        );

        $outcomes = $this->calls->outcomeBreakdown(
            $agentIds,
            $from,
            $to
        );

        /*
         * ============================================================
         * TOTALS
         * ============================================================
         */
        $totals = [
            'calls' =>
                array_sum(
                    array_column(
                        $stats,
                        'total_calls'
                    )
                ),

            'connected' =>
                array_sum(
                    array_column(
                        $stats,
                        'connected_calls'
                    )
                ),

            'talk_sec' =>
                array_sum(
                    array_column(
                        $stats,
                        'talk_seconds'
                    )
                ),

            'agents' =>
                count($stats),
        ];

        $totals['connect_rate'] =
            $totals['calls'] > 0
                ? round(
                    $totals['connected']
                    / $totals['calls']
                    * 100,
                    1
                )
                : 0;

        $totals['talk_minutes'] =
            round(
                $totals['talk_sec'] / 60,
                1
            );

        /*
         * ============================================================
         * TEAM DROPDOWN
         * ============================================================
         *
         * Normal users retain their existing team scope.
         *
         * Delegated users see only teams represented by their
         * delegated agents.
         */
        if ($this->delegatedAccess->hasProfile($userId)) {

            $delegatedAgentIds =
                $this->delegatedAccess->visibleAgentIds(
                    $userId
                );

            if ($delegatedAgentIds === []) {

                $teams = collect();

            } else {

                $delegatedTeamIds = TeamMember::query()
                    ->whereIn(
                        'agent_id',
                        $delegatedAgentIds
                    )
                    ->where('is_active', true)
                    ->pluck('team_id')
                    ->map(fn ($id) => (int) $id)
                    ->unique()
                    ->values()
                    ->all();

                $teams = empty($delegatedTeamIds)
                    ? collect()
                    : Team::query()
                        ->whereIn(
                            'id',
                            $delegatedTeamIds
                        )
                        ->orderBy('name')
                        ->get([
                            'id',
                            'name',
                        ]);
            }

        } else {

            $teams = Team::query()
                ->whereIn(
                    'id',
                    $this->scope->teamIds()
                )
                ->orderBy('name')
                ->get([
                    'id',
                    'name',
                ]);
        }

        return view(
            'reports.telecalling',
            compact(
                'stats',
                'outcomes',
                'totals',
                'teams',
                'from',
                'to',
                'teamFilter'
            )
        );
    }
}