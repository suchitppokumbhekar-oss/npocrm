<?php

namespace App\Http\Controllers;

use App\Models\FacebookIntegration;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class FacebookController extends Controller
{
    private const GRAPH_VERSION = 'v20.0';
    private const OAUTH_DIALOG  = 'https://www.facebook.com/' . self::GRAPH_VERSION . '/dialog/oauth';
    private const GRAPH_BASE    = 'https://graph.facebook.com/' . self::GRAPH_VERSION;

    private const SCOPES = [
        'pages_show_list',
        'pages_read_engagement',
        'pages_manage_ads',
        'leads_retrieval',
        'business_management',
    ];

    /* ============================================================
       GUARD
       ============================================================ */
    private function requireAdmin(): void
    {
        if (session('user_role') !== 'admin') {
            abort(403, 'Admin only.');
        }
    }

    /* ============================================================
       REDIRECT TO FACEBOOK
       ============================================================ */
    public function redirect()
    {
        $this->requireAdmin();

        $clientId    = config('services.facebook.client_id');
        $redirectUri = config('services.facebook.redirect');

        if (! $clientId || ! $redirectUri) {
            return redirect('/settings?tab=facebook')
                ->withErrors(['facebook' => 'Facebook credentials not configured. Check .env.']);
        }

        // CSRF protection
        $state = bin2hex(random_bytes(16));
        session(['fb_oauth_state' => $state]);

        $url = self::OAUTH_DIALOG . '?' . http_build_query([
            'client_id'     => $clientId,
            'redirect_uri'  => $redirectUri,
            'state'         => $state,
            'scope'         => implode(',', self::SCOPES),
            'response_type' => 'code',
        ]);

        return redirect($url);
    }

    /* ============================================================
       HANDLE CALLBACK
       ============================================================ */
    public function callback(Request $request)
    {
        $this->requireAdmin();

        // 1. User clicked "Cancel" or Meta returned an error
        if ($request->has('error')) {
            return redirect('/settings?tab=facebook')
                ->withErrors(['facebook' => 'Facebook connection failed: ' . $request->input('error_description', 'Unknown error')]);
        }

        // 2. Verify state (CSRF)
        $state = $request->input('state');
        $expectedState = session('fb_oauth_state');
        session()->forget('fb_oauth_state');

        if (! $state || $state !== $expectedState) {
            return redirect('/settings?tab=facebook')
                ->withErrors(['facebook' => 'Invalid OAuth state. Please try again.']);
        }

        // 3. Check for code
        $code = $request->input('code');
        if (! $code) {
            return redirect('/settings?tab=facebook')
                ->withErrors(['facebook' => 'No authorization code received.']);
        }

        // 4. Exchange code → short-lived user access token
        $shortToken = $this->exchangeCodeForToken($code);

        if (! $shortToken) {
            return redirect('/settings?tab=facebook')
                ->withErrors(['facebook' => 'Failed to exchange code for access token.']);
        }

        // 5. Exchange short-lived → long-lived token (60 days)
        $longToken = $this->exchangeForLongLivedToken($shortToken['access_token']);

        if (! $longToken) {
            return redirect('/settings?tab=facebook')
                ->withErrors(['facebook' => 'Failed to obtain long-lived token.']);
        }

        // 6. Fetch user profile
        $me = $this->graphGet('/me', ['fields' => 'id,name'], $longToken['access_token']);

        if (! $me || empty($me['id'])) {
            return redirect('/settings?tab=facebook')
                ->withErrors(['facebook' => 'Failed to fetch Facebook profile.']);
        }

        // 7. Fetch pages this user manages
        $pages = $this->fetchPages($longToken['access_token']);

        // 8. Persist
        $expiresAt = isset($longToken['expires_in'])
            ? now()->addSeconds((int) $longToken['expires_in'])
            : null;

        FacebookIntegration::updateOrCreate(
            ['user_id' => session('user_id')],
            [
                'fb_user_id'         => $me['id'],
                'fb_user_name'       => $me['name'] ?? 'Facebook User',
                'user_access_token'  => $longToken['access_token'],
                'token_expires_at'   => $expiresAt,
                'pages_json'         => json_encode($pages),
                'selected_page_id'   => null,
                'selected_page_name' => null,
                'selected_page_token'=> null,
                'status'             => 'connected',
                'last_synced_at'     => now(),
            ]
        );

        return redirect('/settings?tab=facebook')
            ->with('success', '✅ Facebook connected! Now select the Page you want to receive leads from.');
    }

    /* ============================================================
       SELECT PAGE
       ============================================================ */
        public function selectPage(Request $request)
    {
        $this->requireAdmin();

        $validated = $request->validate([
            'page_id' => 'required|string|max:64',
        ]);

        $integration = FacebookIntegration::where('user_id', session('user_id'))->first();
        if (! $integration) {
            return back()->withErrors(['facebook' => 'Not connected to Facebook.']);
        }

        $pages = $integration->pages();
        $selected = null;
        foreach ($pages as $p) {
            if ((string) $p['id'] === (string) $validated['page_id']) {
                $selected = $p;
                break;
            }
        }

        if (! $selected) {
            return back()->withErrors(['facebook' => 'Page not found in your account.']);
        }

        // Persist selection
        $integration->update([
            'selected_page_id'    => $selected['id'],
            'selected_page_name'  => $selected['name'] ?? 'Page',
            'selected_page_token' => $selected['access_token'] ?? null,
        ]);

        // Push token + page id into settings (used by FetchMetaLead)
        $settings = app(\App\Services\SettingsService::class);
        $settings->set('meta_page_access_token', $selected['access_token'] ?? '');
        $settings->set('meta_page_id', $selected['id']);

        // Auto-subscribe this Page to leadgen webhook events
        $subscribed = false;
        try {
            $res = \Illuminate\Support\Facades\Http::post(
                'https://graph.facebook.com/v20.0/' . $selected['id'] . '/subscribed_apps',
                [
                    'access_token'      => $selected['access_token'],
                    'subscribed_fields' => 'leadgen',
                ]
            );
            if ($res->successful() && ($res->json('success') ?? false)) {
                $subscribed = true;
            } else {
                \Log::warning('Auto-subscribe failed for page ' . $selected['id'], ['body' => $res->body()]);
            }
        } catch (\Throwable $e) {
            \Log::error('Auto-subscribe exception: ' . $e->getMessage());
        }

        $msg = '✅ Page selected: ' . ($selected['name'] ?? 'Page');
        $msg .= $subscribed ? ' · Subscribed to leadgen ✓' : ' · ⚠️ Auto-subscribe failed (do it manually)';

        return back()->with('success', $msg);
    }

    /* ============================================================
       DISCONNECT
       ============================================================ */
    public function disconnect()
    {
        $this->requireAdmin();

        FacebookIntegration::where('user_id', session('user_id'))->delete();

        app(\App\Services\SettingsService::class)->set('meta_page_access_token', '');
        app(\App\Services\SettingsService::class)->set('meta_page_id', '');

        return back()->with('success', '🔌 Facebook disconnected.');
    }

    /* ============================================================
       REFRESH PAGES (manual)
       ============================================================ */
    public function refreshPages()
    {
        $this->requireAdmin();

        $integration = FacebookIntegration::where('user_id', session('user_id'))->first();
        if (! $integration) {
            return back()->withErrors(['facebook' => 'Not connected.']);
        }

        $pages = $this->fetchPages($integration->user_access_token);
        $integration->update([
            'pages_json'     => json_encode($pages),
            'last_synced_at' => now(),
        ]);

        return back()->with('success', '🔄 Pages refreshed.');
    }

    /* ============================================================
       HELPERS
       ============================================================ */

    private function exchangeCodeForToken(string $code): ?array
    {
        try {
            $res = Http::get(self::GRAPH_BASE . '/oauth/access_token', [
                'client_id'     => config('services.facebook.client_id'),
                'client_secret' => config('services.facebook.client_secret'),
                'redirect_uri'  => config('services.facebook.redirect'),
                'code'          => $code,
            ]);

            if (! $res->successful()) {
                Log::error('FB code exchange failed: ' . $res->body());
                return null;
            }

            return $res->json();
        } catch (\Throwable $e) {
            Log::error('FB code exchange exception: ' . $e->getMessage());
            return null;
        }
    }

    private function exchangeForLongLivedToken(string $shortToken): ?array
    {
        try {
            $res = Http::get(self::GRAPH_BASE . '/oauth/access_token', [
                'grant_type'        => 'fb_exchange_token',
                'client_id'         => config('services.facebook.client_id'),
                'client_secret'     => config('services.facebook.client_secret'),
                'fb_exchange_token' => $shortToken,
            ]);

            if (! $res->successful()) {
                Log::error('FB long-lived exchange failed: ' . $res->body());
                return null;
            }

            return $res->json();
        } catch (\Throwable $e) {
            Log::error('FB long-lived exception: ' . $e->getMessage());
            return null;
        }
    }

    private function fetchPages(string $userToken): array
    {
        try {
            $res = Http::get(self::GRAPH_BASE . '/me/accounts', [
                'fields'       => 'id,name,access_token,category,tasks',
                'access_token' => $userToken,
                'limit'        => 100,
            ]);

            if (! $res->successful()) {
                Log::error('FB pages fetch failed: ' . $res->body());
                return [];
            }

            return $res->json('data', []) ?: [];
        } catch (\Throwable $e) {
            Log::error('FB pages fetch exception: ' . $e->getMessage());
            return [];
        }
    }

    private function graphGet(string $path, array $params, string $token): ?array
    {
        try {
            $params['access_token'] = $token;
            $res = Http::get(self::GRAPH_BASE . $path, $params);

            if (! $res->successful()) {
                Log::error("FB graph GET {$path} failed: " . $res->body());
                return null;
            }

            return $res->json();
        } catch (\Throwable $e) {
            Log::error("FB graph GET {$path} exception: " . $e->getMessage());
            return null;
        }
    }
}