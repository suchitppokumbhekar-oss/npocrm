@extends('layouts.app')

@section('title', 'Delete Lead — Admin')

@section('content')

<a href="{{ url('/') }}" class="back-link">← Back to dashboard</a>

<div class="card">
    <h2 style="margin:0 0 6px;color:var(--c-danger);">🗑️ Delete Lead</h2>
    <p class="muted" style="margin:0 0 var(--s-3) 0;font-size:13px;">
        Permanently remove a lead and every related record — activities, followups, calls,
        assignments, notifications. <strong>This cannot be undone.</strong>
    </p>

    @if (session('success'))
        <div class="alert" style="background:#d4edda;border-left-color:var(--c-primary);color:#155724;">
            {{ session('success') }}
        </div>
    @endif
    @if (session('error'))
        <div class="alert alert-error">{{ session('error') }}</div>
    @endif

    <form method="GET" action="{{ url('/admin/delete-lead') }}" class="flex" style="margin-top:var(--s-3);">
        <div class="flex-item" style="flex:3;">
            <label>Lead number (ID)</label>
            <input type="number" name="lead_id" class="input"
                   value="{{ $leadId ?: '' }}" min="1" required
                   placeholder="e.g. 31">
        </div>
        <div class="flex-item" style="flex:1;">
            <label>&nbsp;</label>
            <button type="submit" class="btn-small btn-info" style="width:100%;min-height:44px;">
                🔍 Preview
            </button>
        </div>
    </form>
</div>

@if ($leadId && $preview)
    @if (! $preview['found'])
        <div class="card" style="border-left:4px solid var(--c-danger);background:#fdecea;">
            <h3 style="color:var(--c-danger);margin:0;">❌ Lead #{{ $leadId }} not found</h3>
            <p style="margin-top:6px;">No lead exists with that ID.</p>
        </div>
    @else
        @php
            $lead   = $preview['lead'];
            $counts = $preview['counts'];
        @endphp

        {{-- ============================================================ --}}
        {{-- PREVIEW --}}
        {{-- ============================================================ --}}
        <div class="card" style="border-left:4px solid var(--c-warn);background:#fff8e1;">
            <h3 style="margin:0 0 8px 0;">⚠️ Confirm Deletion</h3>
            <p style="font-size:14px;">
                You are about to permanently delete this lead. Everything below will be removed.
            </p>

            <div class="lead-detail-grid" style="margin-top:var(--s-3);">
                <div class="row">
                    <span class="label">Lead ID</span>
                    <span class="value"><strong>#{{ $lead->id }}</strong></span>
                </div>
                <div class="row">
                    <span class="label">Customer</span>
                    <span class="value"><strong>{{ $lead->customer_name }}</strong></span>
                </div>
                <div class="row">
                    <span class="label">Phone</span>
                    <span class="value">{{ $lead->phone }}</span>
                </div>
                <div class="row">
                    <span class="label">Email</span>
                    <span class="value">{{ $lead->email ?: '—' }}</span>
                </div>
                <div class="row">
                    <span class="label">Project</span>
                    <span class="value">{{ $lead->project?->name ?? '—' }}</span>
                </div>
                <div class="row">
                    <span class="label">Agent</span>
                    <span class="value">{{ $lead->agent?->user?->name ?? 'Unassigned' }}</span>
                </div>
                <div class="row">
                    <span class="label">Status</span>
                    <span class="value">{{ ucfirst(str_replace('_', ' ', $lead->status)) }}</span>
                </div>
                <div class="row">
                    <span class="label">Created</span>
                    <span class="value">{{ $lead->created_at?->format('d M Y, H:i') }}</span>
                </div>
            </div>
        </div>

        <div class="card">
            <h3>📦 What will be deleted</h3>
            <div class="table-wrap">
                <table>
                    <tr><th>Table</th><th style="text-align:right;">Rows</th><th>Effect</th></tr>
                    <tr>
                        <td>Activities</td>
                        <td style="text-align:right;"><strong>{{ $counts['activities'] }}</strong></td>
                        <td><span class="muted" style="font-size:12px;">Timeline entries — deleted</span></td>
                    </tr>
                    <tr>
                        <td>Followups</td>
                        <td style="text-align:right;"><strong>{{ $counts['followups'] }}</strong></td>
                        <td><span class="muted" style="font-size:12px;">Pending + past tasks — deleted</span></td>
                    </tr>
                    <tr>
                        <td>Lead-agent links</td>
                        <td style="text-align:right;"><strong>{{ $counts['lead_agents'] }}</strong></td>
                        <td><span class="muted" style="font-size:12px;">Assignments — deleted</span></td>
                    </tr>
                    <tr>
                        <td>Calls</td>
                        <td style="text-align:right;"><strong>{{ $counts['calls'] }}</strong></td>
                        <td><span class="muted" style="font-size:12px;">Telecalling log — deleted</span></td>
                    </tr>
                    <tr>
                        <td>Notifications</td>
                        <td style="text-align:right;"><strong>{{ $counts['notifications'] }}</strong></td>
                        <td><span class="muted" style="font-size:12px;">Related alerts — deleted</span></td>
                    </tr>
                    <tr>
                        <td>Contacts (promoted)</td>
                        <td style="text-align:right;"><strong>{{ $counts['contacts_promoted'] }}</strong></td>
                        <td><span class="muted" style="font-size:12px;">Contact kept, unlinked</span></td>
                    </tr>
                    <tr style="background:#fdecea;">
                        <td><strong>Leads</strong></td>
                        <td style="text-align:right;"><strong>1</strong></td>
                        <td><span style="color:var(--c-danger);font-size:12px;font-weight:600;">The lead itself — deleted</span></td>
                    </tr>
                </table>
            </div>

            @if ($preview['last_activities']->isNotEmpty())
                <h3 style="margin-top:var(--s-3);">📜 Last activities (before deletion)</h3>
                <div class="table-wrap">
                    <table>
                        <tr><th>When</th><th>Type</th><th>Outcome</th></tr>
                        @foreach ($preview['last_activities'] as $a)
                            <tr>
                                <td><span class="muted" style="font-size:12px;">{{ $a->logged_at?->format('d M Y, H:i') }}</span></td>
                                <td>{{ $a->type }}</td>
                                <td>{{ $a->outcome ?: '—' }}</td>
                            </tr>
                        @endforeach
                    </table>
                </div>
            @endif
        </div>

        {{-- ============================================================ --}}
        {{-- CONFIRM FORM --}}
        {{-- ============================================================ --}}
        <div class="card" style="border-left:4px solid var(--c-danger);">
            <h3 style="color:var(--c-danger);margin-top:0;">🔒 Final Confirmation</h3>
            <p style="font-size:13px;">
                To confirm, type the customer's name exactly and tick the acknowledgement box.
            </p>

            <form method="POST" action="{{ url('/admin/delete-lead/confirm') }}">
                @csrf
                <input type="hidden" name="lead_id" value="{{ $lead->id }}">

                <div class="field">
                    <label>Type the name: <code>{{ $lead->customer_name }}</code></label>
                    <input type="text" name="confirm_name" class="input" required
                           autocomplete="off" placeholder="Type the exact name here"
                           data-expected="{{ $lead->customer_name }}">
                </div>

                <div class="field">
                    <label style="display:flex;align-items:flex-start;gap:8px;cursor:pointer;font-weight:600;">
                        <input type="checkbox" name="acknowledge" value="1" required
                               style="width:auto;min-height:auto;margin-top:4px;">
                        <span>
                            I understand this permanently deletes the lead and every related record.
                            This cannot be undone.
                        </span>
                    </label>
                </div>

                <button type="submit" class="btn btn-block"
                        style="background:var(--c-danger);"
                        onclick="return confirm('Last chance — really delete this lead and everything related?');">
                    🗑️ Permanently delete this lead
                </button>
            </form>
        </div>
    @endif
@endif

@endsection