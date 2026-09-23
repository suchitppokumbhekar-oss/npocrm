@extends('layouts.app')

@section('title', $contact->name . ' — Contact')

@section('content')
@php
    $isAgent = session('user_role') === 'agent';
    $isTelecaller = $isAgent && (($callerMode ?? 'agent') === 'telecaller');
    $statusLabel = ucfirst(str_replace('_', ' ', $contact->status));
    $lastOutcomeLabel = $contact->last_outcome_key
        ? ucfirst(str_replace('_', ' ', $contact->last_outcome_key))
        : 'No outcome yet';
@endphp

<a href="{{ url('/contacts') }}" class="back-link">← Back to contacts</a>

@if (session('success'))
    <div class="alert alert-success">{{ session('success') }}</div>
@endif
@if (session('error'))
    <div class="alert alert-error">{{ session('error') }}</div>
@endif
@if ($errors->any())
    <div class="alert alert-error">{{ $errors->first() }}</div>
@endif

{{-- Compact caller header: identity + only the information needed to orient the caller. --}}
<div class="contact-exec-header">
    <div class="contact-exec-identity">
        <div class="eyebrow">{{ $isTelecaller ? 'TELECALLER WORK' : ($isAgent ? 'CALLER WORK' : 'CONTACT') }}</div>
        <h1>{{ $contact->name }}</h1>
        <div class="contact-exec-phone"><a href="tel:{{ phone_tel($contact->phone) }}">📱 {{ phone_display($contact->phone) }}</a></div>
        <div class="contact-exec-context">
            <span>{{ $contact->project?->name ?? 'No pitch project' }}</span>
            <span>·</span>
            <span>{{ $statusLabel }}</span>
            @if ($contact->assigned_to_agent_id)
                <span>·</span><span>{{ $contact->agent?->user?->name ?? 'Assigned' }}</span>
            @endif
        </div>
    </div>
    <div class="contact-exec-actions">
        <a href="tel:{{ phone_tel($contact->phone) }}" class="btn-small btn-info">📞 Call</a>
        @if (! $contact->isPromoted())
            @if (!$isTelecaller || in_array($contact->last_outcome_key, ['site_visit_scheduled','visit_booked_spot'], true))
                <form method="POST" action="{{ url('/contacts/'.$contact->id.'/promote') }}">
                    @csrf
                    <input type="hidden" name="project_id" value="{{ $contact->project_id ?? '' }}">
                    <button type="submit" class="btn-small" style="background:var(--c-primary);color:#fff;">🎯 {{ $isTelecaller ? 'Create Lead / Handoff' : 'Create Lead' }}</button>
                </form>
            @endif
        @else
            <a href="{{ url('/leads/'.$contact->promoted_to_lead_id) }}" class="btn-small" style="background:var(--c-primary);color:#fff;">Open Lead →</a>
        @endif
    </div>
</div>

@if ($isAgent)
    <div class="contact-exec-rule">
        <strong>{{ $isTelecaller ? 'Your job: move this Contact to Site Visit.' : 'Your job: qualify this Contact and move it into the Lead workflow.' }}</strong>
        <span>Do the current action below, record the result, and let the CRM create the next action.</span>
    </div>
@endif

@include('contacts.work-console', [
    'workFollowups' => $workFollowups,
    'workOutcomes' => $workOutcomes,
    'contact' => $contact,
    'callerMode' => $callerMode ?? 'agent',
])

@if ($isAgent)
    {{-- Agents get context without the management/admin clutter. --}}
    <details class="contact-context-details">
        <summary>Customer context & history</summary>
        <div class="contact-context-body">
            <div class="contact-context-summary">
                <span><strong>Last outcome:</strong> {{ $lastOutcomeLabel }}</span>
                <span><strong>Calls:</strong> {{ $contact->attempts }}</span>
                <span><strong>Connected:</strong> {{ $contact->connected_count }}</span>
                <span><strong>Last called:</strong> {{ $contact->last_called_at?->diffForHumans() ?? 'Never' }}</span>
            </div>
            @if ($contact->notes)
                <div class="contact-note-block"><strong>Customer notes</strong><div>{{ $contact->notes }}</div></div>
            @endif
            <details class="contact-history-inner">
                <summary>Call history ({{ $contact->calls->count() }})</summary>
                @include('contacts.partials.call-history-table', ['contact' => $contact])
            </details>
        </div>
    </details>
@else
    {{-- Managers/admins need the operational record and assignment controls. --}}
    <div class="card contact-admin-context">
        <div class="section-head"><div><h3 style="margin:0;">Contact control</h3><p class="muted" style="margin:3px 0 0;font-size:12px;">Management information is kept here so it does not interfere with execution work.</p></div></div>

        @if ($contact->status !== 'dnc' && ! $contact->isPromoted())
            <div class="contact-admin-assignment">
                <h4>Pitch assignment</h4>
                <p class="muted">The assigned project is the caller's working project. Change it only when the customer's actual project requirement changes.</p>
                <form method="POST" action="{{ url('/contacts/'.$contact->id.'/assign') }}" style="display:flex;gap:var(--s-2);flex-wrap:wrap;align-items:end;" data-pitch-assignment-form data-pitch-agents="{{ e(json_encode($pitchAgentIdsByProject)) }}">
                    @csrf
                    <div style="min-width:220px;flex:1;"><label>Pitch Project</label>
                        <div class="npo-project-picker" data-project-picker>
                            <input type="search" class="input" placeholder="Search project…" autocomplete="off" value="{{ $contact->project?->name ?? '' }}" data-project-input>
                            <input type="hidden" name="project_id" value="{{ $contact->project_id ?? '' }}" data-project-id required>
                            <div class="npo-project-picker-menu" data-project-menu hidden>
                                @foreach($projects as $p)
                                    <button type="button" class="npo-project-picker-option" data-project-option data-project-id="{{ $p->id }}" data-project-name="{{ e($p->name) }}">{{ $p->name }}</button>
                                @endforeach
                            </div>
                        </div>
                    </div>
                    <div style="min-width:190px;flex:1;"><label>Caller</label><select name="agent_id" class="input" data-pitch-agent-select><option value="">Unassigned</option>@foreach($agents as $a)<option value="{{ $a->id }}" @selected((int)$contact->assigned_to_agent_id === (int)$a->id)>{{ $a->user?->name ?? 'Agent #'.$a->id }}</option>@endforeach</select></div>
                    <button type="submit" class="btn-small btn-info">Save assignment</button>
                </form>
            </div>
        @endif

        <div class="contact-admin-meta">
            <span><strong>Project:</strong> {{ $contact->project?->name ?? '—' }}</span>
            <span><strong>Source:</strong> {{ $contact->source ?? '—' }}</span>
            <span><strong>Assigned:</strong> {{ $contact->agent?->user?->name ?? 'Unassigned' }}</span>
            <span><strong>Last outcome:</strong> {{ $lastOutcomeLabel }}</span>
            <span><strong>Created:</strong> {{ $contact->created_at?->format('d M Y, H:i') }}</span>
        </div>
    </div>

    <details class="contact-history-details">
        <summary>History & audit trail</summary>
        <div class="contact-history-stack">
            <section>
                <h3>🗂️ Pitch Assignment History</h3>
                @if ($contact->projectAssignments->isEmpty())
                    <p class="muted">No pitch assignment history yet.</p>
                @else
                    <div class="table-wrap"><table>
                        <tr><th>Project</th><th>Caller</th><th>Status</th><th>Assigned</th><th>Completed</th><th>By</th></tr>
                        @foreach ($contact->projectAssignments as $assignment)
                            <tr><td>{{ $assignment->project?->name ?? '—' }}</td><td>{{ $assignment->agent?->user?->name ?? 'Unassigned' }}</td><td>{{ ucfirst($assignment->status) }}</td><td class="muted">{{ $assignment->assigned_at?->format('d M Y, H:i') }}</td><td class="muted">{{ $assignment->completed_at?->format('d M Y, H:i') ?? '—' }}</td><td>{{ $assignment->assignedBy?->name ?? '—' }}</td></tr>
                        @endforeach
                    </table></div>
                @endif
            </section>
            <section>
                <h3>📞 Call History ({{ $contact->calls->count() }})</h3>
                @include('contacts.partials.call-history-table', ['contact' => $contact])
            </section>
        </div>
    </details>
@endif

<style>
.contact-exec-header{display:flex;justify-content:space-between;gap:18px;align-items:center;padding:4px 0 12px;border-bottom:1px solid var(--c-border);margin-bottom:10px}.contact-exec-identity{min-width:0}.contact-exec-identity h1{margin:2px 0 2px;font-size:24px}.contact-exec-phone{font-size:16px;font-weight:800}.contact-exec-phone a{color:var(--c-primary);text-decoration:none}.contact-exec-context{display:flex;gap:7px;flex-wrap:wrap;color:var(--c-muted);font-size:12px;margin-top:5px}.contact-exec-actions{display:flex;gap:7px;align-items:center;flex-wrap:wrap;justify-content:flex-end}.contact-exec-actions form{margin:0}.contact-exec-rule{display:flex;gap:8px;align-items:baseline;flex-wrap:wrap;font-size:12px;margin:0 0 10px;padding:8px 10px;border-left:3px solid var(--c-primary);background:var(--c-surface-2)}.contact-exec-rule span{color:var(--c-muted)}.contact-context-details,.contact-history-details{margin-top:10px;border-top:1px solid var(--c-border);padding-top:10px}.contact-context-details>summary,.contact-history-details>summary{cursor:pointer;font-weight:700;font-size:13px}.contact-context-body{padding-top:10px}.contact-context-summary{display:flex;gap:16px;flex-wrap:wrap;font-size:12px;color:var(--c-muted)}.contact-note-block{margin-top:10px;font-size:12px}.contact-note-block div{white-space:pre-wrap;margin-top:4px}.contact-history-inner{margin-top:12px}.contact-history-inner summary{cursor:pointer;font-weight:700;font-size:12px}.contact-admin-context{margin-top:10px}.contact-admin-assignment{padding-bottom:12px;margin-bottom:12px;border-bottom:1px solid var(--c-border)}.contact-admin-assignment h4{margin:0 0 3px}.contact-admin-assignment p{font-size:12px;margin:0 0 10px}.contact-admin-meta{display:flex;gap:14px;flex-wrap:wrap;font-size:12px;color:var(--c-muted)}.contact-history-stack{display:grid;gap:16px;margin-top:10px}.contact-history-stack h3{margin:0 0 8px;font-size:15px}
@media(max-width:767px){.contact-exec-header{display:block}.contact-exec-identity h1{font-size:21px}.contact-exec-actions{justify-content:flex-start;margin-top:10px}.contact-exec-actions .btn-small{flex:1;text-align:center}.contact-exec-rule{display:block}.contact-exec-rule span{display:block;margin-top:3px}.contact-context-summary{display:block}.contact-context-summary span{display:block;margin-bottom:4px}.contact-admin-meta{display:block}.contact-admin-meta span{display:block;margin-bottom:5px}}
</style>
@endsection
