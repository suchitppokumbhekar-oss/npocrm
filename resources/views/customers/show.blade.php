@extends('layouts.app')

@section('title', $customer->name . ' — Customer 360')

@section('content')

    @if (session('success'))
        <div class="card" style="border-left:4px solid var(--c-success,#22a65a);">{{ session('success') }}</div>
    @endif
    @if (session('error'))
        <div class="card" style="border-left:4px solid var(--c-danger,#e74c3c);">{{ session('error') }}</div>
    @endif

    <a href="{{ url('/search?q=' . urlencode($customer->phone)) }}" class="back-link">← Back to search</a>

    {{-- HEADER --}}
    <div class="card">
        <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:var(--s-3);flex-wrap:wrap;">
            <div>
                <h2 style="margin:0 0 6px;">👤 {{ $customer->name }}</h2>
                <div style="font-size:14px;line-height:1.8;">
                    <div>
                        📱 <a href="tel:{{ $customer->phone }}" style="font-weight:600;color:inherit;">
                            {{ $customer->phone }}
                        </a>
                        @if ($customer->phone)
                            &nbsp;
                            <button type="button" data-npo-whatsapp data-whatsapp-phone="{{ preg_replace('/\D/', '', '91' . $customer->phone) }}" data-whatsapp-text="{{ base64_encode('Hi ' . ($customer->name ?? '')) }}"
                               class="contact-btn contact-btn-wa contact-btn-sm"
                               style="display:inline-flex;vertical-align:middle;margin-left:4px;">
                                💬 WhatsApp
                            </button>
                        @endif
                    </div>
                    @if ($customer->email)
                        <div>✉️ <a href="mailto:{{ $customer->email }}" style="color:inherit;">{{ $customer->email }}</a></div>
                    @endif
                    <div class="muted" style="font-size:12px;margin-top:4px;">
                        First seen: {{ $customer->first_seen_at?->format('d M Y') ?? '—' }}
                        · Last seen: {{ $customer->last_seen_at?->diffForHumans() ?? '—' }}
                    </div>
                </div>
            </div>
        </div>

        <div class="grid-stats" style="margin-top:var(--s-3);">
            <x-stat-card label="📋 Total Enquiries" :value="$stats['total']" />
            <x-stat-card label="🔥 Active" :value="$stats['active']" variant="orange" />
            <x-stat-card label="🎉 Booked" :value="$stats['booked']" variant="green" />
            <x-stat-card label="🚫 Lost" :value="$stats['lost']" variant="red" />
        </div>
    </div>

    {{-- ENQUIRIES --}}
    <div class="card">
        <div class="search-section-head">
            <h3>📋 All Enquiries ({{ $leads->count() }})</h3>
            <div class="muted" style="font-size:12px;margin-top:4px;">Every enquiry visible to your access scope, with current owner, latest activity and next action.</div>
        </div>

        @if ($leads->isEmpty())
            <p class="muted">No enquiries visible in your scope.</p>
        @else
            @php $s = app(\App\Services\SettingsService::class); @endphp

            <div class="table-wrap">
                <table>
                    <tr>
                        <th>Project</th>
                        <th>Who is working</th>
                        <th>Status</th>
                        <th>Last activity</th>
                        <th>Next action</th>
                        <th>Actions</th>
                    </tr>
                    @foreach ($leads as $lead)
                        <tr>
                            <td>
                                <a href="{{ url('/leads/' . $lead->id) }}" style="font-weight:600;color:inherit;">
                                    🏗️ {{ $lead->project?->name ?? 'No project' }}
                                </a>
                            </td>
                            <td>
                                @php
                                    $workers = $lead->activeAgents->pluck('user.name')->filter()->unique()->values();
                                @endphp
                                @if ($workers->isNotEmpty())
                                    <div style="font-size:12px;line-height:1.5;">
                                        👤 {{ $workers->implode(', ') }}
                                    </div>
                                @else
                                    <span class="badge red" style="font-size:11px;">⚠️ Unassigned</span>
                                @endif
                            </td>
                            <td>
                                <span class="badge {{ $s->statusColor($lead->statusKey()) }}">
                                    {{ $s->statusLabel($lead->statusKey()) }}
                                </span>
                            </td>
                            <td>
                                @if ($lead->latestActivity)
                                    <div style="font-size:12px;">
                                        {{ $lead->latestActivity->displayLabel(55) }}
                                    </div>
                                    <span class="muted" style="font-size:11px;">
                                        {{ $lead->latestActivity->agent?->user?->name ?? 'System' }} · {{ $lead->latestActivity->logged_at?->diffForHumans() }}
                                    </span>
                                @else
                                    <span class="muted" style="font-size:12px;">No activity</span>
                                @endif
                            </td>
                            <td>
                                @if ($lead->pendingFollowup)
                                    @php $next = $lead->pendingFollowup; @endphp
                                    <div style="font-size:12px;">
                                        {{ ucfirst(str_replace('_', ' ', $next->action_type)) }}
                                    </div>
                                    <span class="badge {{ $next->isOverdue() ? 'red' : 'blue' }}" style="font-size:10px;">
                                        {{ $next->isOverdue() ? 'OVERDUE' : $next->scheduled_for?->format('d M, H:i') }}
                                    </span>
                                @else
                                    <span class="muted" style="font-size:12px;">No pending action</span>
                                @endif
                            </td>
                            <td class="row-actions">
                                <x-contact-buttons :lead="$lead" size="sm" />
                                <a href="{{ url('/leads/' . $lead->id) }}" class="btn-small btn-info">Open</a>
                            </td>
                        </tr>
                    @endforeach
                </table>
            </div>

            {{-- Mobile cards --}}
            @foreach ($leads as $lead)
                <div class="search-mobile-card">
                    <div class="search-mobile-card-head">
                        <a href="{{ url('/leads/' . $lead->id) }}" class="search-mobile-title">
                            🏗️ {{ $lead->project?->name ?? 'No project' }}
                        </a>
                        <span class="badge {{ $s->statusColor($lead->statusKey()) }}">
                            {{ $s->statusLabel($lead->statusKey()) }}
                        </span>
                    </div>
                    @php $workers = $lead->activeAgents->pluck('user.name')->filter()->unique()->values(); @endphp
                    <div class="muted" style="font-size:12px;line-height:1.5;">
                        👤 {{ $workers->isNotEmpty() ? $workers->implode(', ') : 'Unassigned' }}
                        @if ($lead->latestActivity)
                            · {{ $lead->latestActivity->displayLabel(45) }} · {{ $lead->latestActivity->logged_at?->diffForHumans() }}
                        @endif
                    </div>
                    @if ($lead->pendingFollowup)
                        <div style="font-size:12px;margin-top:4px;">
                            ⏭️ Next: {{ ucfirst(str_replace('_', ' ', $lead->pendingFollowup->action_type)) }} ·
                            <span class="{{ $lead->pendingFollowup->isOverdue() ? 'text-danger' : '' }}">{{ $lead->pendingFollowup->scheduled_for?->format('d M, H:i') }}</span>
                        </div>
                    @endif
                    <div class="search-mobile-actions">
                        <x-contact-buttons :lead="$lead" size="lg" />
                    </div>
                </div>
            @endforeach
        @endif
    </div>

    {{-- SUPER ADMIN DUPLICATE RECONCILIATION --}}
    @if (session('user_role') === 'admin' && app(\App\Services\AccessService::class)->isUnrestrictedAdmin((int) session('user_id')))
        @if ($duplicateGroups->isNotEmpty())
            <div class="card" style="border:1px solid #f0c36a;background:linear-gradient(180deg,rgba(255,193,7,.08),transparent);">
                <div class="search-section-head">
                    <h3>⚠️ Same-Project Duplicate Candidates ({{ $duplicateGroups->count() }})</h3>
                    <div class="muted" style="font-size:12px;margin-top:4px;">Only leads that are genuinely eligible for same-customer + same-project reconciliation appear here. Existing lead relationships are excluded and shown separately below.</div>
                </div>

                @foreach ($duplicateGroups as $group)
                    @php $project = $group->first()->project; $defaultSurvivor = $group->sortBy('created_at')->first(); @endphp
                    <form method="POST" action="{{ url('/customers/' . $customer->id . '/reconcile-duplicates') }}" style="margin-top:14px;padding:14px;border:1px solid var(--c-border);border-radius:12px;background:var(--c-surface,#fff);">
                        @csrf
                        <input type="hidden" name="project_id" value="{{ $group->first()->project_id }}">
                        <div style="display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;align-items:center;">
                            <div>
                                <strong>🏗️ {{ $project?->name ?? 'Project' }}</strong>
                                <div class="muted" style="font-size:12px;">{{ $group->count() }} eligible lead records for the same customer + project</div>
                            </div>
                            <span class="badge red">DUPLICATE CANDIDATE</span>
                        </div>

                        <div class="table-wrap" style="margin-top:10px;">
                            <table>
                                <tr><th>Keep</th><th>Lead</th><th>Owner</th><th>Status</th><th>Created</th><th>Activity</th><th></th></tr>
                                @foreach ($group as $candidate)
                                    <tr>
                                        <td>
                                            <input type="radio" name="survivor_id" value="{{ $candidate->id }}" {{ $candidate->id === $defaultSurvivor->id ? 'checked' : '' }} aria-label="Keep Lead #{{ $candidate->id }}">
                                        </td>
                                        <td><strong>#{{ $candidate->id }}</strong></td>
                                        <td>{{ $candidate->agent?->user?->name ?? 'Unassigned' }}</td>
                                        <td><span class="badge {{ app(\App\Services\SettingsService::class)->statusColor($candidate->statusKey()) }}">{{ app(\App\Services\SettingsService::class)->statusLabel($candidate->statusKey()) }}</span></td>
                                        <td>{{ $candidate->created_at?->format('d M Y H:i') }}</td>
                                        <td>{{ $candidate->latestActivity?->displayLabel(45) ?? 'No activity' }}</td>
                                        <td><a href="{{ url('/leads/' . $candidate->id) }}" class="btn-small btn-info">Open</a></td>
                                    </tr>
                                @endforeach
                            </table>
                        </div>

                        <div style="margin-top:12px;display:grid;gap:8px;">
                            <label style="font-weight:600;">Reconciliation reason <span style="color:#c33;">*</span></label>
                            <textarea name="reason" required maxlength="1000" rows="2" placeholder="Example: Same customer and same project; duplicate manual lead created on 15 Sep. Keep the existing active enquiry and preserve the duplicate history."></textarea>
                            <div class="muted" style="font-size:11px;">Only the other eligible leads in this group will be reconciled. Protected relationships are never moved or overwritten.</div>
                            <button type="submit" class="btn-small btn-info" onclick="return confirm('Reconcile all other eligible leads in this project group into the selected surviving lead? Historical records will be preserved and protected relationships will be left untouched.');">♻️ Reconcile Duplicates</button>
                        </div>
                    </form>
                @endforeach
            </div>
        @endif

        @if ($protectedRelationshipGroups->isNotEmpty())
            <div class="card" style="border:1px solid #7aa7d9;background:linear-gradient(180deg,rgba(13,110,253,.06),transparent);">
                <div class="search-section-head">
                    <h3>🔗 Protected Lead Relationships ({{ $protectedRelationshipGroups->flatten()->count() }})</h3>
                    <div class="muted" style="font-size:12px;margin-top:4px;">These records are not duplicate candidates because they already have an existing lead relationship. They are intentionally excluded from reconciliation.</div>
                </div>
                @foreach ($protectedRelationshipGroups as $group)
                    @foreach ($group as $protected)
                        <div style="margin-top:10px;padding:12px;border:1px solid var(--c-border);border-radius:10px;background:var(--c-surface,#fff);">
                            <strong>Lead #{{ $protected->id }}</strong>
                            · {{ $protected->project?->name ?? 'No project' }}
                            · linked to
                            @if ($protected->parentLead)
                                <a href="{{ url('/leads/' . $protected->parentLead->id) }}">Lead #{{ $protected->parentLead->id }}</a>
                                <span class="muted">({{ $protected->parentLead->project?->name ?? 'another project' }})</span>
                            @else
                                <span class="muted">another lead</span>
                            @endif
                            <div class="muted" style="font-size:11px;margin-top:4px;">Existing relationship protected; review the linked lead separately before changing it.</div>
                            <a href="{{ url('/leads/' . $protected->id) }}" class="btn-small btn-info" style="margin-top:8px;">Open Lead #{{ $protected->id }}</a>
                        </div>
                    @endforeach
                @endforeach
            </div>
        @endif
    @endif

    @if ($reconciledLeads->isNotEmpty())
        <div class="card">
            <h3>♻️ Reconciled Duplicate History ({{ $reconciledLeads->count() }})</h3>
            <div class="muted" style="font-size:12px;margin-bottom:10px;">These records are retained for audit/history but are no longer counted as current customer enquiries.</div>
            <div class="table-wrap">
                <table>
                    <tr><th>Old Lead</th><th>Project</th><th>Reconciled into</th><th>Reason</th><th></th></tr>
                    @foreach ($reconciledLeads as $oldLead)
                        <tr>
                            <td>#{{ $oldLead->id }}</td>
                            <td>{{ $oldLead->project?->name ?? '—' }}</td>
                            <td><a href="{{ url('/leads/' . $oldLead->parent_lead_id) }}">Lead #{{ $oldLead->parent_lead_id }}</a></td>
                            <td style="font-size:12px;">{{ $oldLead->origin_note ?: '—' }}</td>
                            <td><a href="{{ url('/leads/' . $oldLead->id) }}" class="btn-small btn-info">History</a></td>
                        </tr>
                    @endforeach
                </table>
            </div>
        </div>
    @endif

    {{-- CUSTOMER 360 SUMMARY --}}
    @php
        $overdue = $leads->filter(fn($l) => $l->pendingFollowup?->isOverdue())->count();
        $withNext = $leads->filter(fn($l) => (bool) $l->pendingFollowup)->count();
        $recentLead = $leads->sortByDesc(fn($l) => $l->latestActivity?->logged_at ?? $l->created_at)->first();
    @endphp
    <div class="card">
        <h3>🧭 Customer 360 — What is happening now</h3>
        <div class="grid-stats">
            <x-stat-card label="👥 People working" :value="$leads->flatMap(fn($l) => $l->activeAgents->pluck('user.name'))->filter()->unique()->count()" />
            <x-stat-card label="⏭️ Pending next actions" :value="$withNext" variant="blue" />
            <x-stat-card label="🚨 Overdue" :value="$overdue" variant="red" />
            <x-stat-card label="🕒 Latest enquiry activity" :value="$recentLead?->latestActivity?->logged_at?->diffForHumans() ?? '—'" />
        </div>
        @if ($recentLead)
            <div style="margin-top:var(--s-3);padding:12px;border:1px solid var(--c-border);border-radius:10px;">
                <strong>Latest across this customer:</strong>
                🏗️ {{ $recentLead->project?->name ?? 'No project' }} ·
                {{ $recentLead->latestActivity?->displayLabel(80) ?? 'No activity yet' }} ·
                {{ $recentLead->latestActivity?->agent?->user?->name ?? 'System' }}
                @if ($recentLead->latestActivity) · {{ $recentLead->latestActivity->logged_at?->diffForHumans() }} @endif
                <a href="{{ url('/leads/' . $recentLead->id) }}" class="btn-small btn-info" style="float:right;">Open</a>
            </div>
        @endif
    </div>

    {{-- TIMELINE --}}
    <div class="card">
        <h3>📜 Recent Activity ({{ $timeline->count() }})</h3>

        @if ($timeline->isEmpty())
            <p class="muted">No activity across this customer's enquiries yet.</p>
        @else
            <div class="timeline">
                @foreach ($timeline as $act)
                    @php
                        $type = $act->type;
                        $atModel = app(\App\Services\SettingsService::class)->activityTypeByKey($type);
                        $icon = $atModel?->icon ?? '📝';
                        $label = $atModel?->label ?? $type;
                    @endphp
                    <div class="timeline-item {{ $type === 'status_change' ? 'status' : '' }}">
                        <div class="icon">{{ $icon }}</div>
                        <div class="content">
                            <div class="head">
                                <span class="who">
                                    {{ $label }} · {{ $act->agent?->user?->name ?? 'System' }}
                                </span>
                                <span class="when">
                                    {{ $act->logged_at?->diffForHumans() }}
                                </span>
                            </div>
                            <div class="muted" style="font-size:12px;">
                                🏗️ {{ $act->lead?->project?->name ?? '—' }}
                                · <a href="{{ url('/leads/' . $act->lead_id) }}">View lead</a>
                            </div>
                            @if ($act->outcome)
                                <div class="notes" style="font-size:13px;color:var(--c-text-2);margin-top:4px;">
                                    {{ $act->outcome }}
                                </div>
                            @endif
                            @if ($act->notes)
                                <div class="notes" style="margin-top:4px;white-space:pre-wrap;">{{ $act->notes }}</div>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </div>

@endsection