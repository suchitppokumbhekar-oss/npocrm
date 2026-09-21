@extends('layouts.app')

@section('title', 'Super Admin — Work Summary')

@section('content')
<div class="container" style="max-width:1400px;margin:0 auto;padding:16px;">
    @include('partials.reports-tabs', ['currentReport' => 'work-summary'])
    <div class="card" style="margin-bottom:14px;">
        <div style="display:flex;justify-content:space-between;gap:12px;align-items:center;flex-wrap:wrap;">
            <div>
                <h2 style="margin:0;">👤 Who Did What</h2>
                <p class="muted" style="margin:5px 0 0;">Super Admin operational work from CRM records. Every count drills into the underlying leads/tasks.</p>
            </div>
            <div style="display:flex;gap:6px;flex-wrap:wrap;">
                @foreach(['today'=>'Today','week'=>'This week','month'=>'This month'] as $key=>$label)
                    <a href="{{ url('/admin/work-summary?period='.$key) }}" class="btn-small {{ $period === $key ? 'btn-primary' : 'btn-ghost' }}">{{ $label }}</a>
                @endforeach
            </div>
        </div>
        <div class="muted" style="font-size:12px;margin-top:8px;">{{ $from->format('d M Y, h:i A') }} → {{ $to->format('d M Y, h:i A') }}</div>
    </div>

    <div class="card" style="overflow:auto;">
        <table style="width:100%;border-collapse:collapse;min-width:1280px;">
            <thead>
                <tr>
                    <th style="text-align:left;padding:10px;border-bottom:1px solid #e5e7eb;position:sticky;left:0;background:#fff;">Agent</th>
                    @foreach($metrics as $metric=>$label)
                        <th style="padding:10px;border-bottom:1px solid #e5e7eb;text-align:center;white-space:nowrap;">{{ $label }}</th>
                    @endforeach
                    <th style="padding:10px;border-bottom:1px solid #e5e7eb;text-align:center;white-space:nowrap;">Reconciliation</th>
                </tr>
            </thead>
            <tbody>
            @foreach($summary as $row)
                <tr>
                    <td style="padding:10px;border-bottom:1px solid #f1f5f9;position:sticky;left:0;background:#fff;"><strong>{{ $row->name }}</strong></td>
                    @foreach($metrics as $metric=>$label)
                        @php $count = $row->{$metric}; @endphp
                        <td style="padding:10px;border-bottom:1px solid #f1f5f9;text-align:center;">
                            @if($count > 0)
                                <a href="{{ url('/admin/work-summary/'.$row->agent_id.'/'.$metric.'?period='.$period) }}" style="font-weight:700;text-decoration:none;">{{ number_format($count) }}</a>
                            @else
                                <span class="muted">0</span>
                            @endif
                        </td>
                    @endforeach
                    <td style="padding:10px;border-bottom:1px solid #f1f5f9;text-align:center;white-space:nowrap;">
                        <a href="{{ url('/admin/work-summary/reconciliation/'.$row->agent_id.'?period='.$period) }}" class="btn-small btn-ghost">🔎 Reconcile</a>
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>

    @if(!empty($reconciliation))
        <div class="card" style="margin-top:14px;">
            <div style="display:flex;justify-content:space-between;gap:12px;align-items:flex-start;flex-wrap:wrap;">
                <div>
                    <h3 style="margin:0;">🔎 CRM Reconciliation · {{ $reconciliation['agent']->user?->name ?? 'Agent' }}</h3>
                    <div class="muted" style="font-size:12px;margin-top:4px;">Read-only comparison for {{ $period }}: report definitions vs raw CRM history, plus Dashboard vs Agent Performance pipeline scope.</div>
                </div>
                <a href="{{ url('/admin/work-summary/'.$reconciliation['agent']->id.'/new_leads?period='.$period) }}" class="btn-small btn-ghost">View lead history</a>
            </div>

            <div class="table-wrap" style="margin-top:12px;">
                <table>
                    <thead><tr><th>Metric</th><th style="text-align:right;">Report</th><th style="text-align:right;">Raw CRM</th><th style="text-align:right;">Difference</th><th>Why</th></tr></thead>
                    <tbody>
                    @foreach($reconciliation['metrics'] as $m)
                        <tr>
                            <td><strong>{{ $m['label'] }}</strong></td>
                            <td style="text-align:right;">{{ number_format($m['report_count']) }}</td>
                            <td style="text-align:right;">{{ number_format($m['raw_count']) }}</td>
                            <td style="text-align:right;">{{ $m['difference'] > 0 ? '+' : '' }}{{ number_format($m['difference']) }}</td>
                            <td class="muted" style="font-size:12px;">{{ $m['reason'] }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>

            <h4 style="margin:18px 0 8px;">Pipeline / ownership difference</h4>
            <p class="muted" style="font-size:12px;">{{ $reconciliation['scope_reason'] }}</p>
            <div class="table-wrap">
                <table>
                    <thead><tr><th>Status</th><th style="text-align:right;">Dashboard</th><th style="text-align:right;">Agent report</th><th style="text-align:right;">Difference</th><th>Why</th></tr></thead>
                    <tbody>
                    @foreach($reconciliation['pipeline'] as $p)
                        <tr><td><strong>{{ ucfirst(str_replace('_',' ',$p['status'])) }}</strong></td><td style="text-align:right;">{{ $p['dashboard_count'] }}</td><td style="text-align:right;">{{ $p['agent_report_count'] }}</td><td style="text-align:right;">{{ $p['difference'] > 0 ? '+' : '' }}{{ $p['difference'] }}</td><td class="muted" style="font-size:12px;">{{ $p['reason'] }}</td></tr>
                    @endforeach
                    </tbody>
                </table>
            </div>

            @if($reconciliation['dashboard_only_count'] || $reconciliation['primary_only_count'])
                <div style="display:flex;gap:12px;flex-wrap:wrap;margin-top:12px;">
                    <a href="#dashboard-only-leads" class="btn-small btn-ghost">Dashboard-only {{ $reconciliation['dashboard_only_count'] }}</a>
                    <a href="#primary-only-leads" class="btn-small btn-ghost">Primary-only {{ $reconciliation['primary_only_count'] }}</a>
                </div>
                @if($reconciliation['dashboard_only_count'])
                    <div id="dashboard-only-leads" style="margin-top:12px;"><strong>Dashboard-only leads</strong>
                        @foreach($reconciliation['dashboard_only_leads'] as $lead)
                            <a href="{{ url('/leads/'.$lead->lead_id.'?return_to='.urlencode(url()->full())) }}" style="display:block;padding:8px 4px;border-bottom:1px solid #eef2f7;text-decoration:none;"><strong>{{ $lead->name }}</strong>@if($lead->project)<span class="muted"> · {{ $lead->project }}</span>@endif <span class="muted" style="font-size:11px;"> · {{ $lead->detail }}</span></a>
                        @endforeach
                    </div>
                @endif
                @if($reconciliation['primary_only_count'])
                    <div id="primary-only-leads" style="margin-top:12px;"><strong>Primary-pointer-only leads</strong>
                        @foreach($reconciliation['primary_only_leads'] as $lead)
                            <a href="{{ url('/leads/'.$lead->lead_id.'?return_to='.urlencode(url()->full())) }}" style="display:block;padding:8px 4px;border-bottom:1px solid #eef2f7;text-decoration:none;"><strong>{{ $lead->name }}</strong>@if($lead->project)<span class="muted"> · {{ $lead->project }}</span>@endif <span class="muted" style="font-size:11px;"> · {{ $lead->detail }}</span></a>
                        @endforeach
                    </div>
                @endif
            @else
                <div class="muted" style="font-size:12px;margin-top:10px;">The Dashboard active lead population and Agent Performance primary-pointer population are identical for this agent.</div>
            @endif
        </div>
    @endif

    <div class="card" style="margin-top:14px;">
        <strong>How work is counted</strong>
        <ul class="muted" style="margin:8px 0 0;padding-left:20px;font-size:12px;line-height:1.6;">
            <li>New leads received = primary lead assignments created during the selected period.</li>
            <li>Calls / WhatsApp / sharing = distinct leads with the corresponding CRM activity recorded by that agent.</li>
            <li>Tasks completed = follow-up tasks marked done during the selected period.</li>
            <li>Overdue and tomorrow = current pending task snapshot, independent of the selected historical period.</li>
            <li>Bookings = booking status recorded in the selected period; site visits = visits recorded for the agent in the selected period.</li>
        </ul>
    </div>
</div>
@endsection
