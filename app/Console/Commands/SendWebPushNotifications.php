<?php

namespace App\Console\Commands;

use App\Models\PushSubscription;
use App\Services\WebPushService;
use App\Services\NotificationService;
use Illuminate\Console\Command;

class SendWebPushNotifications extends Command
{
    protected $signature = 'notifications:push';
    protected $description = 'Deliver newly-created CRM notifications to subscribed PWA devices.';

    public function handle(WebPushService $push, NotificationService $notifications): int
    {
        $sent = 0;
        $subscriptions = PushSubscription::query()->orderBy('id')->get();

        foreach ($subscriptions as $subscription) {
            // Reconcile before delivery so a task completed through another
            // CRM surface does not become a stale phone alert.
            $notifications->reconcileUnread((int) $subscription->user_id);
            $sent += $push->sendPending($subscription);
        }

        $path = storage_path('app/npo-crm-push-heartbeat.json');
        @file_put_contents($path, json_encode([
            'ok' => true,
            'last_run_at' => now()->toIso8601String(),
            'sent' => $sent,
            'subscriptions' => $subscriptions->count(),
            'message' => 'Push worker ran successfully.',
        ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));

        $this->info("Delivered {$sent} push notification(s) to {$subscriptions->count()} subscription(s).");
        return self::SUCCESS;
    }
}
