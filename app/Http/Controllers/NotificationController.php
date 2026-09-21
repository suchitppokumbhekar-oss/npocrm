<?php

namespace App\Http\Controllers;

use App\Models\AppNotification;
use App\Models\PushSubscription;
use App\Services\NotificationService;
use App\Services\WebPushService;
use Illuminate\Http\Request;
use App\Services\AuditLogService;

class NotificationController extends Controller
{
    public function __construct(
        private NotificationService $notifications,
        private WebPushService $push,
        private AuditLogService $audit,
    ) {}

    /**
     * Prevent LiteSpeed / browser / CDN caching of notification responses.
     * Without this, the unread-count endpoint returns a stale value
     * and the badge doesn't clear after marking read.
     */
    private function noCacheHeaders(): array
    {
        return [
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'Pragma'        => 'no-cache',
            'Expires'       => '0',
        ];
    }

    /* ============================================================
       FULL PAGE — /notifications
       ============================================================ */
    public function index()
    {
        if (! session('user_id')) return redirect('/login');

        // Reconcile against live lead/task state before rendering so the
        // historical page stays complete while stale unread work is no longer
        // presented as active attention.
        $this->notifications->reconcileUnread((int) session('user_id'));

        $items = AppNotification::where('user_id', session('user_id'))
            ->orderByDesc('created_at')
            ->paginate(30);

        return view('notifications', compact('items'));
    }

    /* ============================================================
       PANEL — /notifications/panel (bell dropdown)
       ============================================================ */
    public function panel()
    {
        if (! session('user_id')) abort(401);

        $this->notifications->reconcileUnread((int) session('user_id'));

        $items = AppNotification::where('user_id', session('user_id'))
            ->whereNull('read_at')
            ->orderByDesc('created_at')
            ->limit(10)
            ->get();

        $unread = $this->notifications->unreadCount((int) session('user_id'));

        return response()
            ->view('partials.notifications-panel', compact('items', 'unread'))
            ->withHeaders($this->noCacheHeaders());
    }

    /* ============================================================
       UNREAD COUNT — /notifications/unread-count
       ============================================================ */
    public function unreadCount()
    {
        if (! session('user_id')) {
            return response()->json(['count' => 0])
                ->withHeaders($this->noCacheHeaders());
        }

        return response()
            ->json(['count' => $this->notifications->unreadCount(session('user_id'))])
            ->withHeaders($this->noCacheHeaders());
    }

    /* ============================================================
       MARK ONE READ — /notifications/{id}/read
       ============================================================ */
    public function markRead(Request $request, int $id)
    {
        if (! session('user_id')) abort(401);

        $userId = (int) session('user_id');
        $n = AppNotification::where('user_id', $userId)->findOrFail($id);
        $leadId = $this->notifications->leadIdForNotification($n);
        $readAt = now();

        // A notification click means the user has seen the current alert and
        // the older reminder versions for the same lead are no longer useful
        // in the bell queue. Only clear notifications with an id at or before
        // the clicked alert so a genuinely new alert generated afterwards
        // can still come back to the user.
        $clearedIds = [$n->id];
        if ($leadId) {
            $older = AppNotification::where('user_id', $userId)
                ->whereNull('read_at')
                ->where('id', '!=', $n->id)
                ->where(function ($q) use ($n, $leadId) {
                    $q->where(function ($leadQ) use ($leadId) {
                        $leadQ->where('related_type', 'lead')->where('related_id', $leadId);
                    })->orWhere(function ($followQ) use ($leadId) {
                        $followQ->where('related_type', 'followup')
                            ->whereIn('related_id', function ($sub) use ($leadId) {
                                $sub->select('id')->from('followups')->where('lead_id', $leadId);
                            });
                    })->orWhere('action_url', '/leads/' . $leadId);
                })
                ->where('id', '<=', $n->id)
                ->pluck('id')
                ->map(fn ($notificationId) => (int) $notificationId)
                ->all();

            if ($older) {
                $clearedIds = array_values(array_unique(array_merge($clearedIds, $older)));
            }
        }

        AppNotification::where('user_id', $userId)
            ->whereIn('id', $clearedIds)
            ->whereNull('read_at')
            ->update(['read_at' => $readAt]);

        $this->audit->record(
            'notification',
            'Opened notification',
            $request,
            [
                'notification_id' => (int) $n->id,
                'notification_type' => $n->type,
                'notification_title' => $n->title,
                'lead_id' => $leadId,
                'cleared_older_notification_count' => max(0, count($clearedIds) - 1),
                'cleared_notification_ids' => array_slice($clearedIds, 0, 200),
                'source' => $request->input('source', 'bell'),
                'read_at' => $readAt->toIso8601String(),
            ],
            $leadId ? 'lead' : 'notification',
            $leadId ?: $n->id,
            $userId,
            session('user_name'),
            session('user_role')
        );

        return response()
            ->json([
                'success' => true,
                'notification_id' => (int) $n->id,
                'lead_id' => $leadId,
                'cleared_notification_ids' => $clearedIds,
                'read_at' => $readAt->toIso8601String(),
            ])
            ->withHeaders($this->noCacheHeaders());
    }

    /* ============================================================
       MARK ALL READ — /notifications/read-all
       ============================================================ */
    public function markAllRead(Request $request)
    {
        if (! session('user_id')) abort(401);

        $userId = (int) session('user_id');
        $pending = AppNotification::where('user_id', $userId)
            ->whereNull('read_at')
            ->orderBy('id')
            ->get(['id', 'type', 'title', 'related_type', 'related_id', 'action_url']);

        $readAt = now();
        $count = $this->notifications->markAllRead($userId, $readAt);

        $leadIds = [];
        foreach ($pending as $notification) {
            $leadId = $this->notifications->leadIdForNotification($notification);
            if ($leadId) $leadIds[] = $leadId;
        }
        $leadIds = array_values(array_unique(array_map('intval', $leadIds)));

        if ($count > 0) {
            $this->audit->record(
                'notification',
                'Marked all notifications read',
                $request,
                [
                    'marked_count' => $count,
                    'lead_count' => count($leadIds),
                    'lead_ids' => array_slice($leadIds, 0, 200),
                    'notification_ids' => array_slice($pending->pluck('id')->map(fn ($id) => (int) $id)->all(), 0, 200),
                    'acknowledged_without_opening' => true,
                    'read_at' => $readAt->toIso8601String(),
                ],
                'notifications',
                null,
                $userId,
                session('user_name'),
                session('user_role')
            );
        }

        return response()
            ->json([
                'success' => true,
                'marked' => $count,
                'unread' => $this->notifications->unreadCount($userId),
                'read_at' => $readAt->toIso8601String(),
            ])
            ->withHeaders($this->noCacheHeaders());
    }

    /* ============================================================
       PWA PUSH — public VAPID key
       ============================================================ */
    public function pushKey()
    {
        if (! session('user_id')) abort(401);

        return response()
            ->json(['publicKey' => $this->push->publicKey()])
            ->withHeaders($this->noCacheHeaders());
    }

    /* ============================================================
       PWA PUSH — status / diagnostics
       ============================================================ */
    public function pushStatus()
    {
        if (! session('user_id')) abort(401);

        return response()->json([
            'server' => $this->push->statusForUser((int) session('user_id')),
            'worker' => $this->push->heartbeat(),
        ])->withHeaders($this->noCacheHeaders());
    }

    /* ============================================================
       PWA PUSH — send a real end-to-end test to this user's devices
       ============================================================ */
    public function pushTest()
    {
        if (! session('user_id')) abort(401);

        $userId = (int) session('user_id');
        $notification = $this->notifications->notify($userId, 'system_test', '🔔 NPO CRM alert test', [
            'body' => 'If you can hear/see this while the CRM is closed, mobile alerts are working.',
            'icon' => '🔔',
            'action_url' => '/notifications',
            'related_type' => 'system',
            'related_id' => null,
        ]);

        $subscriptions = PushSubscription::where('user_id', $userId)->get();
        $sent = 0;
        foreach ($subscriptions as $subscription) {
            if ($this->push->sendNotification($subscription, $notification)) {
                $sent++;
            }
        }

        return response()->json([
            'success' => true,
            'sent' => $sent,
            'subscriptions' => $subscriptions->count(),
            'notification_id' => $notification->id,
            'message' => $sent > 0 ? 'Test alert sent.' : 'Test notification created, but no push was delivered. Check alert status.',
        ])->withHeaders($this->noCacheHeaders());
    }

    /* ============================================================
       PWA PUSH — subscribe current device
       ============================================================ */
    public function pushSubscribe(Request $request)
    {
        if (! session('user_id')) abort(401);

        $data = $request->validate([
            'endpoint' => ['required', 'string', 'max:2048'],
            'keys' => ['required', 'array'],
            'keys.p256dh' => ['required', 'string', 'max:512'],
            'keys.auth' => ['required', 'string', 'max:512'],
            'contentEncoding' => ['nullable', 'string', 'max:32'],
        ]);

        $subscription = $this->push->subscribe((int) session('user_id'), $data);

        return response()
            ->json(['success' => true, 'id' => $subscription->id])
            ->withHeaders($this->noCacheHeaders());
    }

    /* ============================================================
       PWA PUSH — unsubscribe current device
       ============================================================ */
    public function pushUnsubscribe(Request $request)
    {
        if (! session('user_id')) abort(401);

        $endpoint = $request->input('endpoint');
        $deleted = $this->push->unsubscribe((int) session('user_id'), is_string($endpoint) ? $endpoint : null);

        return response()
            ->json(['success' => true, 'deleted' => $deleted])
            ->withHeaders($this->noCacheHeaders());
    }

    /* ============================================================
       DELETE — /notifications/{id}/delete
       ============================================================ */
    public function destroy(int $id)
    {
        if (! session('user_id')) abort(401);

        $n = AppNotification::where('user_id', session('user_id'))->findOrFail($id);
        $n->delete();

        return response()
            ->json(['success' => true])
            ->withHeaders($this->noCacheHeaders());
    }
}