@extends('layouts.app')

@section('title', 'My Leads — NPO CRM')

@section('content')

    @php
        $mode = $viewMode ?? request('view');
        if ($mode === 'working' || $mode === 'booked' || $mode === 'all') {
            $activeMode = $mode;
        } else {
            $activeMode = 'working';
        }

        $modeUrls = [
            'working' => url('/my-leads?view=working#lead-results'),
            'booked'  => url('/my-leads?view=booked#lead-results'),
            'all'     => url('/my-leads?view=all#lead-results'),
        ];

        $resultTitle = match ($activeMode) {
            'booked' => 'Booked leads',
            'all'    => 'All my leads',
            default  => 'Working leads',
        };
    @endphp

    <div class="lead-desk" id="lead-desk">
        <a href="{{ url('/') }}" class="back-link">← Back to My Work</a>

        {{-- PURPOSE --}}
        <section class="lead-desk-hero">
            <div>
                <div class="lead-desk-kicker">LEAD DESK</div>
                <h2>Find a lead. Open it. Work it.</h2>
                <p>My Leads is for finding and managing your lead book. Daily tasks, overdue work and today's priorities live in <strong>My Work</strong>.</p>
            </div>
            <button type="button" class="btn-small btn-info" data-modal="add-lead">➕ New Lead</button>
        </section>

        {{-- PRIMARY LEAD VIEWS — deliberately only three. Tasks do not belong here. --}}
        <nav class="lead-view-tabs" aria-label="Lead views">
            <a href="{{ $modeUrls['working'] }}" class="lead-view-tab {{ $activeMode === 'working' ? 'active' : '' }}">
                <span class="lead-view-tab-label">🔥 Working</span>
                <strong>{{ number_format($stats['active']) }}</strong>
                <small>Need attention</small>
            </a>
            <a href="{{ $modeUrls['booked'] }}" class="lead-view-tab {{ $activeMode === 'booked' ? 'active' : '' }}">
                <span class="lead-view-tab-label">🎉 Booked</span>
                <strong>{{ number_format($stats['booked']) }}</strong>
                <small>Won / booked</small>
            </a>
            <a href="{{ $modeUrls['all'] }}" class="lead-view-tab {{ $activeMode === 'all' ? 'active' : '' }}">
                <span class="lead-view-tab-label">📚 All</span>
                <strong>{{ number_format($stats['total']) }}</strong>
                <small>Entire lead book</small>
            </a>
        </nav>

        {{-- SHARED CRM SEARCH & FILTERS --}}
        @php
            $selectedProject = ($projects ?? collect())->firstWhere('id', (int) request('project'));

            $activeFilterCount = collect([
                $searchFilter,
                $statusFilter,
                request('project'),
                request('tag_id'),
                request('label_id'),
                $dateRange !== 'all' ? $dateRange : null,
                request('date_from'),
                request('date_to'),
                request('sort'),
            ])->filter(fn ($v) => $v !== null && $v !== '')->count();
        @endphp

        <section class="card lead-desk-search">
            <input type="checkbox"
                   id="my-leads-filter-toggle"
                   class="filter-toggle-input"
                   @if ($activeFilterCount > 0) checked @endif>

            <label for="my-leads-filter-toggle" class="filter-toggle-label">
                <span>
                    🔍 Search & Filters
                    @if ($activeFilterCount > 0)
                        <span class="ft-count">({{ $activeFilterCount }})</span>
                    @endif
                </span>
                <span class="ft-arrow">▼</span>
            </label>

            <form method="GET"
                  action="{{ url('/my-leads') }}#lead-results"
                  class="filter-body crm-filter-form"
                  data-crm-filter-form>

                <input type="hidden" name="view" value="{{ $activeMode }}">

                <div class="crm-filter-search">
                    <label for="my-leads-search">Search</label>
                    <div class="crm-filter-help">Find by name, phone, email, tag or label.</div>

                    <div class="crm-filter-search-row">
                        <input type="search"
                               id="my-leads-search"
                               name="search"
                               class="input"
                               value="{{ $searchFilter }}"
                               placeholder="Search leads…"
                               autocomplete="off">

                        <button type="submit" class="btn-small btn-info">Search</button>
                    </div>
                </div>

                <div class="crm-filter-grid">
                    <div class="crm-filter-field">
                        <label>Status</label>
                        <div class="crm-filter-help">Choose a specific stage within this view.</div>

                        <select name="status" class="input" data-filter-auto-submit>
                            <option value="">Use {{ ucfirst($activeMode) }} view</option>
                            @foreach ($statuses as $st)
                                <option value="{{ $st->key }}"
                                        @selected($statusFilter === $st->key)>
                                    {{ $st->label }}
                                </option>
                            @endforeach
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
                            search-source="mine"
                            placeholder="Search project…" />
                    </div>

                    <div class="crm-filter-field">
                        <label>🏷️ Tag</label>
                        <div class="crm-filter-help">Narrow your lead book using a tag.</div>

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

                    <div class="crm-filter-field">
                        <label>Sort</label>
                        <div class="crm-filter-help">Choose how your leads are ordered.</div>

                        <select name="sort" class="input" data-filter-auto-submit>
                            <option value="newest" @selected(($sort ?? 'newest') === 'newest')>Newest</option>
                            <option value="oldest" @selected(($sort ?? '') === 'oldest')>Oldest</option>
                            <option value="updated" @selected(($sort ?? '') === 'updated')>Recently updated</option>
                            <option value="active" @selected(($sort ?? '') === 'active')>Recent activity</option>
                            <option value="visit" @selected(($sort ?? '') === 'visit')>Next visit</option>
                            <option value="booking" @selected(($sort ?? '') === 'booking')>Booking date</option>
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
                           id="my-leads-date-range"
                           value="{{ $dateRange }}">

                    <div class="lead-date-custom {{ $dateRange === 'custom' ? 'is-open' : '' }}"
                         id="my-leads-date-custom">
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

                        <a href="{{ $modeUrls[$activeMode] }}" class="btn-small btn-ghost">
                            Clear all
                        </a>
                    </div>
                @endif
            </form>
        </section>

        {{-- RESULTS --}}
        <section class="card lead-results-card" id="lead-results">
            <div class="lead-results-head">
                <div>
                    <div class="lead-results-kicker">YOUR LEAD BOOK</div>
                    <h3>{{ $resultTitle }}</h3>
                    <p>{{ number_format($leads->total()) }} lead{{ $leads->total() === 1 ? '' : 's' }} · tap a lead to continue the work.</p>
                </div>
                @if ($searchFilter)
                    <div class="lead-active-search">🔎 “{{ $searchFilter }}”</div>
                @endif
            </div>

            {{-- SAME LEAD DIRECTORY PRESENTATION AS ALL LEADS --}}
            <div class="table-wrap">
                <table class="leads-table">
                    <tr>
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
                                No leads match this view.
                            </td>
                        </tr>
                    @endforelse
                </table>
            </div>

            {{-- MOBILE — shared with All Leads --}}
            @forelse ($leads as $lead)
                <x-lead-card :lead="$lead" />
            @empty
                <div class="my-leads-empty-mobile">
                    <div class="my-leads-empty-icon">🔎</div>
                    <strong>No leads here</strong>
                    <p>Try another view or search for the customer by name, phone or email.</p>
                    <a href="{{ $modeUrls['working'] }}" class="btn-small btn-ghost">Back to Working</a>
                </div>
            @endforelse
            @if ($leads->hasPages())
                <div class="lead-desk-pagination">
                    <div class="muted">Page {{ $leads->currentPage() }} of {{ $leads->lastPage() }}</div>
                    <div class="lead-desk-pagination-links">
                        @if ($leads->onFirstPage())
                            <span class="btn-small" style="opacity:.4;">← Prev</span>
                        @else
                            <a href="{{ $leads->previousPageUrl() }}#lead-results" class="btn-small btn-ghost">← Prev</a>
                        @endif
                        <span class="lead-page-current">{{ $leads->currentPage() }}</span>
                        @if ($leads->hasMorePages())
                            <a href="{{ $leads->nextPageUrl() }}#lead-results" class="btn-small btn-ghost">Next →</a>
                        @else
                            <span class="btn-small" style="opacity:.4;">Next →</span>
                        @endif
                    </div>
                </div>
            @endif
        </section>
    </div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var range = document.getElementById('my-leads-date-range');
    var custom = document.getElementById('my-leads-date-custom');
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
