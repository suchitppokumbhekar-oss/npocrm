@props(['lead'])

@php
    $visitAt   = $lead->visit_scheduled_at;
    $showVisit = $visitAt && $lead->statusKey() === 'visit_scheduled';
    $bookingAt = $lead->booking_date;
    $createdAt = $lead->created_at;
    $lastAt = $lead->last_activity_at;
    $isAdmin   = (session('user_role') === 'admin');
    $shared    = $lead->activeAgents->filter(fn ($a) => ! $a->pivot->is_primary);
@endphp

<article class="lead-card lead-card-clickable" data-lead-url="{{ url('/leads/' . $lead->id) }}">
    @if (session('user_role') === 'admin' && request('status') === 'lost')
        <label style="display:flex;align-items:center;gap:8px;font-size:12px;margin-bottom:6px;"><input type="checkbox" data-bulk-lead value="{{ $lead->id }}"> Select for bulk edit</label>
    @endif

    <div class="lead-header">
        <a href="{{ url('/leads/' . $lead->id) }}" class="lead-name">
            {{ $lead->customer_name }}
        </a>
        <x-status-badge :status="$lead->statusKey()" />
    </div>

    @if ($lead->tag || $lead->labels->isNotEmpty())
        <div class="lead-card-chips">
            @if ($lead->tag)
                <x-lead-tag-chip :tag="$lead->tag" />
            @endif
            @if ($lead->labels->isNotEmpty())
                <x-lead-labels-row :labels="$lead->labels" :max="4" />
            @endif
        </div>
    @endif

    <div class="lead-meta">
        📱 {{ $lead->phone }}
        @if ($lead->project?->name)
            · 🏗️ {{ $lead->project->name }}
        @endif
        · 👤 {{ $lead->agent?->user?->name ?? '⚠️ Awaiting' }}
    </div>

    <div style="font-size:10px;color:var(--c-muted,#777);margin-top:5px;display:flex;gap:7px;flex-wrap:wrap;">
        @if($createdAt)<span>Created: {{ $createdAt->format('d M Y, h:i A') }}</span>@endif
        @if($lastAt)<span>Last: {{ $lastAt->format('d M Y, h:i A') }}</span>@endif
        @if($bookingAt)<span>Booking: {{ \Carbon\Carbon::parse($bookingAt)->format('d M Y') }}</span>@endif
    </div>

    @if ($lead->latestActivity)
        @php
            $act  = $lead->latestActivity;
            $more = max(0, ($lead->activities_count ?? 1) - 1);
        @endphp
        <div class="last-action-chip"
             title="{{ $act->notes ?? $act->outcome ?? $act->type }}">
            <span class="la-icon">{{ $act->icon() }}</span>
            <span class="la-text">{{ $act->displayLabel() }}</span>
            @if(!empty($act->action_source))<span class="badge" style="font-size:9px;padding:1px 5px;">{{ strtoupper($act->action_source) }}</span>@endif
            <span class="la-sep">·</span>
            <span class="la-actor">{{ $act->agent?->user?->name ?? 'System' }}</span>
            <span class="la-sep">·</span>
            <span class="la-ago">{{ $act->logged_at?->diffForHumans() }}</span>
            @if ($more > 0)
                <span class="la-more">(+{{ $more }})</span>
            @endif
        </div>
    @endif

    @if ($shared->isNotEmpty())
        <div class="shared-agents-row" data-shared-row="{{ $lead->id }}">
            <span class="shared-agents-label" title="Also shared with">👥</span>
            @foreach ($shared as $sa)
                <span class="shared-agent-pill" data-pill-agent="{{ $sa->id }}">
                    {{ $sa->user?->name ?? 'Agent' }}
                    @if ($isAdmin)
                        <button type="button"
                                class="shared-agent-remove"
                                data-unshare-agent
                                data-lead="{{ $lead->id }}"
                                data-agent="{{ $sa->id }}"
                                title="Remove {{ $sa->user?->name }} from this lead">×</button>
                    @endif
                </span>
            @endforeach
        </div>
    @endif

    @if ($showVisit)
        <div class="lead-visit-line">
            🏠 Visit: <strong>{{ $visitAt->format('d M, h:i A') }}</strong>
            @if ($visitAt->isToday())
                <span class="badge orange" style="font-size:10px;padding:1px 6px;">TODAY</span>
            @elseif ($visitAt->isPast())
                <span class="badge red" style="font-size:10px;padding:1px 6px;">PAST</span>
            @endif
        </div>
    @endif

    <x-lead-work-actions :lead="$lead" />

</article>
