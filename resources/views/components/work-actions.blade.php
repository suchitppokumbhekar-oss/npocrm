@props(['lead', 'followup' => null, 'context' => 'lead', 'showHistory' => true, 'showDispose' => false, 'compact' => true, 'returnTo' => null])

@php
    $work = app(\App\Services\WorkActionService::class);
    $state = $work->state($followup);
    $role = session('user_role');
    $allowDispose = $showDispose && $followup && $state['is_future'] && in_array($role, ['admin','team_manager','agent'], true);
    $actionReturnTo = (string) ($returnTo ?: request()->getRequestUri());
    $historyUrl = url('/leads/' . $lead->id . '?' . http_build_query([
        'focus_history' => 1,
        'focus_followup_id' => $followup?->id,
        'return_to' => request()->getRequestUri(),
    ]) . '#activity-history');
@endphp

<div class="work-actions-unified {{ $compact ? 'work-actions-compact' : '' }}" data-work-actions data-lead-id="{{ $lead->id }}" @if($followup) data-followup-id="{{ $followup->id }}" @endif>
    @if ($lead->isLost() || $lead->isWon())
        <a href="{{ url('/leads/' . $lead->id) }}" class="btn-small btn-view">👁 View</a>
    @elseif ($followup && $state['is_future'])
        <div class="work-action-gate" data-work-gate data-work-due="{{ $followup->scheduled_for->toIso8601String() }}">
            <div class="work-action-lock">
                <strong>⏳ Actions unlock at <x-relative-date :date="$followup->scheduled_for" /></strong>
                <span data-work-countdown>Calculating…</span>
            </div>
            <button type="button" class="btn-small btn-ghost" data-work-start-early onclick="(function(btn){var gate=btn.closest('[data-work-gate]');if(!gate)return;var live=gate.querySelector('[data-work-live-actions]');if(!live)return;live.hidden=false;live.style.pointerEvents='';live.style.opacity='';live.querySelectorAll('[data-modal]').forEach(function(b){b.dataset.allowEarly='1';});var lock=gate.querySelector('.work-action-lock');if(lock)lock.remove();btn.remove();})(this)">Start Early</button>
            <div class="work-actions-live" data-work-live-actions hidden>
                <x-contact-buttons :lead="$lead" size="sm" />
                <button type="button" class="btn-small tc-share-btn" data-modal="task-share-lead" data-lead-id="{{ $lead->id }}" data-followup-id="{{ $followup->id }}" data-return-to="{{ e($actionReturnTo) }}">📤 Share Lead</button>
                <button type="button" class="btn-small btn-log" data-modal="complete-task" data-lead-id="{{ $lead->id }}" data-followup-id="{{ $followup->id }}" data-return-to="{{ e($actionReturnTo) }}">✓ Done — Log Now</button>
                @if($showHistory)<a href="{{ $historyUrl }}" class="btn-small btn-view">📜 History</a>@endif
            </div>
            @if($allowDispose)
                <button type="button" class="btn-small btn-danger" data-modal="dispose-followup" data-lead-id="{{ $lead->id }}" data-followup-id="{{ $followup->id }}">Cancel Future Task</button>
            @endif
        </div>
    @elseif ($followup)
        <x-contact-buttons :lead="$lead" size="sm" />
        <button type="button" class="btn-small tc-share-btn" data-modal="task-share-lead" data-lead-id="{{ $lead->id }}" data-followup-id="{{ $followup->id }}" data-return-to="{{ e($actionReturnTo) }}">📤 Share Lead</button>
        <button type="button" class="btn-small btn-log" data-modal="complete-task" data-lead-id="{{ $lead->id }}" data-followup-id="{{ $followup->id }}" data-return-to="{{ e($actionReturnTo) }}">✓ Done — Log Now</button>
        @if($showHistory)<a href="{{ $historyUrl }}" class="btn-small btn-view">📜 History</a>@endif
        @if($allowDispose)<button type="button" class="btn-small btn-danger" data-modal="dispose-followup" data-lead-id="{{ $lead->id }}" data-followup-id="{{ $followup->id }}">Cancel Future Task</button>@endif
    @else
        <a href="{{ url('/leads/' . $lead->id) }}" class="btn-small btn-view">👁 View Lead</a>
    @endif
</div>
