<?php

namespace App\Http\Controllers;

use App\Jobs\FetchMetaLead;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class MetaWebhookController extends Controller
{
    /**
     * GET — Meta's verification handshake when saving the webhook in the dashboard.
     */
        public function verify(Request $request)
    {
        $verifyToken = config('services.meta.verify_token');

        // Meta sends hub.mode / hub.challenge / hub.verify_token (DOTS)
        // Our test URLs use underscores. Support both.
        $mode      = $request->query('hub_mode')      ?? $request->query('hub.mode');
        $token     = $request->query('hub_verify_token') ?? $request->query('hub.verify_token');
        $challenge = $request->query('hub_challenge') ?? $request->query('hub.challenge');

        Log::info('Meta webhook verify attempt', [
            'mode'      => $mode,
            'match'     => $token === $verifyToken,
            'challenge' => $challenge,
        ]);

        if ($mode === 'subscribe' && $token === $verifyToken) {
            // Return the challenge as plain text
            return response((string) $challenge, 200)
                ->header('Content-Type', 'text/plain');
        }

        return response('Forbidden', 403);
    }

    /**
     * POST — Meta sends leadgen events here in real time.
     */
    public function handle(Request $request)
    {
        if (! $this->isValidSignature($request)) {
            Log::warning('Meta webhook signature mismatch');
            return response('Invalid signature', 403);
        }

        $entries = $request->input('entry', []);

        foreach ($entries as $entry) {
            $changes = $entry['changes'] ?? [];

            foreach ($changes as $change) {
                if (($change['field'] ?? '') !== 'leadgen') {
                    continue;
                }

                $leadId = $change['value']['leadgen_id'] ?? null;
                $pageId = $change['value']['page_id'] ?? null;

                if ($leadId) {
                    Log::info('Meta leadgen event received', [
                        'leadgen_id' => $leadId,
                        'page_id'    => $pageId,
                    ]);

                    FetchMetaLead::dispatch($leadId, $pageId);
                }
            }
        }

        return response('EVENT_RECEIVED', 200);
    }

    private function isValidSignature(Request $request): bool
    {
        $signature = $request->header('X-Hub-Signature-256');
        if (! $signature) return false;

        $appSecret = config('services.meta.app_secret');
        if (! $appSecret) return false;

        $payload  = $request->getContent();
        $expected = 'sha256=' . hash_hmac('sha256', $payload, $appSecret);

        return hash_equals($expected, $signature);
    }
}