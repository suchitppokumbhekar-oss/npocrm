<?php

namespace App\Http\Controllers;

use App\Services\LeadIntakeService;
use App\Services\SettingsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

class PublicLeadController extends Controller
{
    public function __construct(
        private SettingsService $settings,
        private LeadIntakeService $intake,
    ) {}

    public function preflight()
    {
        return response('', 204)->withHeaders($this->corsHeaders());
    }

    public function intake(Request $request)
    {
        $cors = $this->corsHeaders();

        // 1. Enabled?
        if ($this->settings->get('website_intake_enabled', '1') !== '1') {
            return response()->json(
                ['success' => false, 'message' => 'Intake temporarily disabled.'],
                503
            )->withHeaders($cors);
        }

        // 2. Optional API key
        if ($this->settings->get('website_intake_require_api_key', '0') === '1') {
            $expected = (string) $this->settings->get('website_intake_api_key', '');
            $provided = (string) ($request->header('X-API-Key') ?? $request->input('api_key', ''));
            if ($expected === '' || ! hash_equals($expected, $provided)) {
                return response()->json(['success' => false, 'message' => 'Unauthorized.'], 401)->withHeaders($cors);
            }
        }

        // 3. Honeypot
        if ($request->filled('website')) {
            return response()->json([
                'success' => true,
                'message' => "Thanks! We'll be in touch.",
            ], 200)->withHeaders($cors);
        }

        // 4. Rate limit
        $rateKey = 'webintake:' . $request->ip();
        $max = max(1, min(60, (int) $this->settings->get('website_intake_rate_per_minute', 5)));
        if (RateLimiter::tooManyAttempts($rateKey, $max)) {
            $seconds = RateLimiter::availableIn($rateKey);
            return response()->json([
                'success' => false,
                'message' => "Too many submissions. Try again in {$seconds}s.",
            ], 429)->withHeaders($cors);
        }
        RateLimiter::hit($rateKey, 60);

        // 5. Normalize + intake
        $raw     = $request->all();
        $channel = (string) ($request->input('channel') ?? 'website');
        $payload = $this->intake->normalize($raw);

        $result = $this->intake->intake($payload, $channel, $raw);

        if (! $result['success']) {
            return response()->json([
                'success' => false,
                'message' => $result['message'],
                'errors'  => $result['errors'],
            ], 422)->withHeaders($cors);
        }

        return response()->json([
            'success'   => true,
            'message'   => "Thanks! Our team will contact you shortly.",
            'lead_id'   => $result['lead']?->id,
            'duplicate' => $result['duplicate'],
        ], 200)->withHeaders($cors);
    }

    private function corsHeaders(): array
    {
        return [
            'Access-Control-Allow-Origin'  => '*',
            'Access-Control-Allow-Methods' => 'POST, OPTIONS',
            'Access-Control-Allow-Headers' => 'Content-Type, X-API-Key, Accept',
            'Access-Control-Max-Age'       => '86400',
        ];
    }
}