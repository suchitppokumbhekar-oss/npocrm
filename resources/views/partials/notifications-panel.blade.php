@if ($items->isEmpty())
    <div class="notif-empty">
        <div class="notif-empty-icon">✓</div>
        <strong>You're all caught up</strong>
        <span>No unread notifications.</span>
    </div>
@else
    @foreach ($items as $n)
        <div class="notif-item notif-{{ \App\Services\NotificationService::priorityForType($n->type) }} {{ $n->read_at ? '' : 'unread' }}"
             data-notif-id="{{ $n->id }}" data-action-url="{{ $n->action_url }}" data-lead-id="{{ app(\App\Services\NotificationService::class)->leadIdForNotification($n) ?? '' }}" role="button" tabindex="0">
            <div class="notif-icon">{{ $n->icon ?? '🔔' }}</div>
            <div class="notif-body">
                <div class="notif-title-row">
                    <div class="notif-title">{{ $n->title }}</div>
                    @if (\App\Services\NotificationService::priorityForType($n->type) === 'urgent')
                        <span class="notif-priority">ACTION</span>
                    @endif
                </div>
                @if ($n->body)<div class="notif-sub">{{ $n->body }}</div>@endif
                <div class="notif-time">{{ $n->created_at?->diffForHumans() }}</div>
            </div>
            <button type="button" class="notif-delete" data-delete-id="{{ $n->id }}" aria-label="Delete">✕</button>
        </div>
    @endforeach
@endif

<div class="notif-push-setup" data-push-setup>
    <div class="notif-push-copy">
        <strong>📲 Work alerts</strong>
        <span data-push-status>Checking this device…</span>
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
        <button type="button" class="btn-small btn-info" data-enable-push hidden>Enable alerts</button>
        <button type="button" class="btn-small btn-info" data-disable-push hidden>Disable alerts</button>
        <button type="button" class="btn-small btn-info" data-test-push hidden>Send test alert</button>
    </div>
</div>
