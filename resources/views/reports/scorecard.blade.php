@extends('layouts.app')

@section('title', 'Agent Scorecard — NPO CRM')

@section('content')
<div class="reports-page">

        <a href="{{ url('/reports') }}" class="back-link">← Back to reports</a>

    @include('partials.reports-tabs', ['currentReport' => 'scorecard'])
    <div class="card">
        <h2 style="margin:0 0 6px;">👤 Agent Scorecard</h2>
        <p class="muted" style="margin:0;font-size:13px;">
            Who is following the rules — response times, followup compliance, red flags.
            SLA target: <strong>{{ $slaMinutes }} min</strong> first response.
        </p>

        <form method="GET" style="margin-top:var(--s-3);" class="flex">
            <div class="flex-item">
                <label>From</label>
                <input type="date" name="from" class="input" value="{{ $from->toDateString() }}">
            </div>
            <div class="flex-item">
                <label>To</label>
                <input type="date" name="to" class="input" value="{{ $to->toDateString() }}">
            </div>
            <div class="flex-item">
                <label>Team</label>
                <select name="team_id" class="input">
                    <option value="">All teams</option>
                    @foreach ($teams as $t)
                        <option value="{{ $t->id }}" @selected((string) $teamFilter === (string) $t->id)>
                            {{ $t->name }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div class="flex-item" style="align-self:flex-end;">
                <button type="submit" class="btn-small btn-info">Apply</button>
                <a href="{{ url('/reports/scorecard') }}" class="btn-small"
                   style="background:transparent;color:var(--c-text);border:1.5px solid var(--c-border);">
                    Reset
                </a>
            </div>
        </form>
    </div>

    {{-- ============================================================ --}}
    {{-- SUMMARY STATS (based on FULL set, not just current page)     --}}
    {{-- ============================================================ --}}
    @php
        // Use the full unpaginated dataset for stats when available;
        // fall back to $rows for compatibility.
        $stats = $fullRows ?? (is_array($rows) ? $rows : $rows->items());

        $n         = count($stats);
        $withScore = array_filter($stats, fn ($r) => $r['score'] !== null);

        $avgScore = count($withScore) > 0
            ? round(array_sum(array_map(fn ($r) => $r['score'], $withScore)) / count($withScore), 1)
            : 0;

        $totalOverdue = array_sum(array_map(fn ($r) => $r['fup_overdue'], $stats));
        $totalLeads   = array_sum(array_map(fn ($r) => $r['leads_assigned'], $stats));
        $compliant    = count(array_filter($stats, fn ($r) => $r['score'] !== null && $r['score'] >= 70));
    @endphp

    <div class="card">
        <div class="grid-stats">
            <x-stat-card label="👤 Agents" :value="$n" />
            <x-stat-card label="✅ Above 70" :value="$compliant . ' / ' . $n"
                         variant="{{ $n > 0 && $compliant === $n ? 'green' : 'orange' }}" />
            <x-stat-card label="📊 Avg Score" :value="$avgScore . '%'"
                         variant="{{ $avgScore >= 70 ? 'green' : ($avgScore >= 50 ? 'orange' : 'red') }}" />
            <x-stat-card label="⚠️ Overdue Now" :value="$totalOverdue"
                         variant="{{ $totalOverdue > 0 ? 'red' : 'green' }}" />
            <x-stat-card label="📋 Leads in Period" :value="number_format($totalLeads)" />
        </div>
    </div>

    {{-- ============================================================ --}}
    {{-- SCORECARD TABLE --}}
    {{-- ============================================================ --}}
    <div class="card">
        <h3>📋 Scorecard</h3>

        @if ($rows->isEmpty())
            <p class="muted">No agents match.</p>
        @else
            <div class="table-wrap">
                <table>
                    <tr>
    <x-sortable-th label="Agent"              field="name" />
    <x-sortable-th label="Team"               field="team" />
    <x-sortable-th label="Leads"              field="leads_assigned"  align="right" />
    <x-sortable-th label="Avg Response"       field="avg_response"    align="right" />
    <x-sortable-th label="Within {{ $slaMinutes }}m" field="within_15" align="right" />
    <x-sortable-th label="Never Responded"    field="never_responded" align="right" />
    <x-sortable-th label="On-Time %"          field="on_time_pct"     align="right" />
    <x-sortable-th label="Overdue"            field="fup_overdue"     align="right" />
    <x-sortable-th label="Escalated"          field="fup_escalated"   align="right" />
    <x-sortable-th label="Activities"         field="activities"      align="right" />
    <x-sortable-th label="Talk (min)"         field="talk_minutes"    align="right" />
    <x-sortable-th label="Bookings"           field="bookings"        align="right" />
    <x-sortable-th label="Brokerage"          field="brokerage"       align="right" />
    <x-sortable-th label="Score"              field="score"           align="right" />
</tr>
                    @foreach ($rows as $r)
                        @php
                            $scoreColor = $r['score'] === null ? 'blue'
                                : ($r['score'] >= 85 ? 'green'
                                : ($r['score'] >= 70 ? 'green'
                                : ($r['score'] >= 55 ? 'orange' : 'red')));
                        @endphp
                        <tr>
                            <td><strong>{{ $r['name'] }}</strong></td>
                            <td><span class="muted" style="font-size:12px;">{{ $r['team'] }}</span></td>
                            <td>{{ $r['leads_assigned'] }}</td>
                            <td>
                                @if ($r['avg_response'] !== null)
                                    <strong style="color:{{ $r['avg_response'] <= $slaMinutes ? 'var(--c-primary)' : 'var(--c-danger)' }};">
                                        {{ $r['avg_response'] }} min
                                    </strong>
                                @else
                                    <span class="muted">—</span>
                                @endif
                            </td>
                            <td>{{ $r['within_15'] }}%</td>
                            <td>
                                @if ($r['never_responded'] > 0)
                                    <span class="badge red">{{ $r['never_responded'] }}</span>
                                @else
                                    <span class="muted">0</span>
                                @endif
                            </td>
                            <td>{{ $r['on_time_pct'] !== null ? $r['on_time_pct'] . '%' : '—' }}</td>
                            <td>
                                @if ($r['fup_overdue'] > 0)
                                    <span class="badge red">{{ $r['fup_overdue'] }}</span>
                                @else
                                    <span class="muted">0</span>
                                @endif
                            </td>
                            <td>
                                @if ($r['fup_escalated'] > 0)
                                    <span class="badge orange">{{ $r['fup_escalated'] }}</span>
                                @else
                                    <span class="muted">0</span>
                                @endif
                            </td>
                            <td>{{ $r['activities'] }}</td>
                            <td>{{ $r['talk_minutes'] }}</td>
                            <td><span class="badge green">{{ $r['bookings'] }}</span></td>
                            <td>{{ inr($r['brokerage'], 0) }}</td>
                            <td>
                                <span class="badge {{ $scoreColor }}">
                                    {{ $r['score'] !== null ? $r['score'] : '—' }}
                                    @if ($r['grade'] !== '—') · {{ $r['grade'] }} @endif
                                </span>
                            </td>
                        </tr>
                    @endforeach
                </table>
            </div>

            @include('partials.pagination', ['paginator' => $rows])
        @endif
    </div>

</div>
@endsection