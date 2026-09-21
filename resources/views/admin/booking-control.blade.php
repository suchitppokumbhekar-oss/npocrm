@extends('layouts.app')

@section('title', 'Booking Control — NPO CRM')

@section('content')
<div class="page-head" style="margin-bottom:14px;">
    <div>
        <a href="{{ url('/reports') }}" class="back-link">← Back to Reports</a>
        <h2 style="margin:7px 0 3px;">🎯 Booking Control</h2>
        <p class="muted" style="margin:0;">Controlled booking approval, rejection and post-approval protection. Booking lifecycle status remains unchanged; this screen controls the approval record.</p>
    </div>
</div>

@if(session('success'))<div class="card notice-success" style="margin-bottom:14px;">{{ session('success') }}</div>@endif
@if(session('error'))<div class="card notice-error" style="margin-bottom:14px;">{{ session('error') }}</div>@endif

<div class="booking-stats">
    <div class="card"><div class="muted">Pending approval</div><strong>{{ number_format($counts['pending']) }}</strong></div>
    <div class="card"><div class="muted">Approved</div><strong>{{ number_format($counts['approved']) }}</strong></div>
    <div class="card"><div class="muted">Rejected / needs correction</div><strong>{{ number_format($counts['rejected']) }}</strong></div>
</div>

<div class="card" style="margin-bottom:14px;">
    <form method="GET" class="booking-filter">
        <label>Status
            <select name="status">
                <option value="">All</option>
                @foreach(['pending','approved','rejected','cancelled'] as $status)
                    <option value="{{ $status }}" @selected(request('status') === $status)>{{ ucfirst($status) }}</option>
                @endforeach
            </select>
        </label>
        <label>Search lead
            <input type="search" name="q" value="{{ request('q') }}" placeholder="Name, phone or lead ID">
        </label>
        <div><button class="btn" type="submit">🔎 Filter</button> <a class="btn btn-ghost" href="{{ route('admin.booking-control') }}">Reset</a></div>
    </form>
</div>

<div class="card" style="padding:0;overflow:hidden;">
    <div style="padding:13px 15px;border-bottom:1px solid var(--c-border,#ddd);display:flex;justify-content:space-between;gap:12px;align-items:center;">
        <strong>{{ number_format($controls->total()) }} booking control records</strong>
        <span class="muted" style="font-size:12px;">Pending first · newest request first</span>
    </div>

    <div class="booking-table-wrap">
        <table class="booking-table">
            <thead><tr><th>Lead</th><th>Booking</th><th>Control</th><th>Requested</th><th>Decision</th><th>Action</th></tr></thead>
            <tbody>
            @forelse($controls as $control)
                @php $lead = $control->lead; @endphp
                <tr>
                    <td>
                        <strong>{{ $lead?->customer_name ?: 'Lead #'.$control->lead_id }}</strong>
                        <div class="muted">Lead #{{ $control->lead_id }} @if($lead?->project) · {{ $lead->project->name }} @endif</div>
                    </td>
                    <td>
                        <strong>{{ inr($lead?->booking_amount, 2) }}</strong>
                        <div class="muted">{{ optional($lead?->booking_date)->format('d M Y') ?: 'No date' }}</div>
                    </td>
                    <td>
                        <span class="control-badge control-{{ $control->status }}">{{ ucfirst($control->status) }}</span>
                        @if($control->decision_note)<div class="decision-note">{{ $control->decision_note }}</div>@endif
                    </td>
                    <td>
                        {{ optional($control->requested_at)->format('d M Y, h:i A') ?: '—' }}
                        @if($control->requestedBy)<div class="muted">by {{ $control->requestedBy->name }}</div>@endif
                    </td>
                    <td>
                        @if($control->approvedBy)
                            Approved by {{ $control->approvedBy->name }}<br>{{ optional($control->approved_at)->format('d M Y, h:i A') }}
                        @elseif($control->rejectedBy)
                            Rejected by {{ $control->rejectedBy->name }}<br>{{ optional($control->rejected_at)->format('d M Y, h:i A') }}
                        @else — @endif
                    </td>
                    <td class="booking-actions">
                        <a class="btn-small btn-info" href="{{ url('/leads/'.$control->lead_id) }}">Open lead</a>
                        @if($control->status === 'pending')
                            <form method="POST" action="{{ route('admin.booking-control.approve', $control->id) }}" style="display:inline;">
                                @csrf
                                <button class="btn-small" type="submit" onclick="return confirm('Approve this booking?')">✅ Approve</button>
                            </form>
                            <details class="reject-details">
                                <summary class="btn-small btn-danger">Reject</summary>
                                <form method="POST" action="{{ route('admin.booking-control.reject', $control->id) }}" class="reject-form">
                                    @csrf
                                    <textarea name="reason" required minlength="3" maxlength="2000" placeholder="Reason for rejection"></textarea>
                                    <button class="btn-small btn-danger" type="submit">Reject booking</button>
                                </form>
                            </details>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="6" style="padding:28px;text-align:center;" class="muted">No booking control records match these filters.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    @if($controls->hasPages())<div style="padding:13px 15px;border-top:1px solid var(--c-border,#ddd);">{{ $controls->links() }}</div>@endif
</div>

<style>
.booking-stats{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;margin-bottom:14px}.booking-stats strong{display:block;font-size:25px;margin-top:5px}
.booking-filter{display:grid;grid-template-columns:220px minmax(240px,1fr) auto;gap:10px;align-items:end}.booking-filter label{font-size:12px;font-weight:700;display:grid;gap:5px}.booking-filter input,.booking-filter select{min-height:40px;padding:8px 10px;border:1px solid var(--c-border,#ddd);border-radius:8px;background:var(--c-surface,#fff);color:inherit;width:100%}
.booking-table-wrap{overflow:auto}.booking-table{width:100%;border-collapse:collapse;min-width:1050px;font-size:12px}.booking-table th,.booking-table td{padding:11px 12px;border-bottom:1px solid var(--c-border,#e5e5e5);vertical-align:top;text-align:left}.booking-table th{font-size:11px;text-transform:uppercase;letter-spacing:.04em;background:var(--c-surface-2,#f7f7f7);white-space:nowrap}.control-badge{display:inline-block;padding:4px 8px;border-radius:999px;font-size:10px;font-weight:800;text-transform:uppercase}.control-pending{background:#fff3cd}.control-approved{background:#dff7e8}.control-rejected{background:#fde2e1}.control-cancelled{background:#eee}.decision-note{margin-top:5px;max-width:260px;white-space:pre-wrap}.booking-actions{white-space:nowrap}.reject-details{display:inline-block;vertical-align:middle}.reject-details summary{cursor:pointer;list-style:none}.reject-form{margin-top:8px;padding:8px;border:1px solid var(--c-border,#ddd);border-radius:8px;background:var(--c-surface,#fff)}.reject-form textarea{display:block;width:260px;min-height:70px;margin-bottom:7px;padding:7px}.notice-success{border-left:4px solid #067647}.notice-error{border-left:4px solid #b42318}
@media(max-width:700px){.booking-stats{grid-template-columns:1fr}.booking-filter{grid-template-columns:1fr}}
</style>
@endsection
