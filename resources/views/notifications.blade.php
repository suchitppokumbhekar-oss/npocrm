@extends('layouts.app')

@section('title', 'Notifications — NPO CRM')

@section('content')

    <a href="{{ url('/') }}" class="back-link">← Back to dashboard</a>

    <div class="card">
        <div class="section-head">
            <h2 style="margin:0;">🔔 Notifications</h2>
            <button type="button" class="btn-small btn-info" id="page-mark-all">
                Mark all read
            </button>
        </div>

        @if ($items->isEmpty())
            <p class="muted" style="padding:24px 0;text-align:center;">
                No notifications yet. You'll see them here when leads are assigned or tasks become overdue.
            </p>
        @else
            <div class="notif-page-list">
                @foreach ($items as $n)
                    <div class="notif-item {{ $n->read_at ? '' : 'unread' }}"
                         data-notif-id="{{ $n->id }}"
                         data-action-url="{{ $n->action_url }}"
                         data-lead-id="{{ app(\App\Services\NotificationService::class)->leadIdForNotification($n) ?? '' }}"
                         role="button" tabindex="0">
                        <div class="notif-icon">{{ $n->icon ?? '🔔' }}</div>
                        <div class="notif-body">
                            <div class="notif-title">{{ $n->title }}</div>
                            @if ($n->body)
                                <div class="notif-sub">{{ $n->body }}</div>
                            @endif
                            <div class="notif-time">
                                {{ $n->created_at?->format('d M Y, H:i') }}
                                · {{ $n->created_at?->diffForHumans() }}
                            </div>
                        </div>
                        <button type="button" class="notif-delete"
                                data-delete-id="{{ $n->id }}" aria-label="Delete">✕</button>
                    </div>
                @endforeach
            </div>

            <div class="npo-notifications-pagination">{{ $items->links() }}</div>
        @endif
    </div>


<style>
.npo-notifications-pagination{margin-top:var(--s-3);padding-top:12px;border-top:1px solid var(--c-border-2);overflow-x:auto;-webkit-overflow-scrolling:touch}
.npo-notifications-pagination nav{display:flex!important;justify-content:center!important;align-items:center!important;width:100%!important;margin:0!important}
.npo-notifications-pagination nav>div{display:flex!important;align-items:center!important;justify-content:center!important;gap:6px!important;flex-wrap:nowrap!important;width:100%!important;margin:0!important}
.npo-notifications-pagination nav>div>div{display:flex!important;align-items:center!important;gap:6px!important;flex-wrap:nowrap!important}
.npo-notifications-pagination a,
.npo-notifications-pagination span[aria-current="page"],
.npo-notifications-pagination span[aria-disabled="true"]{display:inline-flex!important;align-items:center!important;justify-content:center!important;width:34px!important;height:34px!important;min-width:34px!important;padding:0!important;margin:0!important;border:1px solid var(--c-border)!important;border-radius:7px!important;background:var(--c-surface)!important;color:var(--c-text-2)!important;font-size:13px!important;font-weight:600!important;line-height:1!important;text-decoration:none!important;box-sizing:border-box!important}
.npo-notifications-pagination a:hover{background:var(--c-surface-2)!important;color:var(--c-primary)!important;border-color:var(--c-primary)!important}
.npo-notifications-pagination span[aria-current="page"]{background:var(--c-primary)!important;color:#fff!important;border-color:var(--c-primary)!important}
.npo-notifications-pagination span[aria-disabled="true"]{opacity:.45!important;cursor:default!important}
.npo-notifications-pagination svg{width:16px!important;height:16px!important;display:block!important}
.npo-notifications-pagination p{margin:0!important}
@media(max-width:767px){
  .npo-notifications-pagination{justify-content:flex-start!important;padding-bottom:3px}
  .npo-notifications-pagination nav{justify-content:flex-start!important;width:max-content!important;min-width:100%!important}
  .npo-notifications-pagination nav>div{justify-content:flex-start!important;width:max-content!important;min-width:100%!important}
  .npo-notifications-pagination a,
  .npo-notifications-pagination span[aria-current="page"],
  .npo-notifications-pagination span[aria-disabled="true"]{width:32px!important;height:32px!important;min-width:32px!important}
}
</style>

@endsection