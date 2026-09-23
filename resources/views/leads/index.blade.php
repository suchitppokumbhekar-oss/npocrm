@extends('layouts.app')

@section('title', 'All Leads — NPO CRM')

@section('content')

    <a href="{{ session('user_role') === 'team_manager' ? url('/?scope=' . ($workScope ?? 'team')) : url('/') }}" class="back-link">← Back to Dashboard</a>

    {{-- TEAM MANAGER OPERATIONAL SCOPE --}}
    @if (session("user_role") === "team_manager")
        <nav class="task-scope-tabs" aria-label="Lead scope">
            <a href="{{ url("/leads?scope=team") }}" class="task-scope-tab {{ ($workScope ?? "team") === "team" ? "active" : "" }}">
                <strong>My Team</strong>
                <small>Direct team responsibility</small>
            </a>
            <a href="{{ url("/leads?scope=delegated") }}" class="task-scope-tab {{ ($workScope ?? "team") === "delegated" ? "active" : "" }}">
                <strong>All Delegated</strong>
                <small>Other teams &amp; agents in your scope</small>
            </a>
        </nav>
    @endif

    {{-- HEADER --}}
    <div class="card lead-directory-card">
        <div class="section-head">
            <h2 style="margin:0;">📋 Lead Directory</h2>
            <div style="display:flex;gap:8px;flex-wrap:wrap;">
                <button type="button" class="btn-small btn-info"
                        data-modal="add-lead">➕ New</button>
                @if (session('user_role') === 'admin')
                    <a href="{{ url('/export/leads') }}" class="btn-small btn-view">
                        📥 Export
                    </a>
                @endif
            </div>
        </div>

        {{-- CRM FILTERS --}}
        @php
            $selectedProject = ($projects ?? collect())->firstWhere('id', (int) request('project'));

            $activeFilterCount = collect([
                $searchFilter,
                $statusFilter,
                request('project'),
                request('agent_id'),
                request('tag_id'),
                request('label_id'),
                $dateRange !== 'all' ? $dateRange : null,
                request('date_from'),
                request('date_to'),
                request('sort'),
            ])->filter(fn ($v) => $v !== null && $v !== '')->count();
        @endphp

        <input type="checkbox"
               id="leads-filter-toggle"
               class="filter-toggle-input"
               @if ($activeFilterCount > 0) checked @endif>

        <label for="leads-filter-toggle" class="filter-toggle-label">
            <span>
                🔍 Search & Filters
                @if ($activeFilterCount > 0)
                    <span class="ft-count">({{ $activeFilterCount }})</span>
                @endif
            </span>
            <span class="ft-arrow">▼</span>
        </label>

        <form method="GET"
              action="{{ url('/leads') }}"
              class="filter-body crm-filter-form"
              data-crm-filter-form>
            @if (session('user_role') === 'team_manager')
                <input type="hidden" name="scope" value="{{ $workScope ?? 'team' }}">
            @endif

            <div class="crm-filter-search">
                <label for="lead-filter-search">Search</label>
                <div class="crm-filter-help">Find by name, phone, email, tag or label.</div>

                <div class="crm-filter-search-row">
                    <input type="search"
                           id="lead-filter-search"
                           name="search"
                           class="input"
                           value="{{ $searchFilter }}"
                           placeholder="Search leads…">

                    <button type="submit" class="btn-small btn-info">Search</button>
                </div>
            </div>

            <div class="crm-filter-grid">
                <div class="crm-filter-field">
                    <label>Status</label>
                    <div class="crm-filter-help">Choose the lead stage to show.</div>

                    <select name="status" class="input" data-filter-auto-submit>
                        <option value="">Active leads</option>

                        <optgroup label="Working">
                            @foreach ($statuses->where('is_final', false) as $s)
                                <option value="{{ $s->key }}" @selected($statusFilter === $s->key)>
                                    {{ $s->label }}
                                </option>
                            @endforeach
                        </optgroup>

                        <optgroup label="Closed / Terminal">
                            @foreach ($statuses->where('is_final', true) as $s)
                                <option value="{{ $s->key }}" @selected($statusFilter === $s->key)>
                                    {{ $s->label }}
                                </option>
                            @endforeach
                        </optgroup>
                    </select>
                </div>

                <div class="crm-filter-field">
                    <label>Project</label>
                    <div class="crm-filter-help">Search instead of scrolling through projects.</div>

                    <x-project-picker
                        name="project"
                        :selected-id="request('project')"
                        :selected-name="$selectedProject?->name"
                        :allow-all="true"
                        all-label="All projects"
                        :auto-submit="true"
                        search-context="lead_filter"
                        search-source="all"
                        :search-scope="$workScope"
                        placeholder="Search project…" />
                </div>

                @if (session('user_role') !== 'agent')
                    <div class="crm-filter-field">
                        <label>Agent</label>
                        <div class="crm-filter-help">Show leads assigned to one agent.</div>

                        <select name="agent_id" class="input" data-filter-auto-submit>
                            <option value="">All agents</option>

                            @foreach (($agents ?? collect()) as $a)
                                <option value="{{ $a->id }}"
                                        @selected((string) request('agent_id') === (string) $a->id)>
                                    {{ $a->user?->name ?? 'Agent #' . $a->id }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                @endif

                <div class="crm-filter-field">
                    <label>🏷️ Tag</label>
                    <div class="crm-filter-help">Narrow the list using a lead tag.</div>

                    <select name="tag_id" class="input" data-filter-auto-submit>
                        <option value="">All tags</option>

                        @foreach (($tags ?? collect()) as $t)
                            <option value="{{ $t->id }}"
                                    @selected((string) request('tag_id') === (string) $t->id)>
                                {{ $t->icon }} {{ $t->label }}
                            </option>
                        @endforeach
                    </select>
                </div>

                <div class="crm-filter-field">
                    <label>📌 Label</label>
                    <div class="crm-filter-help">Filter by your CRM classification.</div>

                    <select name="label_id" class="input" data-filter-auto-submit>
                        <option value="">All labels</option>

                        @foreach (($labelsFlat ?? collect()) as $lbl)
                            <option value="{{ $lbl->id }}"
                                    @selected((string) request('label_id') === (string) $lbl->id)>
                                {{ $lbl->group_label }} · {{ $lbl->label }}
                            </option>
                        @endforeach
                    </select>
                </div>
            </div>

            <div class="lead-date-filter crm-filter-date">
                <div class="lead-date-filter-title">📅 Lead date</div>
                <div class="crm-filter-help">Based on the date the lead was created.</div>

                <div class="lead-date-quick" role="group" aria-label="Lead created date">
                    @foreach ([
                        'all' => 'All',
                        'today' => 'Today',
                        '3d' => '3d',
                        '7d' => '7d',
                        '30d' => '30d',
                        '1y' => '1y',
                        'custom' => 'Custom',
                    ] as $key => $label)
                        <button type="button"
                                class="lead-date-chip {{ $dateRange === $key ? 'active' : '' }}"
                                data-date-range="{{ $key }}">
                            {{ $label }}
                        </button>
                    @endforeach
                </div>

                <input type="hidden"
                       name="date_range"
                       id="leads-date-range"
                       value="{{ $dateRange }}">

                <div class="lead-date-custom {{ $dateRange === 'custom' ? 'is-open' : '' }}"
                     id="leads-date-custom">

                    <div class="flex-item">
                        <label>From</label>
                        <input type="date" name="date_from" value="{{ $createdFrom }}" class="input">
                    </div>

                    <div class="flex-item">
                        <label>To</label>
                        <input type="date" name="date_to" value="{{ $createdTo }}" class="input">
                    </div>
                </div>
            </div>

            @if ($activeFilterCount > 0)
                <div class="crm-filter-footer">
                    <span class="muted">
                        {{ $activeFilterCount }} active {{ Str::plural('filter', $activeFilterCount) }}
                    </span>

                    <a href="{{ session('user_role') === 'team_manager' ? url('/leads?scope=' . ($workScope ?? 'team')) : url('/leads') }}" class="btn-small btn-ghost">Clear all</a>
                </div>
            @endif
        </form>
    </div>
    @if (session('user_role') === 'admin' && $statusFilter === 'lost')
        <div class="card" id="lead-bulk-toolbar" style="display:none;position:sticky;top:12px;z-index:20;border:1px solid var(--c-primary);box-shadow:0 8px 24px rgba(15,23,42,.12);">
            <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
                <strong>✓ <span id="bulk-selected-count">0</span> Lost leads selected</strong>
                <button type="button" class="btn-small btn-info" id="bulk-edit-lost-btn">✏️ Set Lost Reason</button>
                <button type="button" class="btn-small btn-ghost" id="bulk-clear-btn">Clear</button>
            </div>
        </div>
    @endif

    {{-- LEADS CARD --}}
    <div class="card lead-directory-card">
        <div class="section-head" style="margin-bottom:var(--s-3);">
            <div>
            <h3 style="margin:0;">
                📋 Results
                <span class="muted" style="font-size:12px;">
                    · {{ number_format($leads->total()) }} total
                    @if ($leads->total() > $leads->perPage())
                        · showing {{ $leads->firstItem() }}–{{ $leads->lastItem() }}
                    @endif
                </span>
            </h3>
            @if (!$statusFilter)
                <div class="muted" style="font-size:11px;margin-top:4px;">Active leads are shown by default. Use a status filter to view booked, lost or other closed leads.</div>
            @endif
            </div>
        </div>

        {{-- DESKTOP TABLE --}}
        <div class="table-wrap">
            <table class="leads-table">
                                    <tr>
                    @if (session('user_role') === 'admin' && $statusFilter === 'lost')<th style="width:42px;"><input type="checkbox" id="bulk-select-all" aria-label="Select all visible lost leads"></th>@endif
                    <th class="lead-name-th">Lead</th>
                    <th>Phone</th>
                    <th>Status</th>
                    <th>Agent</th>
                    <th>Actions</th>
                </tr>
                @forelse ($leads as $lead)
                    <x-lead-row :lead="$lead" />
                                    @empty
                    <tr>
                        <td colspan="5" style="text-align:center;padding:32px;" class="muted">
                            No leads match.
                        </td>
                    </tr>
                @endforelse
            </table>
        </div>

        {{-- MOBILE CARDS --}}
        @forelse ($leads as $lead)
            <x-lead-card :lead="$lead" />
        @empty
            <p class="muted" style="padding:24px;text-align:center;">No leads match.</p>
        @endforelse

        {{-- PAGINATION --}}
        @if (method_exists($leads, 'hasPages') && $leads->hasPages())
            <div style="display:flex;justify-content:space-between;align-items:center;margin-top:var(--s-3);gap:var(--s-2);flex-wrap:wrap;">
                <div class="muted" style="font-size:12px;">
                    Page {{ $leads->currentPage() }} of {{ $leads->lastPage() }}
                </div>
                <div style="display:flex;gap:6px;flex-wrap:wrap;">
                    @if ($leads->onFirstPage())
                        <span class="btn-small" style="opacity:.4;cursor:not-allowed;">← Prev</span>
                    @else
                        <a href="{{ $leads->previousPageUrl() }}" class="btn-small btn-view">← Prev</a>
                    @endif

                    @php
                        $start = max(1, $leads->currentPage() - 2);
                        $end   = min($leads->lastPage(), $leads->currentPage() + 2);
                    @endphp

                    @for ($p = $start; $p <= $end; $p++)
                        @if ($p == $leads->currentPage())
                            <span class="btn-small" style="background:var(--c-primary);color:#fff;">{{ $p }}</span>
                        @else
                            <a href="{{ $leads->url($p) }}" class="btn-small btn-view">{{ $p }}</a>
                        @endif
                    @endfor

                    @if ($leads->hasMorePages())
                        <a href="{{ $leads->nextPageUrl() }}" class="btn-small btn-view">Next →</a>
                    @else
                        <span class="btn-small" style="opacity:.4;cursor:not-allowed;">Next →</span>
                    @endif
                </div>
            </div>
        @else
            <p class="muted" style="margin-top:var(--s-3);font-size:12px;text-align:center;">
                Showing {{ $leads->count() }} lead{{ $leads->count() === 1 ? '' : 's' }}
            </p>
        @endif
    </div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var range = document.getElementById('leads-date-range');
    var custom = document.getElementById('leads-date-custom');
    if (!range || !custom) return;
    document.querySelectorAll('[data-date-range]').forEach(function (button) {
        button.addEventListener('click', function () {
            range.value = button.dataset.dateRange || 'all';
            document.querySelectorAll('[data-date-range]').forEach(function (b) { b.classList.remove('active'); });
            button.classList.add('active');
            custom.classList.toggle('is-open', range.value === 'custom');
        });
    });
});
</script>

@endsection