<?php

namespace App\Http\Controllers;

use App\Models\Team;
use App\Models\TeamMember;
use App\Services\ComplianceService;
use App\Services\DelegatedAccessService;
use App\Services\ReportScopeService;
use App\Services\SettingsService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

class TeamReportController extends Controller
{
    public function __construct(
        private ReportScopeService $scope,
        private ComplianceService $compliance,
        private SettingsService $settings,
        private DelegatedAccessService $delegatedAccess,
    ) {}

    /* ============================================================
       ACCESS
       ============================================================ */

    private function requireAccess(): void
    {
        if (! $this->scope->canSeeReports()) {
            abort(403, 'Reports are for admins and team managers only.');
        }
    }

    private function requireAdmin(): void
    {
        if (! $this->scope->canSeeCrmHealth()) {
            abort(403, 'Admin only.');
        }
    }

    /* ============================================================
       DELEGATED ACCESS
       ============================================================ */

    /**
     * null  = normal Admin / Team Manager scope
     * array = delegated user's effective agent scope
     */
    private function effectiveAgentIds(): ?array
    {
        $userId = (int) session('user_id');

        if (! $userId) {
            return [];
        }

        /*
         * Delegated access always takes priority.
         */
        if ($this->delegatedAccess->hasProfile($userId)) {
            return $this->delegatedAccess->visibleAgentIds($userId);
        }

        /*
         * Normal Admin / Team Manager handling remains
         * controlled by ReportScopeService.
         */
        return null;
    }

    /**
     * Get teams represented by an already-authorized agent scope.
     */
    private function effectiveTeamIds(?array $agentIds): array
    {
        /*
         * Normal Admin / Team Manager.
         */
        if ($agentIds === null) {
            return $this->scope->teamIds();
        }

        if ($agentIds === []) {
            return [];
        }

        return TeamMember::query()
            ->whereIn('agent_id', $agentIds)
            ->where('is_active', true)
            ->pluck('team_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    /* ============================================================
       PERIOD
       ============================================================ */

    private function period(Request $request): array
    {
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

        return [$from, $to];
    }

    /* ============================================================
       SORT
       ============================================================ */

    private function sortRows(
        array &$rows,
        array $allowedFields,
        string $defaultField
    ): void {
        $sort = request()->input('sort');

        $dir = request()->input('dir', 'asc') === 'desc'
            ? 'desc'
            : 'asc';

        if (
            ! $sort
            || ! in_array($sort, $allowedFields, true)
        ) {
            $sort = $defaultField;
            $dir = 'desc';
        }

        usort($rows, function ($a, $b) use ($sort, $dir) {

            $av = $a[$sort] ?? null;
            $bv = $b[$sort] ?? null;

            if ($av === null && $bv === null) {
                return 0;
            }

            if ($av === null) {
                return 1;
            }

            if ($bv === null) {
                return -1;
            }

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
       TEAM PERFORMANCE
       ============================================================ */

    public function teamPerformance(Request $request)
{
    $this->requireAccess();

    [$from, $to] = $this->period($request);

    $agentIds = $this->effectiveAgentIds();

    /*
     * ============================================================
     * NORMAL ADMIN / TEAM MANAGER
     * ============================================================
     *
     * Preserve the original, already-working implementation.
     */
    if ($agentIds === null) {

        $teamIds = $this->scope->teamIds();

        $rows = $this->compliance->teamScorecard(
            $teamIds,
            $from,
            $to
        );

        return view('reports.team', [
            'rows'    => $rows,
            'from'    => $from,
            'to'      => $to,
            'isAdmin' => $this->scope->isAdmin(),
        ]);
    }

    /*
     * ============================================================
     * DELEGATED ADMIN
     * ============================================================
     *
     * We cannot call teamScorecard() directly here because that
     * would calculate the complete team and could expose members
     * outside the delegated profile.
     *
     * Instead:
     *
     * delegated users/teams
     *        ↓
     * visibleAgentIds()
     *        ↓
     * agentScorecard()
     *        ↓
     * group only those permitted agents
     *        ↓
     * produce the exact structure expected by reports/team.blade.php
     */

    if ($agentIds === []) {

        return view('reports.team', [
            'rows'    => [],
            'from'    => $from,
            'to'      => $to,
            'isAdmin' => false,
        ]);
    }

    $agentRows = $this->compliance->agentScorecard(
        $agentIds,
        $from,
        $to
    );

    /*
     * Load team/member information for the permitted agents only.
     */
    $teamMembers = TeamMember::query()
        ->whereIn('agent_id', $agentIds)
        ->where('is_active', true)
        ->with(['team'])
        ->get();

    /*
     * Map:
     *
     * agent_id => team information
     */
    $agentTeams = [];

    foreach ($teamMembers as $member) {

        $agentId = (int) $member->agent_id;

        if (! isset($agentTeams[$agentId])) {
            $agentTeams[$agentId] = [];
        }

        $teamId = (int) $member->team_id;

        $agentTeams[$agentId][] = [
            'id'   => $teamId,
            'name' => $member->team?->name ?? 'Unassigned',
        ];
    }

    /*
     * Group the already-authorized agent scorecards.
     */
    $grouped = [];

    foreach ($agentRows as $row) {

        /*
         * ComplianceService normally gives us the agent name.
         */
        $agentName = $row['name'] ?? 'Unknown';

        /*
         * Try to identify the agent from the row.
         *
         * Different scorecard versions may expose agent_id,
         * so keep this defensive.
         */
        $agentId = isset($row['agent_id'])
            ? (int) $row['agent_id']
            : null;

        /*
         * If ComplianceService already gives the team name,
         * use it. Otherwise use the actual TeamMember mapping.
         */
        $teamName = $row['team'] ?? null;

        if (! $teamName && $agentId && isset($agentTeams[$agentId])) {
            $teamName = $agentTeams[$agentId][0]['name'] ?? null;
        }

        $teamName = $teamName ?: 'Unassigned';

        if (! isset($grouped[$teamName])) {

            $grouped[$teamName] = [
                'name' => $teamName,

                'manager_names' => [],

                'member_ids' => [],

                'leads' => 0,

                'avg_response_values' => [],

                'within_15_values' => [],

                'on_time_values' => [],

                'overdue' => 0,

                'escalated' => 0,

                'activities' => 0,

                'talk_minutes' => 0,

                'bookings' => 0,

                'brokerage' => 0,

                'score_values' => [],
            ];
        }

        /*
         * Count the permitted agent only.
         */
        if ($agentId) {
            $grouped[$teamName]['member_ids'][$agentId] = true;
        } else {
            /*
             * Still count the scorecard row if ComplianceService
             * does not expose agent_id.
             */
            $grouped[$teamName]['member_ids'][] = $agentName;
        }

        /*
         * Leads.
         */
        $grouped[$teamName]['leads'] +=
            (int) ($row['leads_assigned'] ?? $row['leads'] ?? 0);

        /*
         * Response time.
         */
        if (
            isset($row['avg_response'])
            && is_numeric($row['avg_response'])
        ) {
            $grouped[$teamName]['avg_response_values'][] =
                (float) $row['avg_response'];
        }

        /*
         * SLA within 15 minutes.
         */
        if (
            isset($row['within_15'])
            && is_numeric($row['within_15'])
        ) {
            $grouped[$teamName]['within_15_values'][] =
                (float) $row['within_15'];
        }

        /*
         * Follow-up on-time percentage.
         */
        if (
            isset($row['on_time_pct'])
            && is_numeric($row['on_time_pct'])
        ) {
            $grouped[$teamName]['on_time_values'][] =
                (float) $row['on_time_pct'];
        }

        /*
         * Follow-up counts.
         */
        $grouped[$teamName]['overdue'] +=
            (int) ($row['overdue'] ?? $row['fup_overdue'] ?? 0);

        $grouped[$teamName]['escalated'] +=
            (int) ($row['escalated'] ?? $row['fup_escalated'] ?? 0);

        /*
         * Activities.
         */
        $grouped[$teamName]['activities'] +=
            (int) ($row['activities'] ?? 0);

        /*
         * Talk time.
         */
        $grouped[$teamName]['talk_minutes'] +=
            (float) ($row['talk_minutes'] ?? 0);

        /*
         * Bookings.
         */
        $grouped[$teamName]['bookings'] +=
            (int) ($row['bookings'] ?? 0);

        /*
         * Brokerage.
         */
        $grouped[$teamName]['brokerage'] +=
            (float) ($row['brokerage'] ?? 0);

        /*
         * Score.
         */
        if (
            isset($row['score'])
            && is_numeric($row['score'])
        ) {
            $grouped[$teamName]['score_values'][] =
                (float) $row['score'];
        }

        /*
         * If the agent scorecard supplies a manager field,
         * retain it.
         */
        if (! empty($row['manager'])) {
            $grouped[$teamName]['manager_names'][] =
                (string) $row['manager'];
        }
    }

    /*
     * ============================================================
     * BUILD EXACT TEAM BLADE STRUCTURE
     * ============================================================
     */

    $rows = [];

    foreach ($grouped as $group) {

        $memberIds = $group['member_ids'];

        /*
         * Unique member count.
         */
        $members = count(
            array_unique(
                array_keys($memberIds)
                ?: $memberIds
            )
        );

        /*
         * Average response.
         */
        $avgResponseValues =
            $group['avg_response_values'];

        $avgResponse =
            count($avgResponseValues) > 0
                ? round(
                    array_sum($avgResponseValues)
                    / count($avgResponseValues),
                    1
                )
                : null;

        /*
         * Average SLA percentage.
         */
        $within15Values =
            $group['within_15_values'];

        $within15 =
            count($within15Values) > 0
                ? round(
                    array_sum($within15Values)
                    / count($within15Values),
                    1
                )
                : 0;

        /*
         * Average follow-up on-time percentage.
         */
        $onTimeValues =
            $group['on_time_values'];

        $onTimePct =
            count($onTimeValues) > 0
                ? round(
                    array_sum($onTimeValues)
                    / count($onTimeValues),
                    1
                )
                : null;

        /*
         * Average score.
         */
        $scoreValues =
            $group['score_values'];

        $score =
            count($scoreValues) > 0
                ? round(
                    array_sum($scoreValues)
                    / count($scoreValues),
                    1
                )
                : 0;

        /*
         * Manager.
         *
         * If the existing ComplianceService supplied manager names,
         * retain them. Otherwise show an em dash rather than
         * exposing or inventing a manager.
         */
        $managerNames = array_values(
            array_unique(
                array_filter(
                    $group['manager_names']
                )
            )
        );

        $manager = count($managerNames) > 0
            ? implode(', ', $managerNames)
            : '—';

        /*
         * EXACT keys expected by reports/team.blade.php.
         */
        $grade = '—';

if ($score !== null) {
    if ($score >= 85) {
        $grade = 'A';
    } elseif ($score >= 70) {
        $grade = 'B';
    } elseif ($score >= 55) {
        $grade = 'C';
    } else {
        $grade = 'D';
    }
}

$rows[] = [
    'name' =>
        $group['name'],

    'manager' =>
        $manager,

    'members' =>
        $members,

    'leads' =>
        $group['leads'],

    'avg_response' =>
        $avgResponse,

    'within_15' =>
        $within15,

    'on_time_pct' =>
        $onTimePct,

    'overdue' =>
        $group['overdue'],

    'escalated' =>
        $group['escalated'],

    'activities' =>
        $group['activities'],

    'talk_minutes' =>
        $group['talk_minutes'],

    'bookings' =>
        $group['bookings'],

    'brokerage' =>
        (float) $group['brokerage'],

    'score' =>
        $score,

    'grade' =>
        $grade,
];
    }

    /*
     * Preserve sorting functionality.
     */
    $this->sortRows(
        $rows,
        [
            'name',
            'manager',
            'members',
            'leads',
            'avg_response',
            'within_15',
            'on_time_pct',
            'overdue',
            'escalated',
            'activities',
            'talk_minutes',
            'bookings',
            'brokerage',
            'score',
        ],
        'score'
    );

    return view('reports.team', [
        'rows' =>
            $rows,

        'from' =>
            $from,

        'to' =>
            $to,

        'isAdmin' =>
            false,
    ]);
}

    /* ============================================================
       AGENT SCORECARD
       ============================================================ */

    public function agentScorecard(Request $request)
    {
        $this->requireAccess();

        [$from, $to] = $this->period($request);

        $agentIds = $this->effectiveAgentIds();

        /*
         * Normal Admin / Team Manager.
         */
        if ($agentIds === null) {
            $agentIds = $this->scope->agentIds();
        }

        /*
         * Never allow an empty delegated scope to become
         * an unrestricted query.
         */
        if ($agentIds === []) {
            $agentIds = [-1];
        }

        /*
         * Team filter is always intersected with the already
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

            if ($agentIds === []) {
                $agentIds = [-1];
            }
        }

        $rows = $this->compliance->agentScorecard(
            $agentIds,
            $from,
            $to
        );

        /*
         * User-selected sorting before statistics/pagination.
         */
        $this->sortRows(
            $rows,
            [
                'name',
                'team',
                'leads_assigned',
                'avg_response',
                'within_15',
                'never_responded',
                'on_time_pct',
                'fup_overdue',
                'fup_escalated',
                'activities',
                'talk_minutes',
                'bookings',
                'brokerage',
                'score',
            ],
            'score'
        );

        /*
         * Full sorted set for the Blade's statistics.
         */
        $fullRows = $rows;

        /*
         * Pagination.
         */
        $page = LengthAwarePaginator::resolveCurrentPage(
            'scorecard_page'
        );

        $perPage = 50;

        $collection = collect($rows);

        $paginated = new LengthAwarePaginator(
            $collection
                ->forPage($page, $perPage)
                ->values(),

            $collection->count(),

            $perPage,

            $page,

            [
                'path' =>
                    request()->url(),

                'pageName' =>
                    'scorecard_page',

                'query' =>
                    request()->query(),
            ]
        );

        /*
         * Only authorized teams appear in the filter.
         */
        $effectiveAgentsForTeams =
            $this->effectiveAgentIds();

        $teamIds = $this->effectiveTeamIds(
            $effectiveAgentsForTeams
        );

        if ($teamIds === []) {

            $teams = collect();

        } else {

            $teams = Team::query()
                ->whereIn('id', $teamIds)
                ->orderBy('name')
                ->get([
                    'id',
                    'name',
                ]);
        }

        return view('reports.scorecard', [
            'rows' =>
                $paginated,

            'fullRows' =>
                $fullRows,

            'teams' =>
                $teams,

            'from' =>
                $from,

            'to' =>
                $to,

            'teamFilter' =>
                $teamFilter,

            'slaMinutes' =>
                $this->compliance->slaMinutes(),
        ]);
    }

    /* ============================================================
       CRM HEALTH
       ============================================================ */

    public function crmHealth(Request $request)
    {
        $this->requireAdmin();

        [$from, $to] = $this->period($request);

        $health = $this->compliance->crmHealth(
            $from,
            $to
        );

        return view('reports.crm-health', [
            'h' => $health,
            'from' => $from,
            'to' => $to,
        ]);
    }
}