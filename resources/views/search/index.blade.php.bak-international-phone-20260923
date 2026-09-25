@extends('layouts.app')

@section('title', 'Search — NPO CRM')

@section('content')
<div class="search-page">

    <a href="{{ url('/') }}" class="back-link">← Back to dashboard</a>

    {{-- SEARCH BAR --}}
    <div class="card">
        <form method="GET" action="{{ url('/search') }}" id="search-form">
            <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
                <div style="flex:1 1 300px;position:relative;">
                    <input type="search"
                           id="search-input"
                           name="q"
                           class="input"
                           value="{{ $q }}"
                           placeholder="Search leads, contacts, projects, tags (e.g. hot, 2bhk)…"
                           autocomplete="off"
                           autofocus>
                    <kbd class="search-kbd-hint">/</kbd>
                </div>
                <button type="submit" class="btn-small btn-info" style="min-height:44px;padding:0 22px;">
                    🔍 Search
                </button>
                <div style="display:flex;gap:6px;flex-wrap:wrap;align-items:end;">
                    <div><label style="font-size:10px;font-weight:700;display:block;">Lead from</label><input type="date" name="created_from" value="{{ $createdFrom ?? request('created_from') }}" class="input" style="min-width:135px;"></div>
                    <div><label style="font-size:10px;font-weight:700;display:block;">Lead to</label><input type="date" name="created_to" value="{{ $createdTo ?? request('created_to') }}" class="input" style="min-width:135px;"></div>
                </div>
            </div>
        </form>

        @if (mb_strlen($q) >= 2)
            <div class="search-summary">
                <strong>{{ number_format($total) }}</strong> result{{ $total === 1 ? '' : 's' }} for
                <strong>"{{ $q }}"</strong>
            </div>
        @elseif ($q !== '')
            <p class="muted" style="margin:var(--s-3) 0 0;font-size:12px;">
                Type at least 2 characters.
            </p>
        @endif

        @if (mb_strlen($q) >= 2)
            <div class="search-filters">
                @php
                    $chips = [
                        'all'       => '🗂️ All',
                        'customers' => '👤 Customers',
                        'leads'     => '📋 Leads',
                        'contacts'  => '📇 Contacts',
                        'projects'  => '🏗️ Projects',
                        'agents'    => '👥 Agents',
                        'teams'     => '👨‍👩‍👧 Teams',
                    ];
                @endphp
                @foreach ($chips as $key => $label)
                    @php
                        $count = $key === 'all' ? $total : ($results[$key]['total'] ?? 0);
                        $active = $typeFilter === $key;
                        if ($key !== 'all' && $count === 0) continue;
                    @endphp
                    <a href="{{ url('/search?q=' . urlencode($q) . '&type=' . $key . (($createdFrom ?? '') !== '' ? '&created_from=' . urlencode($createdFrom) : '') . (($createdTo ?? '') !== '' ? '&created_to=' . urlencode($createdTo) : '')) }}"
                       class="search-chip {{ $active ? 'active' : '' }}">
                        {{ $label }}
                        <span class="search-chip-count">{{ $count }}</span>
                    </a>
                @endforeach
            </div>
        @endif
    </div>

    @if (mb_strlen($q) >= 2)

        {{-- ============================================================ --}}
        {{-- CUSTOMERS --}}
        {{-- ============================================================ --}}
        @if ($results['customers']['total'] > 0)
            <div class="card">
                <div class="search-section-head">
                    <h3>👤 Customers ({{ $results['customers']['total'] }})</h3>
                    @if ($results['customers']['total'] > 25)
                        <span class="muted" style="font-size:12px;">showing top 25</span>
                    @endif
                </div>

                <div class="table-wrap">
                    <table>
                        <tr>
                            <th>Customer</th>
                            <th>Phone</th>
                            <th>Email</th>
                            <th style="text-align:center;">Enquiries</th>
                            <th>Last seen</th>
                            <th></th>
                        </tr>
                        @foreach ($results['customers']['items'] as $c)
                            <tr>
                                <td><strong>{{ $c->name }}</strong></td>
                                <td>{{ $c->phone }}</td>
                                <td><span class="muted" style="font-size:12px;">{{ $c->email ?? '—' }}</span></td>
                                <td style="text-align:center;">
                                    <span class="badge {{ $c->leads_count > 1 ? 'purple' : 'blue' }}">
                                        {{ $c->leads_count }}
                                    </span>
                                </td>
                                <td>
                                    <span class="muted" style="font-size:12px;">
                                        {{ $c->last_seen_at?->diffForHumans() ?? '—' }}
                                    </span>
                                </td>
                                <td>
                                    <a href="{{ url('/customers/' . $c->id) }}" class="btn-small btn-info">Open</a>
                                </td>
                            </tr>
                        @endforeach
                    </table>
                </div>

                {{-- Mobile cards --}}
                @foreach ($results['customers']['items'] as $c)
                    <a href="{{ url('/customers/' . $c->id) }}" class="search-mobile-row">
                        <div style="flex:1;min-width:0;">
                            <div style="font-weight:700;">{{ $c->name }}</div>
                            <div class="muted" style="font-size:12px;">{{ $c->phone }}</div>
                            <div style="margin-top:4px;display:flex;gap:6px;flex-wrap:wrap;">
                                <span class="badge {{ $c->leads_count > 1 ? 'purple' : 'blue' }}" style="font-size:11px;">
                                    {{ $c->leads_count }} enquiries
                                </span>
                                <span class="muted" style="font-size:11px;align-self:center;">
                                    {{ $c->last_seen_at?->diffForHumans() }}
                                </span>
                            </div>
                        </div>
                        <span class="search-mobile-chev">→</span>
                    </a>
                @endforeach
            </div>
        @endif

        {{-- ============================================================ --}}
        {{-- LEADS --}}
        {{-- ============================================================ --}}
        @if ($results['leads']['total'] > 0)
            <div class="card">
                <div class="search-section-head">
                    <h3>📋 Leads ({{ $results['leads']['total'] }})</h3>
                    @if ($results['leads']['total'] > 25)
                        <a href="{{ url('/leads?search=' . urlencode($q)) }}" class="search-viewall">
                            View all {{ $results['leads']['total'] }} →
                        </a>
                    @endif
                </div>

                <div class="table-wrap">
                    <table>
                        <tr>
                            <th>Name</th>
                            <th>Phone</th>
                            <th>Project</th>
                            <th>Agent</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                        @foreach ($results['leads']['items'] as $lead)
                            @php $s = app(\App\Services\SettingsService::class); @endphp
                            <tr>
                                <td>
                                    <a href="{{ url('/leads/' . $lead->id) }}" style="font-weight:700;color:inherit;">
                                        {{ $lead->customer_name }}
                                    </a>

                                    @if ($lead->tag)
                                        <x-lead-tag-chip :tag="$lead->tag" style="margin-left:6px;" />
                                    @endif

                                    @if ($lead->labels->isNotEmpty())
                                        <x-lead-labels-row :labels="$lead->labels" :max="3" />
                                    @endif

                                    @if ($lead->otherEnquiriesCount() > 0)
                                        <br>
                                        <a href="{{ url('/customers/' . $lead->customer_id) }}"
                                           class="lead-related-link"
                                           title="See customer's other enquiries">
                                            👥 +{{ $lead->otherEnquiriesCount() }} other enquir{{ $lead->otherEnquiriesCount() === 1 ? 'y' : 'ies' }}
                                        </a>
                                    @endif
                                </td>
                                <td>{{ $lead->phone }}</td>
                                <td>
                                    <span class="muted" style="font-size:12px;">
                                        {{ $lead->project?->name ?? '—' }}
                                    </span>
                                </td>
                                <td>
                                    @if ($lead->agent?->user?->name)
                                        <span class="badge purple" style="font-size:11px;">
                                            👤 {{ $lead->agent->user->name }}
                                        </span>
                                    @else
                                        <span class="badge red" style="font-size:11px;">⚠️ Unassigned</span>
                                    @endif
                                </td>
                                <td>
                                    <span class="badge {{ $s->statusColor($lead->statusKey()) }}">
                                        {{ $s->statusLabel($lead->statusKey()) }}
                                    </span>
                                </td>
                                <td class="row-actions">
                                    <x-lead-work-actions :lead="$lead" />
                                </td>
                            </tr>
                        @endforeach
                    </table>
                </div>

                {{-- Mobile cards --}}
                @foreach ($results['leads']['items'] as $lead)
                    @php $s = app(\App\Services\SettingsService::class); @endphp
                    <div class="search-mobile-card">
                        <div class="search-mobile-card-head">
                            <a href="{{ url('/leads/' . $lead->id) }}" class="search-mobile-title">
                                {{ $lead->customer_name }}
                            </a>
                            <span class="badge {{ $s->statusColor($lead->statusKey()) }}">
                                {{ $s->statusLabel($lead->statusKey()) }}
                            </span>
                        </div>

                        @if ($lead->tag || $lead->labels->isNotEmpty())
                            <div class="lead-card-chips">
                                <x-lead-tag-chip :tag="$lead->tag" />
                                <x-lead-labels-row :labels="$lead->labels" :max="3" />
                            </div>
                        @endif

                        <div class="muted" style="font-size:12px;">
                            📱 {{ $lead->phone }}
                            @if ($lead->project?->name) · 🏗️ {{ $lead->project->name }} @endif
                        </div>
                        <div style="margin-top:4px;display:flex;gap:6px;flex-wrap:wrap;">
                            @if ($lead->agent?->user?->name)
                                <span class="badge purple" style="font-size:11px;">👤 {{ $lead->agent->user->name }}</span>
                            @else
                                <span class="badge red" style="font-size:11px;">⚠️ Unassigned</span>
                            @endif
                            @if ($lead->otherEnquiriesCount() > 0)
                                <a href="{{ url('/customers/' . $lead->customer_id) }}"
                                   class="badge purple" style="font-size:11px;text-decoration:none;">
                                    👥 +{{ $lead->otherEnquiriesCount() }}
                                </a>
                            @endif
                        </div>
                        <div class="search-mobile-actions">
                            <x-lead-work-actions :lead="$lead" />
                        </div>
                    </div>
                @endforeach
            </div>
        @endif

        {{-- ============================================================ --}}
        {{-- CONTACTS --}}
        {{-- ============================================================ --}}
        @if ($results['contacts']['total'] > 0)
            <div class="card">
                <div class="search-section-head">
                    <h3>📇 Contacts ({{ $results['contacts']['total'] }})</h3>
                    @if ($results['contacts']['total'] > 25)
                        <a href="{{ url('/contacts?q=' . urlencode($q)) }}" class="search-viewall">
                            View all {{ $results['contacts']['total'] }} →
                        </a>
                    @endif
                </div>

                <div class="table-wrap">
                    <table>
                        <tr>
                            <th>Name</th>
                            <th>Phone</th>
                            <th>Project</th>
                            <th>Assigned</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                        @foreach ($results['contacts']['items'] as $c)
                            <tr>
                                <td>
                                    <a href="{{ url('/contacts/' . $c->id) }}" style="font-weight:700;color:inherit;">
                                        {{ $c->name }}
                                    </a>
                                </td>
                                <td>{{ $c->phone }}</td>
                                <td><span class="muted" style="font-size:12px;">{{ $c->project?->name ?? '—' }}</span></td>
                                <td>
                                    @if ($c->agent?->user?->name)
                                        <span class="badge purple" style="font-size:11px;">👤 {{ $c->agent->user->name }}</span>
                                    @else
                                        <span class="muted">—</span>
                                    @endif
                                </td>
                                <td>
                                    <span class="badge {{ $c->status === 'converted' ? 'green' : ($c->status === 'new' ? 'blue' : 'orange') }}">
                                        {{ ucfirst(str_replace('_',' ',$c->status)) }}
                                    </span>
                                </td>
                                <td class="row-actions">
                                    @if ($c->phone)
                                        <a href="tel:{{ $c->phone }}" class="contact-btn contact-btn-call contact-btn-sm"
                                           title="Call">📞</a>
                                    @endif
                                    <a href="{{ url('/contacts/' . $c->id) }}" class="btn-small btn-info">Open</a>
                                </td>
                            </tr>
                        @endforeach
                    </table>
                </div>

                {{-- Mobile cards --}}
                @foreach ($results['contacts']['items'] as $c)
                    <div class="search-mobile-card">
                        <div class="search-mobile-card-head">
                            <a href="{{ url('/contacts/' . $c->id) }}" class="search-mobile-title">{{ $c->name }}</a>
                            <span class="badge {{ $c->status === 'converted' ? 'green' : ($c->status === 'new' ? 'blue' : 'orange') }}">
                                {{ ucfirst(str_replace('_',' ',$c->status)) }}
                            </span>
                        </div>
                        <div class="muted" style="font-size:12px;">
                            📱 {{ $c->phone }}
                            @if ($c->project?->name) · 🏗️ {{ $c->project->name }} @endif
                        </div>
                        <div class="search-mobile-actions">
                            @if ($c->phone)
                                <a href="tel:{{ $c->phone }}" class="contact-btn contact-btn-call contact-btn-lg">📞 Call</a>
                            @endif
                            <a href="{{ url('/contacts/' . $c->id) }}" class="contact-btn contact-btn-lg">👁 Open</a>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif

        {{-- ============================================================ --}}
        {{-- PROJECTS --}}
        {{-- ============================================================ --}}
        @if ($results['projects']['total'] > 0)
            <div class="card">
                <div class="search-section-head">
                    <h3>🏗️ Projects ({{ $results['projects']['total'] }})</h3>
                    @if ($results['projects']['total'] > 25)
                        <span class="muted" style="font-size:12px;">showing top 25</span>
                    @endif
                </div>

                <div class="table-wrap">
                    <table>
                        <tr>
                            <th>Project</th>
                            <th>Location</th>
                            <th style="text-align:center;">Leads</th>
                            <th style="text-align:center;">Direct</th>
                            <th style="text-align:center;">Teams</th>
                            <th>Status</th>
                            <th></th>
                        </tr>
                        @foreach ($results['projects']['items'] as $p)
                            <tr>
                                <td><strong>{{ $p->name }}</strong></td>
                                <td><span class="muted" style="font-size:12px;">{{ $p->location ?? '—' }}</span></td>
                                <td style="text-align:center;">
                                    <a href="{{ url('/leads?project=' . $p->id) }}" class="badge blue" style="text-decoration:none;">
                                        {{ $p->leads_count }}
                                    </a>
                                </td>
                                <td style="text-align:center;">
                                    @if ($p->active_direct_agents_count > 0)
                                        <span class="badge purple">{{ $p->active_direct_agents_count }}</span>
                                    @else
                                        <span class="muted">—</span>
                                    @endif
                                </td>
                                <td style="text-align:center;">
                                    @if ($p->active_teams_count > 0)
                                        <span class="badge blue">{{ $p->active_teams_count }}</span>
                                    @else
                                        <span class="muted">—</span>
                                    @endif
                                </td>
                                <td>
                                    <span class="badge {{ $p->status === 'active' ? 'green' : 'blue' }}">
                                        {{ ucfirst($p->status ?? 'active') }}
                                    </span>
                                </td>
                                <td class="row-actions">
                                    @if (session('user_role') === 'admin')
                                        <button type="button" class="btn-small btn-info"
                                                data-modal="project-routing" data-project="{{ $p->id }}">
                                            ⚙️ Routing
                                        </button>
                                    @endif
                                    <a href="{{ url('/leads?project=' . $p->id) }}" class="btn-small btn-view">
                                        📋 Leads
                                    </a>
                                </td>
                            </tr>
                        @endforeach
                    </table>
                </div>

                {{-- Mobile cards --}}
                @foreach ($results['projects']['items'] as $p)
                    <div class="search-mobile-card">
                        <div class="search-mobile-card-head">
                            <a href="{{ url('/leads?project=' . $p->id) }}" class="search-mobile-title">
                                {{ $p->name }}
                            </a>
                            <span class="badge {{ $p->status === 'active' ? 'green' : 'blue' }}">
                                {{ ucfirst($p->status ?? 'active') }}
                            </span>
                        </div>
                        <div class="muted" style="font-size:12px;">📍 {{ $p->location ?? '—' }}</div>
                        <div style="margin-top:6px;display:flex;gap:6px;flex-wrap:wrap;">
                            <span class="badge blue" style="font-size:11px;">{{ $p->leads_count }} leads</span>
                            @if ($p->active_direct_agents_count > 0)
                                <span class="badge purple" style="font-size:11px;">{{ $p->active_direct_agents_count }} direct</span>
                            @endif
                            @if ($p->active_teams_count > 0)
                                <span class="badge blue" style="font-size:11px;">{{ $p->active_teams_count }} team(s)</span>
                            @endif
                        </div>
                        <div class="search-mobile-actions">
                            <a href="{{ url('/leads?project=' . $p->id) }}" class="contact-btn contact-btn-lg">📋 View leads</a>
                            @if (session('user_role') === 'admin')
                                <button type="button" class="contact-btn contact-btn-lg"
                                        data-modal="project-routing" data-project="{{ $p->id }}">
                                    ⚙️ Routing
                                </button>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        @endif

        {{-- ============================================================ --}}
        {{-- AGENTS --}}
        {{-- ============================================================ --}}
        @if ($results['agents']['total'] > 0)
            <div class="card">
                <div class="search-section-head">
                    <h3>👥 Agents ({{ $results['agents']['total'] }})</h3>
                    @if ($results['agents']['total'] > 25)
                        <span class="muted" style="font-size:12px;">showing top 25</span>
                    @endif
                </div>

                <div class="table-wrap">
                    <table>
                        <tr>
                            <th>Agent</th>
                            <th>Email</th>
                            <th>Team</th>
                            <th>Status</th>
                            <th>Load</th>
                            <th>Actions</th>
                        </tr>
                        @foreach ($results['agents']['items'] as $a)
                            <tr>
                                <td><strong>{{ $a->user?->name ?? 'Agent #' . $a->id }}</strong></td>
                                <td><span class="muted" style="font-size:12px;">{{ $a->user?->email ?? '—' }}</span></td>
                                <td>{{ $a->teams->first()->name ?? '—' }}</td>
                                <td>
                                    <span class="badge {{ $a->status === 'active' ? 'green' : 'red' }}">
                                        {{ ucfirst($a->status) }}
                                    </span>
                                </td>
                                <td>
                                    <span class="badge {{ $a->current_load >= $a->max_daily_leads ? 'red' : 'blue' }}">
                                        {{ $a->current_load }}/{{ $a->max_daily_leads }}
                                    </span>
                                </td>
                                <td class="row-actions">
                                    <a href="{{ url('/leads?agent_id=' . $a->id) }}" class="btn-small btn-info">
                                        📋 Leads
                                    </a>
                                    <a href="{{ url('/tasks?agent_id=' . $a->id) }}" class="btn-small btn-view">
                                        ✅ Tasks
                                    </a>
                                </td>
                            </tr>
                        @endforeach
                    </table>
                </div>

                {{-- Mobile cards --}}
                @foreach ($results['agents']['items'] as $a)
                    <div class="search-mobile-card">
                        <div class="search-mobile-card-head">
                            <span class="search-mobile-title">{{ $a->user?->name ?? 'Agent #' . $a->id }}</span>
                            <span class="badge {{ $a->status === 'active' ? 'green' : 'red' }}">
                                {{ ucfirst($a->status) }}
                            </span>
                        </div>
                        <div class="muted" style="font-size:12px;">{{ $a->user?->email ?? '—' }}</div>
                        <div style="margin-top:6px;display:flex;gap:6px;flex-wrap:wrap;">
                            <span class="badge {{ $a->current_load >= $a->max_daily_leads ? 'red' : 'blue' }}" style="font-size:11px;">
                                {{ $a->current_load }}/{{ $a->max_daily_leads }} load
                            </span>
                            @if ($a->teams->first())
                                <span class="badge blue" style="font-size:11px;">{{ $a->teams->first()->name }}</span>
                            @endif
                        </div>
                        <div class="search-mobile-actions">
                            <a href="{{ url('/leads?agent_id=' . $a->id) }}" class="contact-btn contact-btn-lg">📋 Leads</a>
                            <a href="{{ url('/tasks?agent_id=' . $a->id) }}" class="contact-btn contact-btn-lg">✅ Tasks</a>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif

        {{-- ============================================================ --}}
        {{-- TEAMS --}}
        {{-- ============================================================ --}}
        @if ($results['teams']['total'] > 0)
            <div class="card">
                <div class="search-section-head">
                    <h3>👨‍👩‍👧 Teams ({{ $results['teams']['total'] }})</h3>
                </div>

                <div class="table-wrap">
                    <table>
                        <tr>
                            <th>Team</th>
                            <th>Manager</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                        @foreach ($results['teams']['items'] as $t)
                            <tr>
                                <td><strong>{{ $t->name }}</strong></td>
                                <td>{{ $t->manager?->name ?? '—' }}</td>
                                <td>
                                    <span class="badge {{ $t->is_active ? 'green' : 'red' }}">
                                        {{ $t->is_active ? 'Active' : 'Inactive' }}
                                    </span>
                                </td>
                                <td>
                                    @if (session('user_role') === 'admin')
                                        <a href="{{ url('/my-team?team=' . $t->id) }}" class="btn-small btn-info">Open</a>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </table>
                </div>

                @foreach ($results['teams']['items'] as $t)
                    <div class="search-mobile-card">
                        <div class="search-mobile-card-head">
                            <span class="search-mobile-title">{{ $t->name }}</span>
                            <span class="badge {{ $t->is_active ? 'green' : 'red' }}">
                                {{ $t->is_active ? 'Active' : 'Inactive' }}
                            </span>
                        </div>
                        <div class="muted" style="font-size:12px;">Manager: {{ $t->manager?->name ?? '—' }}</div>
                    </div>
                @endforeach
            </div>
        @endif

        {{-- EMPTY STATE --}}
        @if ($total === 0)
            <div class="card" style="text-align:center;padding:var(--s-5) var(--s-4);">
                <div style="font-size:40px;">🔍</div>
                <h3 style="margin-top:8px;">No results for "{{ $q }}"</h3>
                <p class="muted" style="margin-top:4px;">
                    Try a name, phone, project, RERA, or tag/label (e.g. <strong>hot</strong>, <strong>2bhk</strong>, <strong>investor</strong>).
                    @if (session('user_role') !== 'admin')
                        <br>You only see results you have access to.
                    @endif
                </p>
            </div>
        @endif

    @endif

</div>

<script>
(function () {
    'use strict';

    var input = document.getElementById('search-input');
    if (!input) return;

    document.addEventListener('keydown', function (e) {
        var tag = (document.activeElement && document.activeElement.tagName) || '';
        if (/INPUT|TEXTAREA|SELECT/.test(tag)) return;

        if (e.key === '/') {
            e.preventDefault();
            input.focus();
            input.select();
        }
    });

    input.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') input.blur();
    });

    var form = document.getElementById('search-form');
    if (form) {
        form.addEventListener('submit', function () {
            var q = input.value.trim();
            if (q.length < 2) return;
            try {
                var key = 'npocrm_recent_searches';
                var list = JSON.parse(localStorage.getItem(key) || '[]');
                list = list.filter(function (x) { return x !== q; });
                list.unshift(q);
                list = list.slice(0, 5);
                localStorage.setItem(key, JSON.stringify(list));
            } catch (e) {}
        });
    }
})();
</script>

@endsection