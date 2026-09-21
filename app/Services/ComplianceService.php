<?php

namespace App\Services;

use App\Models\Activity;
use App\Models\Agent;
use App\Models\Followup;
use App\Models\Lead;
use App\Models\Team;
use App\Models\TeamMember;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class ComplianceService
{
    public function __construct(private SettingsService $settings) {}

    /** SLA minutes from settings. */
    public function slaMinutes(): int
    {
        return max(1, (int) $this->settings->get('first_contact_delay_minutes', 15));
    }

    /* ============================================================
       AGENT SCORECARD — one row per agent, all KPIs
       ============================================================ */
    public function agentScorecard(array $agentIds, Carbon $from, Carbon $to): array
{
    if (empty($agentIds)) return [];

    $agents = Agent::with('user')->whereIn('id', $agentIds)->orderBy('id')->get();
    $sla    = $this->slaMinutes();

    // IST "now" for the overdue comparison. MySQL's NOW() is UTC (per
    // handoff gotcha), but scheduled_for values are stored as IST strings.
    // Comparing against MySQL NOW() makes overdue tasks appear 5.5h late.
    $nowIst = now()->toDateTimeString();

    // ---- Leads assigned in period ----
    $leads = Lead::whereIn('agent_id', $agentIds)
        ->whereNotNull('assigned_at')
        ->whereBetween('assigned_at', [$from, $to])
        ->get(['id', 'agent_id', 'status', 'assigned_at', 'created_at', 'booking_amount', 'brokerage_amount']);

    // ---- First activity per lead (post-assignment) ----
    $leadIds = $leads->pluck('id')->all();
    $firstActivity = [];
    if (! empty($leadIds)) {
        $rows = DB::table('activities')
            ->whereIn('lead_id', $leadIds)
            ->selectRaw('lead_id, MIN(logged_at) AS first_at')
            ->groupBy('lead_id')
            ->get();
        foreach ($rows as $r) {
            $firstActivity[$r->lead_id] = $r->first_at;
        }
    }

    // ---- Followups in period ----
    // IMPORTANT: scheduled_for is stored as IST; use the bound IST-now value,
    // not MySQL's UTC-based NOW().
    $fupRows = DB::table('followups')
        ->whereIn('agent_id', $agentIds)
        ->whereBetween('created_at', [$from, $to])
        ->selectRaw("
            agent_id,
            SUM(CASE WHEN status = 'done' AND updated_at <= scheduled_for THEN 1 ELSE 0 END) AS done_on_time,
            SUM(CASE WHEN status = 'done' AND updated_at >  scheduled_for THEN 1 ELSE 0 END) AS done_late,
            SUM(CASE WHEN status = 'pending' AND scheduled_for < ? THEN 1 ELSE 0 END) AS overdue_now,
            SUM(CASE WHEN escalated_flag = 1 THEN 1 ELSE 0 END) AS escalated,
            COUNT(*) AS total
        ", [$nowIst])
        ->groupBy('agent_id')
        ->get()
        ->keyBy('agent_id');

    // ---- Activity volume in period ----
    $actRows = DB::table('activities')
        ->whereIn('agent_id', $agentIds)
        ->whereBetween('logged_at', [$from, $to])
        ->selectRaw('agent_id, COUNT(*) AS n, COALESCE(SUM(duration),0) AS seconds')
        ->groupBy('agent_id')
        ->get()
        ->keyBy('agent_id');

    $out = [];

    foreach ($agents as $agent) {
        $agentLeads = $leads->where('agent_id', $agent->id);

        $responseTimes = [];
        $within15 = 0;
        $within60 = 0;
        $never    = 0;

        foreach ($agentLeads as $lead) {
            $firstAt = $firstActivity[$lead->id] ?? null;
            if (! $firstAt) {
                $never++;
                continue;
            }
            $assigned = Carbon::parse($lead->assigned_at);
            $first    = Carbon::parse($firstAt);
            if ($first->lt($assigned)) continue; // activity predates assignment — ignore

            $mins = $assigned->diffInMinutes($first);
            $responseTimes[] = $mins;
            if ($mins <= $sla) $within15++;
            if ($mins <= 60)   $within60++;
        }

        $totalAssigned = $agentLeads->count();
        $responded     = count($responseTimes);
        $avgResponse   = $responded > 0 ? round(array_sum($responseTimes) / $responded, 1) : null;

        $fup = $fupRows[$agent->id] ?? null;
        $doneOnTime = (int) ($fup->done_on_time ?? 0);
        $doneLate   = (int) ($fup->done_late   ?? 0);
        $overdueNow = (int) ($fup->overdue_now ?? 0);
        $escalated  = (int) ($fup->escalated   ?? 0);
        $fupTotal   = (int) ($fup->total       ?? 0);
        $onTimePct  = ($doneOnTime + $doneLate) > 0
            ? round($doneOnTime / ($doneOnTime + $doneLate) * 100, 1)
            : null;

        $act = $actRows[$agent->id] ?? null;
        $actCount    = (int) ($act->n ?? 0);
        $talkSeconds = (int) ($act->seconds ?? 0);

        $bookings    = $agentLeads->where('status', 'booking')->count();
        $bookedValue = (float) $agentLeads->where('status', 'booking')->sum('booking_amount');
        $brokerage   = (float) $agentLeads->where('status', 'booking')->sum('brokerage_amount');

        // ---- Compliance score (0-100) ----
        // Weighted average of available components. If NONE of the
        // components can be computed (agent had no leads and no followups
        // in the period), the score is null — not 100. An agent who did
        // nothing is neither compliant nor non-compliant; they're unrated.
        $components = [
            'response_sla_pct'  => $totalAssigned > 0 ? ($within15 / $totalAssigned) * 100 : null,
            'on_time_pct'       => $onTimePct,
            'overdue_ratio'     => $fupTotal > 0 ? ($overdueNow / $fupTotal) * 100 : null,
            'activity_per_lead' => $totalAssigned > 0 ? $actCount / $totalAssigned : null,
        ];

        $nonNull = array_filter($components, fn ($v) => $v !== null);

        if (empty($nonNull)) {
            $score = null;
            $grade = '—';
        } else {
            $score = $this->computeScore($components);
            $grade = $this->grade($score);
        }

        $out[] = [
            'agent_id'        => $agent->id,
            'name'            => $agent->user?->name ?? ('Agent #' . $agent->id),
            'team'            => $agent->teams->first()->name ?? '—',
            'leads_assigned'  => $totalAssigned,
            'responded'       => $responded,
            'never_responded' => $never,
            'avg_response'    => $avgResponse,
            'within_15'       => $totalAssigned > 0 ? round($within15 / $totalAssigned * 100, 1) : 0,
            'within_60'       => $totalAssigned > 0 ? round($within60 / $totalAssigned * 100, 1) : 0,
            'fup_total'       => $fupTotal,
            'fup_on_time'     => $doneOnTime,
            'fup_late'        => $doneLate,
            'fup_overdue'     => $overdueNow,
            'fup_escalated'   => $escalated,
            'on_time_pct'     => $onTimePct,
            'activities'      => $actCount,
            'talk_minutes'    => round($talkSeconds / 60, 1),
            'bookings'        => $bookings,
            'booked_value'    => $bookedValue,
            'brokerage'       => $brokerage,
            'current_load'    => $agent->current_load,
            'max_load'        => $agent->max_daily_leads,
            'score'           => $score,
            'grade'           => $grade,
        ];
    }

    // Sorting is handled by the caller (controller's sortRows()).
    // The service returns rows in agent-ID order for predictable defaults.

    return $out;
}

    /* ============================================================
       TEAM SCORECARD
       ============================================================ */
    public function teamScorecard(array $teamIds, Carbon $from, Carbon $to): array
    {
        if (empty($teamIds)) return [];

        $teams = Team::with('manager')->whereIn('id', $teamIds)->orderBy('name')->get();
        $out   = [];

        foreach ($teams as $team) {
            $agentIds = TeamMember::where('team_id', $team->id)
                ->where('is_active', true)
                ->pluck('agent_id')
                ->unique()
                ->values()
                ->all();

            if (empty($agentIds)) {
                $out[] = [
                    'team_id' => $team->id, 'name' => $team->name,
                    'manager' => $team->manager?->name ?? '—',
                    'members' => 0, 'leads' => 0, 'avg_response' => null,
                    'within_15' => 0, 'on_time_pct' => null,
                    'overdue' => 0, 'bookings' => 0, 'brokerage' => 0,
                    'score' => null, 'grade' => '—',
                ];
                continue;
            }

            $rows = $this->agentScorecard($agentIds, $from, $to);
            $n = max(1, count($rows));

            $sum = function (string $key) use ($rows) {
                return array_sum(array_map(fn ($r) => is_numeric($r[$key] ?? null) ? $r[$key] : 0, $rows));
            };
            $avg = function (string $key) use ($rows) {
                $vals = array_filter(array_map(fn ($r) => $r[$key], $rows), fn ($v) => $v !== null);
                return count($vals) ? round(array_sum($vals) / count($vals), 1) : null;
            };

            $out[] = [
                'team_id'      => $team->id,
                'name'         => $team->name,
                'manager'      => $team->manager?->name ?? '—',
                'members'      => count($rows),
                'leads'        => $sum('leads_assigned'),
                'avg_response' => $avg('avg_response'),
                'within_15'    => round(array_sum(array_map(fn ($r) => $r['within_15'], $rows)) / $n, 1),
                'on_time_pct'  => $avg('on_time_pct'),
                'overdue'      => $sum('fup_overdue'),
                'escalated'    => $sum('fup_escalated'),
                'bookings'     => $sum('bookings'),
                'brokerage'    => $sum('brokerage'),
                'activities'   => $sum('activities'),
                'score'        => $avg('score'),
                'grade'        => $this->grade($avg('score')),
            ];
        }

        usort($out, fn ($a, $b) => ($b['score'] ?? -1) <=> ($a['score'] ?? -1));

        return $out;
    }

    /* ============================================================
       CRM HEALTH
       ============================================================ */
    public function crmHealth(Carbon $from, Carbon $to): array
    {
        $sla = $this->slaMinutes();

        /* --- Total leads in period --- */
        $totalLeads = Lead::whereBetween('created_at', [$from, $to])->count();

        /* --- Unassigned (right now) --- */
        $unassignedNow = Lead::whereNull('agent_id')
            ->whereNotIn('status', ['booking', 'lost'])
            ->count();
        $oldestUnassigned = Lead::whereNull('agent_id')
            ->whereNotIn('status', ['booking', 'lost'])
            ->orderBy('created_at')
            ->first(['id', 'customer_name', 'created_at']);

        /* --- Response-time distribution --- */
        $assignedLeads = Lead::whereNotNull('assigned_at')
            ->whereBetween('assigned_at', [$from, $to])
            ->get(['id', 'assigned_at']);

        $leadIds = $assignedLeads->pluck('id')->all();
        $firstActivity = [];
        if (! empty($leadIds)) {
            $rows = DB::table('activities')
                ->whereIn('lead_id', $leadIds)
                ->selectRaw('lead_id, MIN(logged_at) AS first_at')
                ->groupBy('lead_id')
                ->get();
            foreach ($rows as $r) $firstActivity[$r->lead_id] = $r->first_at;
        }

        $within15 = $within60 = $within240 = $never = 0;
        $total = 0;
        foreach ($assignedLeads as $l) {
            $total++;
            $firstAt = $firstActivity[$l->id] ?? null;
            if (! $firstAt) { $never++; continue; }
            $assigned = Carbon::parse($l->assigned_at);
            $first    = Carbon::parse($firstAt);
            if ($first->lt($assigned)) continue;
            $mins = $assigned->diffInMinutes($first);
            if ($mins <= $sla) $within15++;
            elseif ($mins <= 60) $within60++;
            elseif ($mins <= 240) $within240++;
        }

        $slaPct = $total > 0 ? round($within15 / $total * 100, 1) : 0;

        /* --- Followups right now --- */
        $overdueNow = Followup::where('status', 'pending')
            ->where('scheduled_for', '<', now())
            ->count();
        $escalatedNow = Followup::where('status', 'pending')
            ->where('escalated_flag', 1)
            ->count();

        /* --- Funnel: current status counts --- */
        $statusCounts = Lead::select('status', DB::raw('COUNT(*) AS n'))
            ->whereBetween('created_at', [$from, $to])
            ->groupBy('status')
            ->pluck('n', 'status')
            ->toArray();

        $booked = $statusCounts['booking'] ?? 0;
        $lost   = $statusCounts['lost'] ?? 0;

        /* --- Top / bottom agents --- */
        $allAgentIds = Agent::pluck('id')->all();
        $scorecard   = $this->agentScorecard($allAgentIds, $from, $to);
        $scored      = array_filter($scorecard, fn ($r) => $r['score'] !== null);
        usort($scored, fn ($a, $b) => $b['score'] <=> $a['score']);
        $top    = array_slice($scored, 0, 5);
        $bottom = array_slice(array_reverse($scored), 0, 5);

        /* --- Source ROI in period --- */
        $sourceRows = DB::table('leads')
            ->whereBetween('created_at', [$from, $to])
            ->selectRaw("
                source,
                COUNT(*) AS total,
                SUM(CASE WHEN status='booking' THEN 1 ELSE 0 END) AS booked,
                SUM(CASE WHEN status='booking' THEN booking_amount ELSE 0 END) AS value,
                SUM(CASE WHEN status='booking' THEN brokerage_amount ELSE 0 END) AS brokerage
            ")
            ->groupBy('source')
            ->orderByDesc('total')
            ->get();

        return [
            'total_leads'         => $totalLeads,
            'assigned_leads'      => $total,
            'sla_minutes'         => $sla,
            'within_sla'          => $within15,
            'within_60'           => $within60,
            'within_4h'           => $within240,
            'never_responded'     => $never,
            'sla_pct'             => $slaPct,
            'booked'              => $booked,
            'lost'                => $lost,
            'conv_pct'            => $totalLeads > 0 ? round($booked / $totalLeads * 100, 1) : 0,
            'unassigned_now'      => $unassignedNow,
            'oldest_unassigned'   => $oldestUnassigned,
            'overdue_now'         => $overdueNow,
            'escalated_now'       => $escalatedNow,
            'status_counts'       => $statusCounts,
            'top_agents'          => $top,
            'bottom_agents'       => $bottom,
            'source_rows'         => $sourceRows,
        ];
    }

    /* ============================================================
       SCORING HELPERS
       ============================================================ */
    private function computeScore(array $c): ?float
    {
        // Each component is 0-100 or null (skip)
        $parts = [];
        $weights = [
            'response_sla_pct'  => 0.40,
            'on_time_pct'       => 0.30,
            'overdue_ratio'     => 0.20,
            'activity_per_lead' => 0.10,
        ];

        foreach ($weights as $key => $w) {
            $val = $c[$key] ?? null;
            if ($val === null) continue;

            if ($key === 'overdue_ratio') {
                $val = max(0, 100 - $val); // invert: fewer overdue = better
            } elseif ($key === 'activity_per_lead') {
                // 4+ activities per lead = 100, 0 = 0
                $val = min(100, $val * 25);
            }

            $parts[] = [$val, $w];
        }

        if (empty($parts)) return null;

        $sumW = array_sum(array_column($parts, 1));
        $sumV = array_sum(array_map(fn ($p) => $p[0] * $p[1], $parts));

        return $sumW > 0 ? round($sumV / $sumW, 1) : null;
    }

    private function grade(?float $score): string
    {
        if ($score === null) return '—';
        if ($score >= 85) return 'A';
        if ($score >= 70) return 'B';
        if ($score >= 55) return 'C';
        if ($score >= 40) return 'D';
        return 'F';
    }
}