@extends('layouts.app')

@section('title', 'CRM Health — NPO CRM')

@section('content')
<div class="reports-page">

    <a href="{{ url('/reports') }}" class="back-link">← Back to reports</a>

    <div class="card">
        <h2 style="margin:0 0 6px;">🩺 CRM Health</h2>
        <p class="muted" style="margin:0;font-size:13px;">
            Company-wide KPIs — SLA compliance, funnel, top performers, source ROI.
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
                <a href="{{ url('/reports/crm-health') }}" class="btn-small" style="background:transparent;color:var(--c-text);border:1.5px solid var(--c-border);">Reset</a>
            </div>
        </form>
    </div>

    {{-- SLA + headline KPIs --}}
    <div class="card">
        <h3>⏱️ Response SLA</h3>
        <div class="grid-stats">
            <x-stat-card label="🎯 Within SLA ({{ $h['sla_minutes'] }} min)" :value="$h['sla_pct'] . '%'" variant="{{ $h['sla_pct'] >= 80 ? 'green' : ($h['sla_pct'] >= 50 ? 'orange' : 'red') }}" />
            <x-stat-card label="✅ Within 15m"  :value="$h['within_sla']" variant="green" />
            <x-stat-card label="⚠️ 15–60 min"    :value="$h['within_60']" variant="orange" />
            <x-stat-card label="🕐 1–4 hours"    :value="$h['within_4h']" variant="orange" />
            <x-stat-card label="❌ Never Responded" :value="$h['never_responded']" variant="red" />
        </div>
    </div>

    {{-- Live issues --}}
    <div class="card">
        <h3>🚨 Live Issues</h3>
        <div class="grid-stats">
            <x-stat-card label="🔴 Unassigned Now" :value="$h['unassigned_now']" variant="{{ $h['unassigned_now'] > 0 ? 'red' : 'green' }}" />
            <x-stat-card label="⏰ Followups Overdue" :value="$h['overdue_now']" variant="{{ $h['overdue_now'] > 0 ? 'red' : 'green' }}" />
            <x-stat-card label="⚠️ Escalated" :value="$h['escalated_now']" variant="{{ $h['escalated_now'] > 0 ? 'orange' : 'green' }}" />
        </div>
        @if ($h['oldest_unassigned'])
            <p style="margin-top:var(--s-3);font-size:13px;">
                Oldest unassigned: <strong>{{ $h['oldest_unassigned']->customer_name }}</strong>
                · {{ \Carbon\Carbon::parse($h['oldest_unassigned']->created_at)->diffForHumans() }}
                <a href="{{ url('/leads/' . $h['oldest_unassigned']->id) }}" class="btn-small btn-info" style="margin-left:8px;">Open</a>
            </p>
        @endif
    </div>

    {{-- Funnel --}}
    <div class="card">
        <h3>🎯 Funnel ({{ $from->format('d M') }} → {{ $to->format('d M') }})</h3>
        <div class="grid-stats">
            <x-stat-card label="📋 Total Leads" :value="number_format($h['total_leads'])" />
            <x-stat-card label="🎉 Booked" :value="$h['booked'] . ' (' . $h['conv_pct'] . '%)'" variant="green" />
            <x-stat-card label="🚫 Lost" :value="$h['lost']" variant="red" />
        </div>

        @if (! empty($h['status_counts']))
            <div class="table-wrap" style="margin-top:var(--s-3);">
                <table>
                    <tr><th>Stage</th><th>Leads</th><th>% of Total</th></tr>
                    @foreach ($h['status_counts'] as $status => $count)
                        <tr>
                            <td><strong>{{ ucfirst(str_replace('_', ' ', $status)) }}</strong></td>
                            <td>{{ $count }}</td>
                            <td>{{ $h['total_leads'] > 0 ? round($count / $h['total_leads'] * 100, 1) : 0 }}%</td>
                        </tr>
                    @endforeach
                </table>
            </div>
        @endif
    </div>

    {{-- Top / bottom agents --}}
    <div class="card">
        <h3>🏆 Top Agents</h3>
        @if (empty($h['top_agents']))
            <p class="muted">Not enough data.</p>
        @else
            <div class="table-wrap">
                <table>
                    <tr><th>#</th><th>Agent</th><th>Score</th><th>Within SLA</th><th>On-Time %</th><th>Bookings</th></tr>
                    @foreach ($h['top_agents'] as $i => $r)
                        <tr>
                            <td>{{ $i + 1 }}</td>
                            <td><strong>{{ $r['name'] }}</strong></td>
                            <td><span class="badge green">{{ $r['score'] }} · {{ $r['grade'] }}</span></td>
                            <td>{{ $r['within_15'] }}%</td>
                            <td>{{ $r['on_time_pct'] !== null ? $r['on_time_pct'] . '%' : '—' }}</td>
                            <td>{{ $r['bookings'] }}</td>
                        </tr>
                    @endforeach
                </table>
            </div>
        @endif
    </div>

    <div class="card">
        <h3>📉 Needs Attention</h3>
        @if (empty($h['bottom_agents']))
            <p class="muted">Not enough data.</p>
        @else
            <div class="table-wrap">
                <table>
                    <tr><th>Agent</th><th>Score</th><th>Within SLA</th><th>Overdue</th><th>Escalated</th><th>Never Responded</th></tr>
                    @foreach ($h['bottom_agents'] as $r)
                        <tr>
                            <td><strong>{{ $r['name'] }}</strong></td>
                            <td><span class="badge {{ $r['score'] >= 55 ? 'orange' : 'red' }}">{{ $r['score'] }} · {{ $r['grade'] }}</span></td>
                            <td>{{ $r['within_15'] }}%</td>
                            <td>@if ($r['fup_overdue'] > 0)<span class="badge red">{{ $r['fup_overdue'] }}</span>@else<span class="muted">0</span>@endif</td>
                            <td>@if ($r['fup_escalated'] > 0)<span class="badge orange">{{ $r['fup_escalated'] }}</span>@else<span class="muted">0</span>@endif</td>
                            <td>@if ($r['never_responded'] > 0)<span class="badge red">{{ $r['never_responded'] }}</span>@else<span class="muted">0</span>@endif</td>
                        </tr>
                    @endforeach
                </table>
            </div>
        @endif
    </div>

    {{-- Source ROI --}}
    <div class="card">
        <h3>📥 Source ROI</h3>
        @if ($h['source_rows']->isEmpty())
            <p class="muted">No data.</p>
        @else
            <div class="table-wrap">
                <table>
                    <tr><th>Source</th><th>Leads</th><th>Booked</th><th>Conv %</th><th>Booking Value</th><th>Brokerage</th></tr>
                    @foreach ($h['source_rows'] as $row)
                        <tr>
                            <td><strong>{{ $row->source ?? 'unknown' }}</strong></td>
                            <td>{{ $row->total }}</td>
                            <td><span class="badge green">{{ $row->booked }}</span></td>
                            <td>{{ $row->total > 0 ? round($row->booked / $row->total * 100, 1) : 0 }}%</td>
                            <td>{{ inr($row->value, 0) }}</td>
                            <td><strong>{{ inr($row->brokerage, 0) }}</strong></td>
                        </tr>
                    @endforeach
                </table>
            </div>
        @endif
    </div>

</div>
@endsection