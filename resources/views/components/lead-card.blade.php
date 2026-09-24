@props(['lead'])

@php
    $visitAt   = $lead->visit_scheduled_at;
    $showVisit = $visitAt && $lead->statusKey() === 'visit_scheduled';
    $createdAt = $lead->created_at;
    $lastAt    = $lead->last_activity_at;

    $latestActivity = $lead->latestActivity;

    $activityLabel = $latestActivity
        ? $latestActivity->displayLabel()
        : null;

    $activityAgo = $latestActivity?->logged_at
        ? $latestActivity->logged_at->diffForHumans()
        : null;

    $isClosed = $lead->isLost() || $lead->isWon();
@endphp

<article class="lead-card lead-card-clickable lead-glimpse-card"
         data-lead-url="{{ url('/leads/' . $lead->id) }}">

    @if (session('user_role') === 'admin' && request('status') === 'lost')
        <label class="lead-glimpse-select">
            <input type="checkbox" data-bulk-lead value="{{ $lead->id }}">
            Select
        </label>
    @endif

    {{-- ROW 1: identity + status --}}
    <div class="lead-glimpse-head">

        <div class="lead-glimpse-identity">
            <a href="{{ url('/leads/' . $lead->id) }}"
               class="lead-glimpse-name">
                {{ $lead->customer_name }}
            </a>

            @if ($lead->project?->name)
                <div class="lead-glimpse-project">
                    🏗️ {{ $lead->project->name }}
                </div>
            @endif
        </div>

        <x-status-badge :status="$lead->statusKey()" />

    </div>


    {{-- ROW 2: immediately useful contact/ownership information --}}
    <div class="lead-glimpse-meta">

        <span class="lead-glimpse-phone">
            📱 {{ phone_display($lead->phone) }}
        </span>

        <span class="lead-glimpse-agent">
            👤 {{ $lead->agent?->user?->name ?? 'Awaiting assignment' }}
        </span>

    </div>


    {{-- TAGS / LABELS: compact, only when present --}}
    @if ($lead->tag || $lead->labels->isNotEmpty())
        <div class="lead-glimpse-chips">

            @if ($lead->tag)
                <x-lead-tag-chip :tag="$lead->tag" />
            @endif

            @if ($lead->labels->isNotEmpty())
                <x-lead-labels-row :labels="$lead->labels" :max="2" />
            @endif

        </div>
    @endif


    {{-- IMPORTANT UPCOMING WORK --}}
    @if ($showVisit)

        <div class="lead-glimpse-next {{ $visitAt->isPast() ? 'is-overdue' : '' }}">

            <div class="lead-glimpse-next-copy">
                <span class="lead-glimpse-kicker">
                    {{ $visitAt->isPast() ? 'OVERDUE' : 'NEXT' }}
                </span>

                <strong>🏠 Site Visit</strong>
            </div>

            <time datetime="{{ $visitAt->toIso8601String() }}">
                @if ($visitAt->isToday())
                    Today · {{ $visitAt->format('h:i A') }}
                @elseif ($visitAt->isTomorrow())
                    Tomorrow · {{ $visitAt->format('h:i A') }}
                @else
                    {{ $visitAt->format('d M · h:i A') }}
                @endif
            </time>

        </div>

    @elseif ($latestActivity)

        <div class="lead-glimpse-last">

            <span class="lead-glimpse-kicker">LAST</span>

            <span class="lead-glimpse-last-main">
                <strong>
                    {{ $latestActivity->icon() }}
                    {{ $activityLabel }}
                </strong>

                @if ($activityAgo)
                    <span>· {{ $activityAgo }}</span>
                @endif
            </span>

        </div>

    @elseif ($createdAt)

        <div class="lead-glimpse-last">
            <span class="lead-glimpse-kicker">NEW LEAD</span>

            <span class="lead-glimpse-last-main">
                <strong>Created {{ $createdAt->diffForHumans() }}</strong>
            </span>
        </div>

    @endif


    {{-- When a visit is the next important item, still retain last activity context --}}
    @if ($showVisit && $latestActivity)

        <div class="lead-glimpse-history">
            Last:
            <strong>{{ $activityLabel }}</strong>

            @if ($activityAgo)
                · {{ $activityAgo }}
            @endif
        </div>

    @endif


    {{-- ACTION: preserve canonical CRM work workflow --}}
    <div class="lead-glimpse-actions">

        <a href="{{ url('/leads/' . $lead->id) }}#pending-tasks"
           class="lead-glimpse-work">

            <span>{{ $isClosed ? '👁' : '▶' }}</span>

            <strong>
                {{ $isClosed ? 'Open Lead' : 'Work Lead' }}
            </strong>

        </a>

        <a href="{{ url('/leads/' . $lead->id) }}"
           class="lead-glimpse-open"
           aria-label="Open {{ $lead->customer_name }}">
            Details →
        </a>

    </div>

</article>

<style>
@media (max-width: 1023px) {

    /*
     * Lead Directory owns its mobile presentation.
     * Never expose the desktop table on mobile even if another
     * page has overridden the global .table-wrap behaviour.
     */
    body .leads-table {
        display: none !important;
    }

    body .leads-table,
    body .leads-table * {
        max-width: 100%;
    }

    .lead-glimpse-card {
        padding: 11px 12px !important;
        margin-bottom: 8px !important;
        border: 1px solid var(--c-border) !important;
        border-radius: 11px !important;
        background: var(--c-surface) !important;
    }

    .lead-glimpse-head {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 8px;
    }

    .lead-glimpse-identity {
        min-width: 0;
        flex: 1;
    }

    .lead-glimpse-name {
        display: block;
        color: var(--c-text);
        font-size: 15px;
        font-weight: 850;
        line-height: 1.2;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .lead-glimpse-project {
        margin-top: 3px;
        color: var(--c-muted);
        font-size: 11px;
        font-weight: 650;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .lead-glimpse-head .badge {
        flex: 0 0 auto;
        max-width: 44%;
        overflow: hidden;
        text-overflow: ellipsis;
        font-size: 10px;
        padding: 2px 7px;
    }

    .lead-glimpse-meta {
        display: flex;
        align-items: center;
        gap: 6px 12px;
        flex-wrap: wrap;
        margin-top: 8px;
        color: var(--c-text-2);
        font-size: 11px;
    }

    .lead-glimpse-phone {
        color: var(--c-text);
        font-weight: 750;
    }

    .lead-glimpse-agent {
        color: var(--c-muted);
    }

    .lead-glimpse-chips {
        display: flex;
        align-items: center;
        gap: 4px;
        flex-wrap: wrap;
        margin-top: 7px;
        max-height: 26px;
        overflow: hidden;
    }

    .lead-glimpse-chips .badge,
    .lead-glimpse-chips [class*="tag-"] {
        font-size: 9px !important;
    }

    .lead-glimpse-next {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 10px;
        margin-top: 9px;
        padding: 7px 8px;
        border-radius: 8px;
        background: var(--c-surface-2);
        border-left: 3px solid var(--c-primary);
    }

    .lead-glimpse-next.is-overdue {
        border-left-color: var(--c-danger);
        background: #fff6f5;
    }

    .lead-glimpse-next-copy {
        min-width: 0;
        display: flex;
        flex-direction: column;
        gap: 1px;
    }

    .lead-glimpse-kicker {
        color: var(--c-muted);
        font-size: 8px;
        font-weight: 900;
        letter-spacing: .08em;
    }

    .lead-glimpse-next.is-overdue .lead-glimpse-kicker {
        color: var(--c-danger);
    }

    .lead-glimpse-next-copy strong {
        font-size: 11px;
    }

    .lead-glimpse-next time {
        flex: 0 0 auto;
        color: var(--c-text-2);
        font-size: 10px;
        font-weight: 750;
        white-space: nowrap;
    }

    .lead-glimpse-last {
        display: flex;
        align-items: center;
        gap: 7px;
        min-width: 0;
        margin-top: 8px;
        padding: 6px 8px;
        border-radius: 8px;
        background: var(--c-surface-2);
    }

    .lead-glimpse-last-main {
        min-width: 0;
        flex: 1;
        color: var(--c-muted);
        font-size: 10px;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .lead-glimpse-last-main strong {
        color: var(--c-text-2);
    }

    .lead-glimpse-history {
        margin-top: 5px;
        color: var(--c-muted);
        font-size: 9px;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .lead-glimpse-history strong {
        color: var(--c-text-2);
    }

    .lead-glimpse-actions {
        display: grid;
        grid-template-columns: 1fr auto;
        gap: 6px;
        margin-top: 9px;
        padding-top: 8px;
        border-top: 1px solid var(--c-border-2);
    }

    .lead-glimpse-work,
    .lead-glimpse-open {
        min-height: 38px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 5px;
        border-radius: 8px;
        padding: 7px 11px;
        text-decoration: none;
        font-size: 11px;
    }

    .lead-glimpse-work {
        background: var(--c-primary);
        color: #fff;
        font-weight: 850;
    }

    .lead-glimpse-work:hover {
        color: #fff;
        text-decoration: none;
    }

    .lead-glimpse-open {
        min-width: 82px;
        background: var(--c-surface-2);
        border: 1px solid var(--c-border);
        color: var(--c-text);
        font-weight: 750;
    }

    .lead-glimpse-open:hover {
        color: var(--c-text);
        text-decoration: none;
    }

    .lead-glimpse-select {
        display: flex;
        align-items: center;
        gap: 6px;
        margin-bottom: 7px;
        color: var(--c-muted);
        font-size: 10px;
    }
}

@media (min-width: 1024px) {

    /*
     * lead-card is the mobile representation.
     * Desktop continues to use the existing leads table.
     */
    .lead-glimpse-card {
        display: none !important;
    }
}
</style>
