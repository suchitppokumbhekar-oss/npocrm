@extends('layouts.app')

@section('title', 'Telecalling Report — NPO CRM')

@section('content')
<div class="reports-page">

    <a href="{{ url('/reports') }}" class="back-link">← Back to reports</a>

    <div class="card">
        <h2 style="margin:0 0 6px;">📞 Telecalling Report</h2>
        <p class="muted" style="margin:0;font-size:13px;">
            Call activity per agent — dials, connects, talk time, outcomes.
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
                        <option value="{{ $t->id }}" @selected((string)$teamFilter === (string)$t->id)>
                            {{ $t->name }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div class="flex-item" style="align-self:flex-end;">
                <button type="submit" class="btn-small btn-info">Apply</button>
                <a href="{{ url('/reports/telecalling') }}" class="btn-small"
                   style="background:transparent;color:var(--c-text);border:1.5px solid var(--c-border);">Reset</a>
            </div>
        </form>
    </div>

    <div class="card">
        <div class="grid-stats">
            <x-stat-card label="📞 Total Calls" :value="number_format($totals['calls'])" />
            <x-stat-card label="✅ Connected" :value="number_format($totals['connected'])" variant="green" />
            <x-stat-card label="📊 Connect Rate" :value="$totals['connect_rate'] . '%'"
                         variant="{{ $totals['connect_rate'] >= 50 ? 'green' : ($totals['connect_rate'] >= 25 ? 'orange' : 'red') }}" />
            <x-stat-card label="⏱️ Talk Time" :value="$totals['talk_minutes'] . ' min'" />
            <x-stat-card label="👤 Agents" :value="$totals['agents']" />
        </div>
    </div>

    <div class="card">
        <h3>👥 Agent Breakdown</h3>
        @if (empty($stats))
            <p class="muted">No calls logged in this period.</p>
        @else
            <div class="table-wrap">
                <table>
                    <tr>
                        <th>Agent</th>
                        <th>Team</th>
                        <th>Total Dials</th>
                        <th>Connected</th>
                        <th>Not Connected</th>
                        <th>Connect %</th>
                        <th>Talk (min)</th>
                        <th>Avg Talk</th>
                        <th>Unique People</th>
                    </tr>
                    @foreach ($stats as $r)
                        <tr>
                            <td><strong>{{ $r['name'] }}</strong></td>
                            <td><span class="muted" style="font-size:12px;">{{ $r['team'] }}</span></td>
                            <td>{{ $r['total_calls'] }}</td>
                            <td><span class="badge green">{{ $r['connected_calls'] }}</span></td>
                            <td><span class="badge red">{{ $r['not_connected'] }}</span></td>
                            <td><strong>{{ $r['connect_rate'] }}%</strong></td>
                            <td>{{ $r['talk_minutes'] }}</td>
                            <td>{{ $r['avg_talk_seconds'] }}s</td>
                            <td>{{ $r['unique_people'] }}</td>
                        </tr>
                    @endforeach
                </table>
            </div>
        @endif
    </div>

    <div class="card">
        <h3>📊 Outcome Breakdown</h3>
        @if (empty($outcomes))
            <p class="muted">No outcomes recorded.</p>
        @else
            <div class="table-wrap">
                <table>
                    <tr><th>Outcome</th><th>Calls</th></tr>
                    @foreach ($outcomes as $o)
                        <tr>
                            <td>{{ $o['label'] }}</td>
                            <td><strong>{{ $o['count'] }}</strong></td>
                        </tr>
                    @endforeach
                </table>
            </div>
        @endif
    </div>

</div>
@endsection