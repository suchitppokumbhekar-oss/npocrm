@extends('layouts.app')

@section('title', 'Team Performance — NPO CRM')

@section('content')
<div class="reports-page">

    <a href="{{ url('/reports') }}" class="back-link">← Back to reports</a>

    <div class="card">
        <h2 style="margin:0 0 6px;">👥 Team Performance</h2>
        <p class="muted" style="margin:0;font-size:13px;">
            Response time, followup compliance, and results per team.
            @unless ($isAdmin) Showing your team(s) only. @endunless
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
            <div class="flex-item" style="align-self:flex-end;">
                <button type="submit" class="btn-small btn-info">Apply</button>
                <a href="{{ url('/reports/team') }}" class="btn-small" style="background:transparent;color:var(--c-text);border:1.5px solid var(--c-border);">Reset</a>
            </div>
        </form>
    </div>

    @php
        $totalTeams = count($rows);
        $avgScore   = $totalTeams > 0
            ? round(array_sum(array_map(fn ($r) => $r['score'] ?? 0, $rows)) / $totalTeams, 1)
            : 0;
        $avgResponse = $totalTeams > 0
            ? round(array_sum(array_filter(array_map(fn ($r) => $r['avg_response'], $rows), fn($v) => $v !== null)) /
                max(1, count(array_filter(array_map(fn ($r) => $r['avg_response'], $rows), fn($v) => $v !== null))), 1)
            : null;
    @endphp

    <div class="card">
        <div class="grid-stats">
            <x-stat-card label="👥 Teams" :value="$totalTeams" />
            <x-stat-card label="📊 Avg Compliance" :value="$avgScore . '%'" variant="{{ $avgScore >= 70 ? 'green' : ($avgScore >= 50 ? 'orange' : 'red') }}" />
            <x-stat-card label="⏱️ Avg First Response" :value="$avgResponse !== null ? $avgResponse . ' min' : '—'" variant="{{ $avgResponse !== null && $avgResponse <= 15 ? 'green' : 'orange' }}" />
        </div>
    </div>

    <div class="card">
        <h3>📋 Team Breakdown</h3>

        @if (empty($rows))
            <p class="muted">No teams match.</p>
        @else
            <div class="table-wrap">
                <table>
                    <tr>
                        <th>Team</th>
                        <th>Manager</th>
                        <th>Members</th>
                        <th>Leads</th>
                        <th>Avg Response</th>
                        <th>Within SLA</th>
                        <th>On-Time %</th>
                        <th>Overdue</th>
                        <th>Escalated</th>
                        <th>Bookings</th>
                        <th>Brokerage</th>
                        <th>Score</th>
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
                            <td>{{ $r['manager'] }}</td>
                            <td>{{ $r['members'] }}</td>
                            <td>{{ number_format($r['leads']) }}</td>
                            <td>{{ $r['avg_response'] !== null ? $r['avg_response'] . ' min' : '—' }}</td>
                            <td>{{ $r['within_15'] }}%</td>
                            <td>{{ $r['on_time_pct'] !== null ? $r['on_time_pct'] . '%' : '—' }}</td>
                            <td>
                                @if ($r['overdue'] > 0)
                                    <span class="badge red">{{ $r['overdue'] }}</span>
                                @else
                                    <span class="muted">0</span>
                                @endif
                            </td>
                            <td>
                                @if ($r['escalated'] > 0)
                                    <span class="badge orange">{{ $r['escalated'] }}</span>
                                @else
                                    <span class="muted">0</span>
                                @endif
                            </td>
                            <td><span class="badge green">{{ $r['bookings'] }}</span></td>
                            <td><strong>{{ inr($r['brokerage'], 0) }}</strong></td>
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
        @endif
    </div>

</div>
@endsection