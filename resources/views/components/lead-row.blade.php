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

<tr class="lead-row-clickable" data-lead-url="{{ url('/leads/' . $lead->id) }}">
    @if (session('user_role') === 'admin' && request('status') === 'lost')
        <td style="width:42px;text-align:center;"><input type="checkbox" data-bulk-lead value="{{ $lead->id }}" aria-label="Select {{ $lead->customer_name }}"></td>
    @endif

    <td class="lead-name-cell">
        <div class="lead-name-row">
            <a href="{{ url('/leads/' . $lead->id) }}" class="lead-name-link">
                {{ $lead->customer_name }}
            </a>
            @if ($lead->tag)
                <x-lead-tag-chip :tag="$lead->tag" />
            @endif
        </div>

        @if ($lead->project?->name)
            <div class="lead-project-inline">
                🏗️ {{ $lead->project->name }}
            </div>
        @endif

        @if ($lead->labels->isNotEmpty())
            <x-lead-labels-row :labels="$lead->labels" :max="3" />
        @endif

        <div class="lead-date-line" style="font-size:10px;color:var(--c-muted,#777);margin-top:4px;display:flex;gap:7px;flex-wrap:wrap;">
            @if($createdAt) <span>Created: {{ $createdAt->format('d M Y, h:i A') }}</span> @endif
            @if($lastAt) <span>Last: {{ $lastAt->format('d M Y, h:i A') }}</span> @endif
            @if($bookingAt) <span>Booking: {{ \Carbon\Carbon::parse($bookingAt)->format('d M Y') }}</span> @endif
        </div>

        @if ($showVisit)
            <div class="lead-visit-line">
                🏠 {{ $visitAt->format('d M, h:i A') }}
                @if ($visitAt->isToday())
                    <span class="badge orange" style="font-size:10px;padding:1px 6px;">TODAY</span>
                @elseif ($visitAt->isPast())
                    <span class="badge red" style="font-size:10px;padding:1px 6px;">PAST</span>
                @endif
            </div>
        @endif
    </td>

    <td class="lead-phone-cell">{{ phone_display($lead->phone) }}</td>

    <td><x-status-badge :status="$lead->statusKey()" /></td>

    <td class="lead-agent-cell">
        @if ($lead->agent?->user?->name)
            <span class="agent-tag">{{ $lead->agent->user->name }}</span>
        @else
            <span class="agent-tag" style="background:#e74c3c;" title="Add routing in Settings → Project Routing">
                ⚠️ Awaiting
            </span>
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
    </td>

    <td class="lead-row-actions">
        <x-lead-work-actions :lead="$lead" />
    </td>
</tr>