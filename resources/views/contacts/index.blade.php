@extends('layouts.app')

@section('title', 'Contacts — NPO CRM')

@section('content')

<div class="contacts-page">

<a href="{{ url('/') }}" class="back-link">← Back to dashboard</a>

<div class="card contacts-head-card">
    <div class="section-head">
        <div>
            <h2 style="margin:0;">📇 Contacts</h2>
            <p class="muted" style="margin-top:4px;font-size:13px;">
                {{ number_format($contacts->total()) }} contact{{ $contacts->total() === 1 ? '' : 's' }}
            </p>
        </div>

        <div class="contacts-head-actions">
            <a href="{{ url('/contacts/dialer') }}" class="btn-small btn-info">📞 Start Calling</a>

            @if (app(\App\Services\AccessService::class)->canImportContacts())
                <a href="{{ url('/contacts/import') }}" class="btn-small contacts-secondary-btn">
                    📥 Import CSV
                </a>
            @endif
        </div>
    </div>
</div>

{{-- FILTERS --}}
<div class="contacts-mobile-filter-toggle">
    <button type="button" onclick="document.querySelector('.contacts-filters').classList.toggle('contacts-filters-open'); this.classList.toggle('is-open');">
        <span>🔍 Search & Filters</span>
        <span class="contacts-filter-chevron">⌄</span>
    </button>
</div>

<div class="card contacts-filters">
    <form method="GET" action="{{ url('/contacts') }}">
        <div class="flex contacts-filter-grid">
            <div class="flex-item contacts-search-field">
                <label>🔍 Search</label>
                <input type="text"
                       name="q"
                       class="input"
                       value="{{ $q }}"
                       placeholder="Name, phone, email">
            </div>

            <div class="flex-item">
                <label>Status</label>
                <select name="status" class="input">
                    <option value="">All</option>
                    @foreach (['new','called','interested','not_interested','converted','dnc','invalid'] as $st)
                        <option value="{{ $st }}" @selected($status === $st)>
                            {{ ucfirst(str_replace('_',' ',$st)) }}
                        </option>
                    @endforeach
                </select>
            </div>

            <div class="flex-item">
                <label>Project</label>
                <select name="project_id" class="input">
                    <option value="">All projects</option>
                    @foreach ($projects as $p)
                        <option value="{{ $p->id }}"
                                @selected((string)$projectId === (string)$p->id)>
                            {{ $p->name }}
                        </option>
                    @endforeach
                </select>
            </div>

            <div class="flex-item">
                <label>Agent</label>
                <select name="agent_id" class="input">
                    <option value="">All agents</option>
                    @foreach ($agents as $a)
                        <option value="{{ $a->id }}"
                                @selected((string)$agentId === (string)$a->id)>
                            {{ $a->user?->name ?? 'Agent #'.$a->id }}
                        </option>
                    @endforeach
                </select>
            </div>
        </div>

        <div class="contacts-filter-actions">
            <button type="submit" class="btn-small btn-info">🔍 Apply</button>
            <a href="{{ url('/contacts') }}" class="btn-small contacts-secondary-btn">Reset</a>
        </div>
    </form>
</div>

@if ($contacts->isEmpty())

    <div class="card">
        <p class="muted">No contacts match your filters.</p>
    </div>

@else

    {{-- =========================================================
         MOBILE CALLING WORK QUEUE
         ========================================================= --}}
    <div class="contacts-mobile-list">

        <div class="contacts-mobile-intro">
            <strong>Calling work</strong>
            <span>Tap Work Call to handle a contact without leaving the CRM workflow.</span>
        </div>

        @foreach ($contacts as $c)
            @php
                $badge = [
                    'new' => 'blue',
                    'called' => 'orange',
                    'interested' => 'green',
                    'not_interested' => 'red',
                    'converted' => 'green',
                    'dnc' => 'red',
                    'invalid' => 'red',
                ][$c->status] ?? 'blue';

                $canWorkCall = ! in_array(
                    $c->status,
                    ['converted', 'dnc', 'invalid'],
                    true
                );
            @endphp

            <article class="contact-work-card">

                <div class="contact-work-top">
                    <div class="contact-work-identity">
                        <a href="{{ url('/contacts/'.$c->id) }}"
                           class="contact-work-name">
                            {{ $c->name }}
                        </a>

                        <a href="{{ url('/contacts/'.$c->id) }}"
                           class="contact-work-phone">
                            {{ $c->phone }}
                        </a>
                    </div>

                    <span class="badge {{ $badge }}">
                        {{ ucfirst(str_replace('_',' ',$c->status)) }}
                    </span>
                </div>

                <div class="contact-work-project">
                    <span class="contact-work-label">PROJECT</span>
                    <strong>{{ $c->project?->name ?? 'No project assigned' }}</strong>
                </div>

                <div class="contact-work-stats">
                    <div>
                        <span>Attempts</span>
                        <strong>{{ $c->attempts }}</strong>
                    </div>

                    <div>
                        <span>Connected</span>
                        <strong>{{ $c->connected_count }}</strong>
                    </div>

                    <div class="contact-work-stat-wide">
                        <span>Last outcome</span>
                        <strong>
                            {{ $c->last_outcome_key
                                ? ucfirst(str_replace('_',' ', $c->last_outcome_key))
                                : 'Not called yet' }}
                        </strong>
                    </div>
                </div>

                <div class="contact-work-assigned">
                    <span class="contact-work-label">ASSIGNED TO</span>

                    @if ($c->agent?->user?->name)
                        <strong>{{ $c->agent->user->name }}</strong>
                    @else
                        <span class="muted">Unassigned</span>
                    @endif
                </div>

                <div class="contact-work-actions">

                    @if ($canWorkCall)
                        <a href="{{ route('contacts.dialer', ['contact_id' => $c->id]) }}"
                           class="contact-work-call">
                            📞 Work Call
                        </a>
                    @else
                        <span class="contact-work-disabled">
                            Calling unavailable
                        </span>
                    @endif

                    <a href="{{ url('/contacts/'.$c->id) }}"
                       class="contact-work-open">
                        Open Contact →
                    </a>

                </div>

            </article>
        @endforeach

    </div>


    {{-- =========================================================
         DESKTOP TABLE
         ========================================================= --}}
    <div class="card contacts-desktop-list">
        <div class="table-wrap">
            <table>
                <tr>
                    <th>Name</th>
                    <th>Phone</th>
                    <th>Project</th>
                    <th>Assigned</th>
                    <th>Attempts</th>
                    <th>Connected</th>
                    <th>Last Outcome</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>

                @foreach ($contacts as $c)
                    @php
                        $canWorkCall = ! in_array($c->status, ["converted", "dnc", "invalid"], true);
                    @endphp
                    <tr>
                        <td>
                            <a href="{{ url('/contacts/'.$c->id) }}"
                               style="font-weight:600;color:inherit;">
                                {{ $c->name }}
                            </a>
                        </td>

                        <td>{{ $c->phone }}</td>

                        <td>
                            <span class="muted" style="font-size:12px;">
                                {{ $c->project?->name ?? '—' }}
                            </span>
                        </td>

                        <td>
                            @if ($c->agent?->user?->name)
                                <span class="badge purple" style="font-size:11px;">
                                    {{ $c->agent->user->name }}
                                </span>
                            @else
                                <span class="muted">—</span>
                            @endif
                        </td>

                        <td>{{ $c->attempts }}</td>

                        <td>
                            @if ($c->connected_count > 0)
                                <span class="badge green">{{ $c->connected_count }}</span>
                            @else
                                <span class="muted">0</span>
                            @endif
                        </td>

                        <td>
                            @if ($c->last_outcome_key)
                                <span class="muted" style="font-size:12px;">
                                    {{ ucfirst(str_replace('_',' ', $c->last_outcome_key)) }}
                                </span>
                            @else
                                <span class="muted">—</span>
                            @endif
                        </td>

                        <td>
                            @php
                                $desktopBadge = [
                                    'new' => 'blue',
                                    'called' => 'orange',
                                    'interested' => 'green',
                                    'not_interested' => 'red',
                                    'converted' => 'green',
                                    'dnc' => 'red',
                                    'invalid' => 'red',
                                ][$c->status] ?? 'blue';
                            @endphp

                            <span class="badge {{ $desktopBadge }}">
                                {{ ucfirst(str_replace('_',' ',$c->status)) }}
                            </span>
                        </td>

                        <td>
                            <div class="contacts-desktop-actions">
                                @if ($canWorkCall)
                                    <a href="{{ route('contacts.dialer', ['contact_id' => $c->id]) }}" class="btn-small btn-info">
                                        📞 Work Call
                                    </a>
                                @endif
                                <a href="{{ url('/contacts/'.$c->id) }}" class="btn-small contacts-secondary-btn">
                                    View
                                </a>
                            </div>
                        </td>
                    </tr>
                @endforeach

            </table>
        </div>
    </div>

    <div class="contacts-pagination">
        @include('partials.pagination', ['paginator' => $contacts])
    </div>

@endif

</div>

<style>
.contacts-mobile-filter-toggle {
    display: none;
}

.contacts-mobile-list {
    display: none;
}

.contacts-head-actions,
.contacts-filter-actions {
    display: flex;
    gap: var(--s-2);
    flex-wrap: wrap;
}

.contacts-filter-actions {
    margin-top: var(--s-2);
}

.contacts-filter-grid {
    flex-wrap: wrap;
    gap: var(--s-2);
}

.contacts-filter-grid .flex-item {
    min-width: 160px;
}

.contacts-search-field {
    min-width: 220px !important;
}

.contacts-secondary-btn {
    background: transparent;
    color: var(--c-text);
    border: 1.5px solid var(--c-border);
}

@media (max-width: 767px) {

    .contacts-mobile-filter-toggle {
        display: block;
        margin-bottom: 10px;
    }

    .contacts-mobile-filter-toggle button {
        width: 100%;
        min-height: 44px;
        padding: 10px 14px;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 10px;
        background: var(--c-surface);
        color: var(--c-text);
        border: 1px solid var(--c-border);
        border-radius: 10px;
        font: inherit;
        font-size: 13px;
        font-weight: 800;
        cursor: pointer;
    }

    .contacts-filter-chevron {
        font-size: 18px;
        line-height: 1;
        transition: transform .15s ease;
    }

    .contacts-mobile-filter-toggle button.is-open .contacts-filter-chevron {
        transform: rotate(180deg);
    }

    .contacts-page .contacts-filters {
        display: none;
    }

    .contacts-page .contacts-filters.contacts-filters-open {
        display: block;
    }

    /*
     * The global stylesheet hides/shows table-wrap for other CRM screens.
     * Contacts intentionally uses its own mobile work cards instead.
     */
    .contacts-page .contacts-desktop-list {
        display: none !important;
    }

    .contacts-page .contacts-mobile-list {
        display: block;
    }

    .contacts-head-card {
        padding: 14px;
    }

    .contacts-head-card .section-head {
        align-items: flex-start;
    }

    .contacts-head-actions {
        justify-content: flex-end;
    }

    .contacts-head-actions .btn-small {
        text-align: center;
    }

    .contacts-filters {
        padding: 12px;
    }

    .contacts-filter-grid {
        display: grid !important;
        grid-template-columns: 1fr 1fr;
        gap: 10px;
    }

    .contacts-filter-grid .flex-item,
    .contacts-search-field {
        min-width: 0 !important;
        width: 100%;
    }

    .contacts-search-field {
        grid-column: 1 / -1;
    }

    .contacts-filter-actions {
        display: grid;
        grid-template-columns: 1fr 1fr;
    }

    .contacts-filter-actions .btn-small {
        width: 100%;
        text-align: center;
    }

    .contacts-mobile-intro {
        margin: 4px 2px 10px;
        display: flex;
        flex-direction: column;
        gap: 2px;
    }

    .contacts-mobile-intro strong {
        font-size: 15px;
    }

    .contacts-mobile-intro span {
        color: var(--c-muted);
        font-size: 12px;
        line-height: 1.4;
    }

    .contact-work-card {
        background: var(--c-surface);
        border: 1px solid var(--c-border);
        border-radius: 14px;
        box-shadow: var(--shadow-sm);
        padding: 14px;
        margin-bottom: 10px;
    }

    .contact-work-top {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        gap: 10px;
    }

    .contact-work-identity {
        min-width: 0;
        display: flex;
        flex-direction: column;
        gap: 4px;
    }

    .contact-work-name {
        color: var(--c-text);
        text-decoration: none;
        font-size: 17px;
        line-height: 1.2;
        font-weight: 800;
    }

    .contact-work-phone {
        color: var(--c-primary);
        text-decoration: none;
        font-size: 15px;
        font-weight: 700;
    }

    .contact-work-project,
    .contact-work-assigned {
        margin-top: 12px;
        padding-top: 10px;
        border-top: 1px solid var(--c-border-2);
        display: flex;
        flex-direction: column;
        gap: 3px;
    }

    .contact-work-label {
        color: var(--c-muted);
        font-size: 9px;
        font-weight: 800;
        letter-spacing: .08em;
    }

    .contact-work-project strong,
    .contact-work-assigned strong {
        font-size: 13px;
    }

    .contact-work-stats {
        margin-top: 10px;
        display: grid;
        grid-template-columns: 1fr 1fr 2fr;
        border: 1px solid var(--c-border-2);
        border-radius: 10px;
        overflow: hidden;
    }

    .contact-work-stats > div {
        padding: 9px 8px;
        border-right: 1px solid var(--c-border-2);
        min-width: 0;
    }

    .contact-work-stats > div:last-child {
        border-right: 0;
    }

    .contact-work-stats span,
    .contact-work-stats strong {
        display: block;
    }

    .contact-work-stats span {
        color: var(--c-muted);
        font-size: 9px;
        margin-bottom: 3px;
    }

    .contact-work-stats strong {
        font-size: 12px;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .contact-work-actions {
        margin-top: 13px;
        display: grid;
        grid-template-columns: 1.35fr 1fr;
        gap: 8px;
    }

    .contact-work-call,
    .contact-work-open,
    .contact-work-disabled {
        min-height: 44px;
        border-radius: 9px;
        display: flex;
        justify-content: center;
        align-items: center;
        padding: 9px 10px;
        text-align: center;
        text-decoration: none;
        font-size: 13px;
        font-weight: 800;
    }

    .contact-work-call {
        background: var(--c-primary);
        color: #fff;
    }

    .contact-work-open {
        color: var(--c-text);
        background: var(--c-surface-2);
        border: 1px solid var(--c-border);
    }

    .contact-work-disabled {
        color: var(--c-muted);
        background: var(--c-surface-2);
        border: 1px solid var(--c-border);
        font-size: 11px;
    }

    .contacts-pagination {
        margin-top: 10px;
    }
}

@media (max-width: 390px) {
    .contact-work-stats {
        grid-template-columns: 1fr 1fr;
    }

    .contact-work-stat-wide {
        grid-column: 1 / -1;
        border-top: 1px solid var(--c-border-2);
    }

    .contact-work-actions {
        grid-template-columns: 1fr;
    }
}

</style>

@endsection
