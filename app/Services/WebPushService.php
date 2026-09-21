<?php

namespace App\Services;

use App\Models\AppNotification;
use App\Models\PushSubscription;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Minimal native Web Push sender for the NPO CRM PWA.
 *
 * This intentionally avoids a third-party Composer dependency so the CRM can
 * remain deployable in the current hosting environment. Payload encryption
 * follows the aes128gcm Web Push content coding and VAPID uses ES256.
 */
class WebPushService
{
    private const KEY_FILE = 'npo-crm-vapid.json';

    public function publicKey(): string
    {
        return $this->keys()['public'];
    }

    public function subscribe(int $userId, array $subscription): PushSubscription
    {
        $endpoint = trim((string) ($subscription['endpoint'] ?? ''));
        $p256dh = trim((string) ($subscription['keys']['p256dh'] ?? ''));
        $auth = trim((string) ($subscription['keys']['auth'] ?? ''));

        if ($endpoint === '' || $p256dh === '' || $auth === '') {
            throw new RuntimeException('Invalid push subscription payload.');
        }

        if (! filter_var($endpoint, FILTER_VALIDATE_URL) || ! in_array(parse_url($endpoint, PHP_URL_SCHEME), ['https'], true)) {
            throw new RuntimeException('Invalid push subscription endpoint.');
        }

        $latestId = (int) (AppNotification::max('id') ?? 0);

        $existing = PushSubscription::where('endpoint', $endpoint)->first();
        if ($existing) {
            $existing->forceFill([
                'user_id' => $userId,
                'p256dh' => $p256dh,
                'auth' => $auth,
                'content_encoding' => $subscription['contentEncoding'] ?? 'aes128gcm',
                'last_used_at' => now(),
                'last_error_at' => null,
            ])->save();
            return $existing->fresh();
        }

        return PushSubscription::create([
            'user_id' => $userId,
            'endpoint' => $endpoint,
            'p256dh' => $p256dh,
            'auth' => $auth,
            'content_encoding' => $subscription['contentEncoding'] ?? 'aes128gcm',
            'last_notification_id' => $latestId,
            'last_used_at' => now(),
            'last_error_at' => null,
        ]);
    }

    public function unsubscribe(int $userId, ?string $endpoint = null): int
    {
        $query = PushSubscription::where('user_id', $userId);
        if ($endpoint !== null && trim($endpoint) !== '') {
            $query->where('endpoint', trim($endpoint));
        }

        return $query->delete();
    }

    /**
     * Send all newly-created notifications to a subscription, with a small
     * burst guard so one busy CRM session cannot spam a phone.
     */
    /**
     * Send one specific notification immediately to one device. This bypasses
     * the per-device cursor used by the background worker, so a manual test
     * can never be swallowed merely because the minute cron already processed
     * the notification.
     */
    public function sendNotification(PushSubscription $subscription, AppNotification $notification): bool
    {
        $payload = [
            'title' => $notification->title,
            'body' => $notification->body ?: 'Open NPO CRM to review this notification.',
            'icon' => '/icons/icon-192.png',
            'badge' => '/icons/icon-192.png',
            'url' => $notification->action_url ?: '/notifications',
            'notification_id' => $notification->id,
            'priority' => NotificationService::priorityForType($notification->type),
        ];

        $ok = $this->send($subscription, $payload);
        if ($ok) {
            $subscription->forceFill([
                'last_notification_id' => max((int) $subscription->last_notification_id, (int) $notification->id),
                'last_used_at' => now(),
                'last_error_at' => null,
            ])->save();
        }
        return $ok;
    }

    public function sendPending(PushSubscription $subscription): int
    {
        $notifications = AppNotification::where('user_id', $subscription->user_id)
            ->where('id', '>', (int) $subscription->last_notification_id)
            ->orderBy('id')
            ->limit(6)
            ->get();

        if ($notifications->isEmpty()) {
            return 0;
        }

        $sent = 0;
        $highestId = (int) $subscription->last_notification_id;

        if ($notifications->count() > 3) {
            $latest = $notifications->last();
            $payload = [
                'title' => '🔔 NPO CRM — New work',
                'body' => $notifications->count() . ' new notifications need your attention.',
                'icon' => '/icons/icon-192.png',
                'badge' => '/icons/icon-192.png',
                'url' => '/notifications',
                'notification_id' => $latest->id,
                'priority' => 'important',
            ];

            if ($this->send($subscription, $payload)) {
                $sent = $notifications->count();
                $highestId = (int) $latest->id;
            }
        } else {
            foreach ($notifications as $notification) {
                $payload = [
                    'title' => $notification->title,
                    'body' => $notification->body ?: 'Open NPO CRM to review this notification.',
                    'icon' => '/icons/icon-192.png',
                    'badge' => '/icons/icon-192.png',
                    'url' => $notification->action_url ?: '/notifications',
                    'notification_id' => $notification->id,
                    'priority' => NotificationService::priorityForType($notification->type),
                ];

                if (! $this->send($subscription, $payload)) {
                    break;
                }

                $sent++;
                $highestId = (int) $notification->id;
            }
        }

        if ($highestId > (int) $subscription->last_notification_id) {
            $subscription->forceFill([
                'last_notification_id' => $highestId,
                'last_used_at' => now(),
                'last_error_at' => null,
            ])->save();
        }

        return $sent;
    }

    public function statusForUser(int $userId): array
    {
        $rows = PushSubscription::where('user_id', $userId)->orderByDesc('last_used_at')->get();
        return [
            'subscription_count' => $rows->count(),
            'healthy_count' => $rows->filter(fn ($r) => ! $r->last_error_at)->count(),
            'last_used_at' => optional($rows->first())->last_used_at?->toIso8601String(),
            'last_error_at' => optional($rows->sortByDesc('last_error_at')->first())->last_error_at?->toIso8601String(),
        ];
    }

    public function heartbeat(): array
    {
        $path = storage_path('app/npo-crm-push-heartbeat.json');
        if (! is_file($path)) {
            return ['ok' => false, 'last_run_at' => null, 'sent' => 0, 'subscriptions' => 0, 'message' => 'Push worker has not reported a run yet.'];
        }
        $data = json_decode((string) @file_get_contents($path), true);
        return is_array($data) ? $data : ['ok' => false, 'last_run_at' => null, 'sent' => 0, 'subscriptions' => 0, 'message' => 'Invalid push worker heartbeat.'];
    }

    /**
     * Deliver one encrypted Web Push message. Prefer cURL on shared hosting
     * because it gives us reliable TLS/status/error reporting; retain the
     * stream fallback for installations without the PHP cURL extension.
     */
    /** Deliver one encrypted Web Push message. */
    private function send(PushSubscription $subscription, array $payload): bool
    {
        try {
            $endpoint = $subscription->endpoint;
            $url = parse_url($endpoint);
            if (! is_array($url) || empty($url['scheme']) || empty($url['host'])) throw new RuntimeException('Invalid push endpoint.');
            $body = $this->encryptPayload(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), $subscription);
            $audience = $url['scheme'] . '://' . $url['host'] . (! empty($url['port']) ? ':' . $url['port'] : '');
            $jwt = $this->vapidJwt($audience);
            $keys = $this->keys();
            $headers = [
                'Content-Type: application/octet-stream',
                'Content-Encoding: aes128gcm',
                'TTL: 300',
                'Authorization: vapid t=' . $jwt . ', k=' . $keys['public'],
                'User-Agent: NPO-CRM-PWA/1.1',
                'Content-Length: ' . strlen($body),
            ];
            $status = 0; $response = null; $error = null;
            if (function_exists('curl_init')) {
                $ch = curl_init($endpoint);
                curl_setopt_array($ch, [CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$body,CURLOPT_HTTPHEADER=>$headers,CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>12,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_FOLLOWLOCATION=>false]);
                $response = curl_exec($ch); $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE); $error = curl_error($ch) ?: null; curl_close($ch);
            } else {
                $context = stream_context_create(['http'=>['method'=>'POST','header'=>implode("\r\n",$headers),'content'=>$body,'timeout'=>12,'ignore_errors'=>true],'ssl'=>['verify_peer'=>true,'verify_peer_name'=>true]]);
                $response = @file_get_contents($endpoint,false,$context); $status = $this->responseStatus($http_response_header ?? []);
                if ($status===0) $error = error_get_last()['message'] ?? 'HTTP request failed.';
            }
            if ($status===404 || $status===410) { $subscription->delete(); return false; }
            if ($status<200 || $status>=300) {
                $subscription->forceFill(['last_error_at'=>now()])->save();
                Log::warning('NPO CRM Web Push delivery failed',['status'=>$status,'endpoint_host'=>$url['host'],'response'=>is_string($response)?mb_substr($response,0,500):null,'error'=>$error]);
                return false;
            }
            return true;
        } catch (\Throwable $e) {
            $subscription->forceFill(['last_error_at'=>now()])->save();
            Log::warning('NPO CRM Web Push exception',['message'=>$e->getMessage()]);
            return false;
        }
    }

    private function keys(): array
    {
        $path = storage_path('app/' . self::KEY_FILE);

        if (is_file($path)) {
            $data = json_decode((string) file_get_contents($path), true);
            if (is_array($data) && ! empty($data['private_pem']) && ! empty($data['public'])) {
                return $data;
            }
        }

        $dir = dirname($path);
        if (! is_dir($dir) && ! @mkdir($dir, 0775, true) && ! is_dir($dir)) {
            throw new RuntimeException('CRM storage directory is not writable.');
        }

        $private = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_EC,
            'curve_name' => 'prime256v1',
        ]);

        if ($private === false || ! openssl_pkey_export($private, $privatePem)) {
            throw new RuntimeException('Unable to create Web Push VAPID keys.');
        }

        $details = openssl_pkey_get_details($private);
        if (! is_array($details) || empty($details['ec']['x']) || empty($details['ec']['y'])) {
            throw new RuntimeException('Unable to read Web Push VAPID public key.');
        }

        $rawPublic = "\x04" . $details['ec']['x'] . $details['ec']['y'];
        $data = [
            'private_pem' => $privatePem,
            'public' => $this->base64Url($rawPublic),
        ];

        $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
        if (@file_put_contents($path, $json, LOCK_EX) === false) {
            throw new RuntimeException('Unable to persist Web Push VAPID keys.');
        }
        @chmod($path, 0600);

        return $data;
    }

    private function vapidJwt(string $audience): string
    {
        $keys = $this->keys();
        $header = $this->base64Url(json_encode(['typ' => 'JWT', 'alg' => 'ES256'], JSON_THROW_ON_ERROR));
        $payload = $this->base64Url(json_encode([
            'aud' => $audience,
            'exp' => time() + 12 * 60 * 60,
            'sub' => 'mailto:notifications@npocrm.com',
        ], JSON_THROW_ON_ERROR));

        $input = $header . '.' . $payload;
        $signatureDer = '';
        $ok = openssl_sign($input, $signatureDer, $keys['private_pem'], OPENSSL_ALGO_SHA256);
        if (! $ok) {
            throw new RuntimeException('Unable to sign Web Push VAPID token.');
        }

        return $header . '.' . $payload . '.' . $this->base64Url($this->derToJose($signatureDer, 32));
    }

    private function encryptPayload(string $plaintext, PushSubscription $subscription): string
    {
        $uaPublicRaw = $this->base64UrlDecode($subscription->p256dh);
        $auth = $this->base64UrlDecode($subscription->auth);

        if (strlen($uaPublicRaw) !== 65 || $uaPublicRaw[0] !== "\x04" || strlen($auth) < 16) {
            throw new RuntimeException('Invalid browser push encryption keys.');
        }
        $auth = substr($auth, 0, 16);

        $uaPublicPem = $this->rawP256ToPem($uaPublicRaw);
        $ephemeralPrivate = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_EC,
            'curve_name' => 'prime256v1',
        ]);
        if ($ephemeralPrivate === false) {
            throw new RuntimeException('Unable to create push encryption key.');
        }

        $ephemeralDetails = openssl_pkey_get_details($ephemeralPrivate);
        $serverPublicRaw = "\x04" . $ephemeralDetails['ec']['x'] . $ephemeralDetails['ec']['y'];
        $sharedSecret = openssl_pkey_derive($uaPublicPem, $ephemeralPrivate, 32);
        if ($sharedSecret === false) {
            throw new RuntimeException('Unable to derive Web Push shared secret.');
        }

        $keyInfo = "WebPush: info\0" . $uaPublicRaw . $serverPublicRaw;
        $prkKey = hash_hmac('sha256', $sharedSecret, $auth, true);
        $ikm = $this->hkdfExpand($prkKey, $keyInfo, 32);

        $salt = random_bytes(16);
        $prk = hash_hmac('sha256', $ikm, $salt, true);
        $cek = $this->hkdfExpand($prk, "Content-Encoding: aes128gcm\0", 16);
        $nonce = $this->hkdfExpand($prk, "Content-Encoding: nonce\0", 12);

        // RFC 8188: one record, with a 0x02 final-record delimiter.
        $padded = $plaintext . "\x02";
        $tag = '';
        $ciphertext = openssl_encrypt($padded, 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag);
        if ($ciphertext === false) {
            throw new RuntimeException('Unable to encrypt Web Push payload.');
        }

        $recordSize = 4096;
        return $salt . pack('N', $recordSize) . chr(strlen($serverPublicRaw)) . $serverPublicRaw . $ciphertext . $tag;
    }

    private function rawP256ToPem(string $raw): string
    {
        $der = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200') . $raw;
        return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
    }

    private function hkdfExpand(string $prk, string $info, int $length): string
    {
        $out = '';
        $previous = '';
        for ($i = 1; strlen($out) < $length; $i++) {
            $previous = hash_hmac('sha256', $previous . $info . chr($i), $prk, true);
            $out .= $previous;
        }
        return substr($out, 0, $length);
    }

    private function derToJose(string $der, int $partLength): string
    {
        $offset = 0;
        if (ord($der[$offset++]) !== 0x30) {
            throw new RuntimeException('Invalid ECDSA signature.');
        }
        $this->readDerLength($der, $offset);
        if (ord($der[$offset++]) !== 0x02) {
            throw new RuntimeException('Invalid ECDSA R.');
        }
        $rLen = $this->readDerLength($der, $offset);
        $r = substr($der, $offset, $rLen);
        $offset += $rLen;
        if (ord($der[$offset++]) !== 0x02) {
            throw new RuntimeException('Invalid ECDSA S.');
        }
        $sLen = $this->readDerLength($der, $offset);
        $s = substr($der, $offset, $sLen);
        return str_pad(ltrim($r, "\0"), $partLength, "\0", STR_PAD_LEFT)
            . str_pad(ltrim($s, "\0"), $partLength, "\0", STR_PAD_LEFT);
    }

    private function readDerLength(string $data, int &$offset): int
    {
        $length = ord($data[$offset++]);
        if ($length & 0x80) {
            $bytes = $length & 0x7f;
            $length = 0;
            for ($i = 0; $i < $bytes; $i++) {
                $length = ($length << 8) | ord($data[$offset++]);
            }
        }
        return $length;
    }

    private function base64Url(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $data): string
    {
        $padded = strtr($data, '-_', '+/');
        $padded .= str_repeat('=', (4 - strlen($padded) % 4) % 4);
        $decoded = base64_decode($padded, true);
        if ($decoded === false) {
            throw new RuntimeException('Invalid base64url value.');
        }
        return $decoded;
    }

    private function responseStatus(array $headers): int
    {
        foreach (array_reverse($headers) as $header) {
            if (preg_match('/^HTTP\/\S+\s+(\d{3})/', $header, $m)) {
                return (int) $m[1];
            }
        }
        return 0;
    }
}
