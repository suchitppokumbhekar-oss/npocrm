@extends('layouts.app')

@section('title', 'System Health — NPO CRM')

@section('content')
    <a href="{{ url('/settings') }}" class="back-link">← Back to Settings</a>

    <div class="card">
        <div class="section-head">
            <div>
                <h2 style="margin:0;">🛡️ NPO CRM System Health</h2>
                <p class="muted" style="margin-top:5px;">Production safety and environment checks. This page is read-only.</p>
            </div>
            <div style="text-align:right;">
                <strong>Version {{ $release['version'] }}</strong>
                <div class="muted">{{ $release['date'] }}</div>
            </div>
        </div>

        <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:14px;">
            <span style="background:var(--c-primary);color:#fff;padding:6px 10px;border-radius:999px;font-size:12px;font-weight:700;">{{ $passCount }} PASS</span>
            @if($failCount > 0)
                <span style="background:#c0392b;color:#fff;padding:6px 10px;border-radius:999px;font-size:12px;font-weight:700;">{{ $failCount }} NEEDS ATTENTION</span>
            @else
                <span style="background:#2980b9;color:#fff;padding:6px 10px;border-radius:999px;font-size:12px;font-weight:700;">SYSTEM CHECK COMPLETE</span>
            @endif
        </div>
    </div>

    <div class="card">
        <div class="section-head"><h3>Release</h3><span class="muted">{{ $release['name'] }}</span></div>
        <div class="table-wrap">
            <table style="min-width:0;">
                <tr><th>Item</th><th>Value</th></tr>
                <tr><td>CRM version</td><td><strong>{{ $release['version'] }}</strong></td></tr>
                <tr><td>Release date</td><td>{{ $release['date'] }}</td></tr>
                <tr><td>Environment</td><td>{{ app()->environment() }}</td></tr>
                <tr><td>Application URL</td><td>{{ config('app.url') ?: 'Not configured' }}</td></tr>
            </table>
        </div>
    </div>

    <div class="card">
        <div class="section-head"><h3>Health Checks</h3><span class="muted">No changes are made by this page.</span></div>
        <div class="table-wrap">
            <table style="min-width:0;">
                <tr><th>Check</th><th>Status</th><th>Details</th></tr>
                @foreach($checks as $check)
                    <tr>
                        <td><strong>{{ $check['name'] }}</strong></td>
                        <td>{{ $check['ok'] ? '✅ PASS' : '❌ CHECK' }}</td>
                        <td class="muted">{{ $check['value'] }}</td>
                    </tr>
                @endforeach
            </table>
        </div>
    </div>

    @if(count($stats))
        <div class="card">
            <div class="section-head"><h3>Database Snapshot</h3><span class="muted">Informational only</span></div>
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:10px;">
                @foreach($stats as $stat)
                    <div style="border:1px solid var(--c-border,#ddd);border-radius:10px;padding:12px;">
                        <div class="muted">{{ $stat['label'] }}</div>
                        <div style="font-size:20px;font-weight:700;margin-top:3px;">{{ $stat['value'] }}</div>
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    <div class="card" style="border-left:4px solid #f39c12;">
        <h3>⏰ Scheduler note</h3>
        <p class="muted" style="margin:0;">{{ $serverCronNote }}</p>
    </div>

    <div class="card">
        <h3>How we use this page</h3>
        <ol style="margin:0;padding-left:20px;line-height:1.8;">
            <li>Before a production update, make your normal cPanel backup.</li>
            <li>After uploading a new CRM release, open this page.</li>
            <li>If everything is green, test the specific feature that was changed.</li>
            <li>If anything is red or the CRM behaves unexpectedly, stop and send me a screenshot.</li>
        </ol>
    </div>
@endsection
