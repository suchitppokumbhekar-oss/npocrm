@extends('layouts.app')

@section('title', $screenTitle . ' — NPO CRM')

@section('content')
<a href="{{ url('/') }}" class="back-link">← Back to dashboard</a>

<div class="card">
    <div class="section-head">
        <div>
            <h2 style="margin:0;">{{ $screenTitle }}</h2>
            <p class="muted" style="margin:5px 0 0;font-size:13px;">
                See who owns the Contact work, what still needs attention, and how many Contacts have become Leads.
            </p>
        </div>
        <div style="display:flex;gap:8px;flex-wrap:wrap;">
            <a href="{{ route('contacts.index') }}" class="btn-small">📇 Contacts</a>
            <a href="{{ route('contacts.dialer') }}" class="btn-small btn-info">📞 Caller Work</a>
        </div>
    </div>
</div>

<div class="ops-cards">
    <div class="card ops-stat"><span class="muted">Contacts assigned</span><strong>{{ number_format($summary['total']) }}</strong></div>
    <div class="card ops-stat"><span class="muted">Active Contact work</span><strong>{{ number_format($summary['active']) }}</strong></div>
    <div class="card ops-stat"><span class="muted">Converted to Leads</span><strong>{{ number_format($summary['converted']) }}</strong></div>
    <div class="card ops-stat"><span class="muted">Converted today</span><strong>{{ number_format($summary['converted_today']) }}</strong></div>
    <div class="card ops-stat"><span class="muted">Work needing attention</span><strong>{{ number_format($summary['needs_work']) }}</strong></div>
</div>

<div class="card" style="padding-bottom:8px;">
    <div class="section-head" style="margin-bottom:10px;">
        <div>
            <h3 style="margin:0;">Caller workload</h3>
            <p class="muted" style="margin:4px 0 0;font-size:12px;">Converted Contacts are counted here for management visibility, but they are not part of the caller's active work queue.</p>
        </div>
        <span class="badge purple">{{ $rows->count() }} caller{{ $rows->count() === 1 ? '' : 's' }} in scope</span>
    </div>

    @if ($rows->isEmpty())
        <p class="muted">No callers are currently inside your permitted scope.</p>
    @else
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Caller</th>
                        <th>Team / scope</th>
                        <th>Active work</th>
                        <th>Needs attention</th>
                        <th>Converted to Leads</th>
                        <th>Conversion</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                @foreach ($rows as $row)
                    <tr>
                        <td>
                            <strong>{{ $row['name'] }}</strong>
                            <div class="muted" style="font-size:11px;">{{ number_format($row['total']) }} assigned total</div>
                        </td>
                        <td>
                            @if ($row['teams'])
                                @foreach ($row['teams'] as $team)
                                    <span class="badge purple" style="font-size:10px;margin:2px 2px 2px 0;">{{ $team }}</span>
                                @endforeach
                            @else
                                <span class="muted">Direct / ungrouped</span>
                            @endif
                        </td>
                        <td><strong>{{ number_format($row['active']) }}</strong></td>
                        <td>
                            <strong>{{ number_format($row['needs_work']) }}</strong>
                            @if ($row['unfinished'] || $row['followups'])
                                <div class="muted" style="font-size:10px;">{{ $row['unfinished'] }} unfinished · {{ $row['followups'] }} follow-up{{ $row['followups'] === 1 ? '' : 's' }}</div>
                            @endif
                        </td>
                        <td>
                            <strong>{{ number_format($row['converted']) }}</strong>
                            @if ($row['converted_today'])
                                <div class="muted" style="font-size:10px;">+{{ $row['converted_today'] }} today</div>
                            @endif
                        </td>
                        <td>{{ number_format($row['conversion_rate'], 1) }}%</td>
                        <td style="white-space:nowrap;">
                            <a class="btn-small btn-info" href="{{ route('contacts.index', ['agent_id' => $row['agent_id']]) }}">View Contacts</a>
                            <a class="btn-small" href="{{ route('contacts.index', ['agent_id' => $row['agent_id'], 'view' => 'converted']) }}">Converted</a>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>

<style>
.ops-cards{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:10px;margin:12px 0}.ops-stat{padding:14px}.ops-stat span{display:block;font-size:11px}.ops-stat strong{display:block;font-size:24px;margin-top:5px}
@media(max-width:1000px){.ops-cards{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media(max-width:600px){.ops-cards{grid-template-columns:1fr 1fr}.ops-stat strong{font-size:20px}.table-wrap{overflow-x:auto}.table-wrap table{min-width:900px}}
</style>
@endsection
