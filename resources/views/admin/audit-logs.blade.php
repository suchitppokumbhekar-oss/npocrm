@extends('layouts.app')

@section('title', 'Vigilance Audit Log — NPO CRM')

@section('content')
<div class="page-head" style="margin-bottom:14px;">
    <div>
        <a href="{{ url('/reports') }}" class="back-link">← Back to Reports</a>
        <h2 style="margin:7px 0 3px;">🛡️ Vigilance Audit Log</h2>
        <p class="muted" style="margin:0;">System-level actions outside the lead activity timeline: logins, downloads, exports, imports, settings changes, user administration and other administrative requests.</p>
    </div>
</div>

@if(session('status'))
    <div class="card" style="margin-bottom:14px;">{{ session('status') }}</div>
@endif

@if($integrity['checked'] === 0 || ! $integrity['ok'])
<div class="card" style="margin-bottom:14px;">
    <strong>Integrity initialization</strong>
    <div class="muted" style="font-size:12px;margin:4px 0 10px;">Legacy audit events must be initialized before the full chain can be verified.</div>
    <form method="POST" action="{{ route('admin.audit-logs.initialize') }}">
        @csrf
        <button class="btn" type="submit">Initialize audit chain</button>
    </form>
</div>
@endif

<div class="card" style="margin-bottom:14px;display:flex;justify-content:space-between;gap:14px;align-items:center;">
    <div>
        <strong>🔐 Audit integrity</strong>
        <div class="muted" style="font-size:12px;margin-top:3px;">
            {{ number_format($integrity['checked']) }} events checked against the tamper-evident chain.
            @if($integrity['ok']) No integrity errors detected. @else {{ $integrity['first_error'] }} @endif
        </div>
    </div>
    <a class="btn {{ $integrity['ok'] ? 'btn-ghost' : '' }}" href="{{ route('admin.audit-logs.verify') }}">Verify now</a>
</div>

<div class="card" style="margin-bottom:14px;">
    <form method="GET" action="{{ route('admin.audit-logs') }}" class="audit-filter-grid">
        <label>User
            <select name="user_id">
                <option value="">All users</option>
                @foreach($users as $user)
                    <option value="{{ $user->id }}" @selected((string)request('user_id') === (string)$user->id)>{{ $user->name }} — {{ $user->role }}</option>
                @endforeach
            </select>
        </label>
        <label>Category
            <select name="category">
                <option value="">All categories</option>
                @foreach($categories as $category)
                    <option value="{{ $category }}" @selected(request('category') === $category)>{{ ucwords(str_replace('_', ' ', $category)) }}</option>
                @endforeach
            </select>
        </label>
        <label>Action contains
            <input type="search" name="action" value="{{ request('action') }}" placeholder="download, settings, role…">
        </label>
        <label>From
            <input type="date" name="from" value="{{ request('from') }}">
        </label>
        <label>To
            <input type="date" name="to" value="{{ request('to') }}">
        </label>
        <div style="display:flex;gap:8px;align-items:end;">
            <button class="btn" type="submit">🔎 Filter</button>
            <a class="btn btn-ghost" href="{{ route('admin.audit-logs') }}">Reset</a>
        </div>
    </form>
</div>

<div class="card" style="padding:0;overflow:hidden;">
    <div style="padding:13px 15px;border-bottom:1px solid var(--c-border,#ddd);display:flex;justify-content:space-between;gap:12px;align-items:center;">
        <strong>{{ number_format($logs->total()) }} audit events</strong>
        <span class="muted" style="font-size:12px;">Newest first · timestamps are CRM server time</span>
    </div>

    <div class="audit-table-wrap">
        <table class="audit-table">
            <thead>
                <tr>
                    <th>When</th>
                    <th>Who</th>
                    <th>Action</th>
                    <th>Request</th>
                    <th>Result</th>
                    <th>Details</th>
                </tr>
            </thead>
            <tbody>
            @forelse($logs as $log)
                <tr>
                    <td class="audit-time">
                        <strong>{{ optional($log->created_at)->format('d M Y') }}</strong><br>
                        <span>{{ optional($log->created_at)->format('h:i:s A') }}</span>
                    </td>
                    <td>
                        <strong>{{ $log->actor_name ?: 'Unauthenticated' }}</strong><br>
                        <span class="muted">{{ $log->actor_role ?: 'guest' }} @if($log->actor_user_id)#{{ $log->actor_user_id }}@endif</span>
                    </td>
                    <td>
                        <span class="audit-category audit-category-{{ $log->event_category }}">{{ ucwords(str_replace('_',' ', $log->event_category)) }}</span>
                        <div style="margin-top:5px;font-weight:600;">{{ $log->action }}</div>
                    </td>
                    <td>
                        <code>{{ $log->method }}</code> {{ $log->path }}
                        @if($log->route_name)<div class="muted" style="font-size:11px;margin-top:3px;">{{ $log->route_name }}</div>@endif
                    </td>
                    <td>
                        <span class="audit-status {{ ($log->status_code ?? 0) >= 400 ? 'bad' : 'ok' }}">{{ $log->status_code ?: '—' }}</span>
                    </td>
                    <td class="audit-details">
                        @if(is_array($log->details))
                            @foreach($log->details as $key => $value)
                                <div><strong>{{ str_replace('_',' ', $key) }}:</strong>
                                    @if(is_array($value)) {{ implode(', ', array_map('strval', $value)) }}
                                    @else {{ (string)$value }} @endif
                                </div>
                            @endforeach
                        @endif
                        @if($log->ip_address)<div class="muted">IP: {{ $log->ip_address }}</div>@endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="6" style="padding:28px;text-align:center;" class="muted">No audit events match these filters.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>

    @if($logs->hasPages())
        <div style="padding:13px 15px;border-top:1px solid var(--c-border,#ddd);">{{ $logs->links() }}</div>
    @endif
</div>

<style>
.audit-filter-grid{display:grid;grid-template-columns:repeat(6,minmax(0,1fr));gap:10px;align-items:end}
.audit-filter-grid label{font-size:12px;font-weight:600;display:grid;gap:5px}
.audit-filter-grid input,.audit-filter-grid select{width:100%;min-height:40px;padding:8px 10px;border:1px solid var(--c-border,#ddd);border-radius:8px;background:var(--c-surface,#fff);color:inherit}
.audit-table-wrap{overflow:auto}
.audit-table{width:100%;border-collapse:collapse;min-width:1050px;font-size:12px}
.audit-table th,.audit-table td{padding:10px 12px;border-bottom:1px solid var(--c-border,#e5e5e5);vertical-align:top;text-align:left}
.audit-table th{font-size:11px;text-transform:uppercase;letter-spacing:.04em;white-space:nowrap;background:var(--c-surface-2,#f7f7f7)}
.audit-time{white-space:nowrap}.audit-time span{font-size:11px;color:var(--c-muted,#666)}
.audit-category{display:inline-block;padding:3px 7px;border-radius:999px;font-size:10px;font-weight:700;background:#eee}
.audit-status{font-weight:700}.audit-status.bad{color:#b42318}.audit-status.ok{color:#067647}
.audit-details{max-width:360px;word-break:break-word}.audit-details div{margin-bottom:3px}
@media(max-width:900px){.audit-filter-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media(max-width:560px){.audit-filter-grid{grid-template-columns:1fr}.audit-table{min-width:900px}}
</style>
@endsection
