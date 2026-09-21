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

        {{-- SEARCH FIRST. FILTERS ARE SECONDARY. --}}
        <section class="lead-desk-search card">
            <form method="GET" action="{{ url('/my-leads') }}#lead-results">
                <input type="hidden" name="view" value="{{ $activeMode }}">
                <label for="my-leads-search" class="lead-desk-search-label">Find a customer</label>
                <div class="lead-desk-search-row">
                    <div class="lead-desk-search-input-wrap">
                        <span aria-hidden="true">🔍</span>
                        <input id="my-leads-search" type="search" name="search" class="input" value="{{ $searchFilter }}" placeholder="Name, phone or email" autocomplete="off">
                    </div>
                    <button type="submit" class="btn lead-desk-search-btn">Search</button>
                </div>
            </form>

            <details class="lead-filter-drawer" {{ ($statusFilter || $projectFilter || request('tag_id') || request('label_id') || $dateRange !== 'all' || request('sort')) ? 'open' : '' }}>
                <summary>⚙️ Filters & sorting <span>Tap only when you need them</span></summary>
                <form method="GET" action="{{ url('/my-leads') }}#lead-results" class="lead-filter-form">
                    <input type="hidden" name="view" value="{{ $activeMode }}">
                    <input type="hidden" name="search" value="{{ $searchFilter }}">
                    <div class="flex">
                        <div class="flex-item">
                            <label>Status</label>
                            <select name="status" class="input">
                                <option value="">Use view default</option>
                                @foreach ($statuses as $st)
                                    <option value="{{ $st->key }}" @selected($statusFilter === $st->key)>{{ $st->label }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="flex-item">
                            <label>Sort</label>
                            <select name="sort" class="input">
                                <option value="newest" @selected(($sort ?? 'newest') === 'newest')>Newest</option>
                                <option value="oldest" @selected(($sort ?? '') === 'oldest')>Oldest</option>
                                <option value="updated" @selected(($sort ?? '') === 'updated')>Recently updated</option>
                                <option value="active" @selected(($sort ?? '') === 'active')>Recent activity</option>
                                <option value="visit" @selected(($sort ?? '') === 'visit')>Next visit</option>
                                <option value="booking" @selected(($sort ?? '') === 'booking')>Booking date</option>
                            </select>
                        </div>
                    </div>

                    <div class="lead-date-filter">
                        <div class="lead-date-filter-title">📅 Lead created</div>
                        <div class="lead-date-quick" role="group" aria-label="Quick lead date filters">
                            @foreach (['all' => 'All time', 'today' => 'Today', '3d' => '3 days', '7d' => '7 days', '30d' => '30 days', '1y' => '1 year', 'custom' => 'Custom'] as $key => $label)
                                <button type="button" class="lead-date-chip {{ $dateRange === $key ? 'active' : '' }}" data-date-range="{{ $key }}">{{ $label }}</button>
                            @endforeach
                        </div>
                        <input type="hidden" name="date_range" id="my-leads-date-range" value="{{ $dateRange }}">
                        <div class="lead-date-custom {{ $dateRange === 'custom' ? 'is-open' : '' }}" id="my-leads-date-custom">
                            <div class="flex-item"><label>From</label><input type="date" name="date_from" value="{{ $createdFrom }}" class="input"></div>
                            <div class="flex-item"><label>To</label><input type="date" name="date_to" value="{{ $createdTo }}" class="input"></div>
                        </div>
                    </div>

                    <div class="lead-filter-actions">
                        <button type="submit" class="btn">Apply filters</button>
                        @if ($statusFilter || $searchFilter || $dateRange !== 'all' || request('sort'))
                            <a href="{{ $modeUrls[$activeMode] }}" class="btn btn-ghost">Reset</a>
                        @endif
                    </div>
                </form>
            </details>
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

            {{-- DESKTOP DIRECTORY --}}
            <div class="table-wrap">
                <table>
                    <tr>
                        <th>Name</th>
                        <th>Phone</th>
                        <th>Status</th>
                        <th>Owner / Team</th>
                        <th>Last activity</th>
                        <th>Action</th>
                    </tr>
                    @forelse ($leads as $lead)
                        <x-lead-row :lead="$lead" />
                    @empty
                        <tr><td colspan="6" class="lead-desk-empty">No leads match this view.</td></tr>
                    @endforelse
                </table>
            </div>

            {{-- MOBILE: one decision per card — identify, understand, open. --}}
            <div class="my-leads-mobile-list">
                @forelse ($leads as $lead)
                    @php
                        $next = $lead->pendingFollowup;
                        $isOverdue = $next && $next->scheduled_for && $next->scheduled_for->isPast();
                    @endphp
                    <article class="my-lead-mobile-card">
                        <a href="{{ url('/leads/' . $lead->id) }}" class="my-lead-mobile-main">
                            <div class="my-lead-mobile-top">
                                <strong>{{ $lead->customer_name }}</strong>
                                <x-status-badge :status="$lead->statusKey()" />
                            </div>
                            <div class="my-lead-mobile-project">🏗️ {{ $lead->project?->name ?? 'No project' }}</div>
                            @if ($next)
                                <div class="my-lead-mobile-next {{ $isOverdue ? 'overdue' : '' }}">
                                    {{ $isOverdue ? '🚨' : '⏰' }}
                                    <span>{{ $next->action_label ?? ucfirst(str_replace('_', ' ', $next->action_type ?? 'Follow up')) }}</span>
                                    <time>{{ $next->scheduled_for?->diffForHumans() }}</time>
                                </div>
                            @elseif ($lead->latestActivity)
                                <div class="my-lead-mobile-last">{{ $lead->latestActivity->icon() }} {{ $lead->latestActivity->displayLabel() }} · {{ $lead->latestActivity->logged_at?->diffForHumans() }}</div>
                            @endif
                        </a>
                        <div class="my-lead-mobile-actions">
                            <a href="{{ url('/leads/' . $lead->id) }}" class="lead-mobile-open">Open lead <span>→</span></a>
                            <x-contact-buttons :lead="$lead" size="sm" />
                        </div>
                    </article>
                @empty
                    <div class="my-leads-empty-mobile">
                        <div class="my-leads-empty-icon">🔎</div>
                        <strong>No leads here</strong>
                        <p>Try another view or search for the customer by name, phone or email.</p>
                        <a href="{{ $modeUrls['working'] }}" class="btn-small btn-ghost">Back to Working</a>
                    </div>
                @endforelse
            </div>

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
