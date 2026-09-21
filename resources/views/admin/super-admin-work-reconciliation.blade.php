@extends('layouts.app')

@section('title', 'CRM Reconciliation')

@section('content')
<div class="container" style="max-width:1400px;margin:0 auto;padding:16px;">
    @include('partials.reports-tabs', ['currentReport' => 'work-summary'])

    <div class="card" style="margin-bottom:14px;">
        <div style="display:flex;justify-content:space-between;gap:12px;align-items:flex-start;flex-wrap:wrap;">
            <div>
                <a href="{{ url('/admin/work-summary?period='.$period) }}" style="font-size:13px;">← Back to Super Admin Work</a>
                <h2 style="margin:8px 0 4px;">🔎 CRM Reconciliation</h2>
                <h3 style="margin:0;">{{ $reconciliation['agent']->user?->name ?? 'Agent' }}</h3>
                <p class="muted" style="margin:5px 0 0;font-size:12px;">{{ $from->format('d M Y, h:i A') }} → {{ $to->format('d M Y, h:i A') }}</p>
            </div>
            <div style="display:flex;gap:6px;flex-wrap:wrap;">
                @foreach(['today'=>'Today','week'=>'This week','month'=>'This month'] as $key=>$label)
                    <a href="{{ url('/admin/work-summary/reconciliation/'.$reconciliation['agent']->id.'?period='.$key) }}" class="btn-small {{ $period === $key ? 'btn-primary' : 'btn-ghost' }}">{{ $label }}</a>
                @endforeach
            </div>
        </div>
    </div>

    <div class="card" style="overflow:auto;">
        <h3 style="margin:0 0 10px;">Metric reconciliation</h3>
        <table style="width:100%;border-collapse:collapse;min-width:850px;">
            <thead><tr><th style="text-align:left;padding:9px;border-bottom:1px solid #e5e7eb;">Metric</th><th style="text-align:right;padding:9px;border-bottom:1px solid #e5e7eb;">Report</th><th style="text-align:right;padding:9px;border-bottom:1px solid #e5e7eb;">Raw CRM</th><th style="text-align:right;padding:9px;border-bottom:1px solid #e5e7eb;">Difference</th><th style="text-align:left;padding:9px;border-bottom:1px solid #e5e7eb;">Why</th><th style="padding:9px;border-bottom:1px solid #e5e7eb;"></th></tr></thead>
            <tbody>
            @foreach($reconciliation['metrics'] as $m)
                <tr>
                    <td style="padding:9px;border-bottom:1px solid #f1f5f9;"><strong>{{ $m['label'] }}</strong></td>
                    <td style="padding:9px;text-align:right;border-bottom:1px solid #f1f5f9;">{{ number_format($m['report_count']) }}</td>
                    <td style="padding:9px;text-align:right;border-bottom:1px solid #f1f5f9;">{{ number_format($m['raw_count']) }}</td>
                    <td style="padding:9px;text-align:right;border-bottom:1px solid #f1f5f9;font-weight:700;">{{ $m['difference'] > 0 ? '+' : '' }}{{ number_format($m['difference']) }}</td>
                    <td style="padding:9px;border-bottom:1px solid #f1f5f9;font-size:12px;" class="muted">{{ $m['reason'] }}</td>
                    <td style="padding:9px;text-align:right;border-bottom:1px solid #f1f5f9;white-space:nowrap;">
                        @if(isset($reconciliation['metric_evidence'][$m['metric']]))
                            <a href="#evidence-{{ $m['metric'] }}" class="btn-small btn-ghost">Show records</a>
                        @endif
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>

    @forelse($reconciliation['metric_evidence'] as $metric => $evidence)
        <div class="card" id="evidence-{{ $metric }}" style="margin-top:14px;">
            <h3 style="margin:0;">📋 {{ $evidence['title'] }} · {{ $reconciliation['agent']->user?->name ?? 'Agent' }}</h3>
            <p class="muted" style="font-size:12px;margin:5px 0 12px;">These are the actual CRM records behind the discrepancy. “Repeat” or “Excluded” explains why a raw record does not add another report count.</p>
            <div style="overflow:auto;">
                <table style="width:100%;border-collapse:collapse;min-width:850px;">
                    <thead><tr><th style="text-align:left;padding:8px;border-bottom:1px solid #e5e7eb;">Lead</th><th style="padding:8px;border-bottom:1px solid #e5e7eb;">When</th><th style="padding:8px;border-bottom:1px solid #e5e7eb;">State</th><th style="text-align:left;padding:8px;border-bottom:1px solid #e5e7eb;">Why</th></tr></thead>
                    <tbody>
                    @foreach($evidence['rows'] as $row)
                        <tr>
                            <td style="padding:8px;border-bottom:1px solid #f1f5f9;">
                                <a href="{{ url('/leads/'.$row['lead']->lead_id.'?return_to='.urlencode(url()->full())) }}" style="text-decoration:none;"><strong>{{ $row['lead']->name }}</strong></a>
                                @if($row['lead']->project)<span class="muted"> · {{ $row['lead']->project }}</span>@endif
                                <div class="muted" style="font-size:11px;">Lead #{{ $row['lead']->lead_id }} · {{ $row['lead']->detail }}</div>
                            </td>
                            <td style="padding:8px;border-bottom:1px solid #f1f5f9;white-space:nowrap;font-size:12px;">{{ $row['at']->format('d M Y, h:i A') }}</td>
                            <td style="padding:8px;border-bottom:1px solid #f1f5f9;white-space:nowrap;"><strong>{{ $row['state'] }}</strong></td>
                            <td style="padding:8px;border-bottom:1px solid #f1f5f9;font-size:12px;" class="muted">{{ $row['reason'] }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @empty
        <div class="card" style="margin-top:14px;">
            <strong>All historical metric counts match their raw CRM records.</strong>
            <p class="muted" style="font-size:12px;margin-bottom:0;">There is no metric-level discrepancy to investigate for this period.</p>
        </div>
    @endforelse

    <div class="card" style="margin-top:14px;">
        <h3 style="margin:0 0 8px;">Pipeline / ownership reconciliation</h3>
        <p class="muted" style="font-size:12px;">{{ $reconciliation['scope_reason'] }}</p>
        <div style="overflow:auto;">
            <table style="width:100%;border-collapse:collapse;min-width:760px;">
                <thead><tr><th style="text-align:left;padding:8px;border-bottom:1px solid #e5e7eb;">Status</th><th style="text-align:right;padding:8px;border-bottom:1px solid #e5e7eb;">Dashboard</th><th style="text-align:right;padding:8px;border-bottom:1px solid #e5e7eb;">Agent report</th><th style="text-align:right;padding:8px;border-bottom:1px solid #e5e7eb;">Difference</th><th style="text-align:left;padding:8px;border-bottom:1px solid #e5e7eb;">Why</th></tr></thead>
                <tbody>
                @foreach($reconciliation['pipeline'] as $p)
                    <tr><td style="padding:8px;border-bottom:1px solid #f1f5f9;"><strong>{{ ucfirst(str_replace('_',' ',$p['status'])) }}</strong></td><td style="padding:8px;text-align:right;border-bottom:1px solid #f1f5f9;">{{ $p['dashboard_count'] }}</td><td style="padding:8px;text-align:right;border-bottom:1px solid #f1f5f9;">{{ $p['agent_report_count'] }}</td><td style="padding:8px;text-align:right;border-bottom:1px solid #f1f5f9;font-weight:700;">{{ $p['difference'] > 0 ? '+' : '' }}{{ $p['difference'] }}</td><td style="padding:8px;border-bottom:1px solid #f1f5f9;font-size:12px;" class="muted">{{ $p['reason'] }}</td></tr>
                @endforeach
                </tbody>
            </table>
        </div>
        <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:12px;">
            <a href="#dashboard-only-leads" class="btn-small btn-ghost">Dashboard-only leads: {{ $reconciliation['dashboard_only_count'] }}</a>
            <a href="#primary-only-leads" class="btn-small btn-ghost">Primary-pointer-only leads: {{ $reconciliation['primary_only_count'] }}</a>
        </div>
        @if($reconciliation['dashboard_only_count'])
            <div id="dashboard-only-leads" style="margin-top:14px;"><h4 style="margin:0 0 6px;">Dashboard-only leads</h4>
                @foreach($reconciliation['dashboard_only_leads'] as $lead)
                    <a href="{{ url('/leads/'.$lead->lead_id.'?return_to='.urlencode(url()->full())) }}" style="display:block;padding:8px 4px;border-bottom:1px solid #eef2f7;text-decoration:none;"><strong>{{ $lead->name }}</strong>@if($lead->project)<span class="muted"> · {{ $lead->project }}</span>@endif <span class="muted" style="font-size:11px;"> · {{ $lead->detail }}</span></a>
                @endforeach
            </div>
        @endif
        @if($reconciliation['primary_only_count'])
            <div id="primary-only-leads" style="margin-top:14px;"><h4 style="margin:0 0 6px;">Primary-pointer-only leads</h4>
                @foreach($reconciliation['primary_only_leads'] as $lead)
                    <a href="{{ url('/leads/'.$lead->lead_id.'?return_to='.urlencode(url()->full())) }}" style="display:block;padding:8px 4px;border-bottom:1px solid #eef2f7;text-decoration:none;"><strong>{{ $lead->name }}</strong>@if($lead->project)<span class="muted"> · {{ $lead->project }}</span>@endif <span class="muted" style="font-size:11px;"> · {{ $lead->detail }}</span></a>
                @endforeach
            </div>
        @endif
    </div>
</div>
@endsection
