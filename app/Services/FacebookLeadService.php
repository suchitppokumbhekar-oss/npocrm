<?php

namespace App\Services;

use App\Models\Lead;
use App\Models\Project;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class FacebookLeadService
{
    private const GRAPH_VERSION = 'v23.0';

    public function __construct(private SettingsService $settings) {}

    /* ============================================================
       System User token
       ============================================================ */

    public function getSystemUserToken(): ?string
    {
        $t = (string) $this->settings->get('meta_system_user_token_raw', '');
        return $t !== '' ? $t : null;
    }

    public function saveSystemUserToken(string $token): array
    {
        $token = trim($token);
        if ($token === '') {
            throw new \InvalidArgumentException('Token is empty.');
        }

        $verify = Http::get('https://graph.facebook.com/' . self::GRAPH_VERSION . '/me/permissions', [
            'access_token' => $token,
        ]);

        if (! $verify->successful()) {
            throw new \RuntimeException(
                'Facebook rejected the token: ' . ($verify->json('error.message') ?? 'unknown error')
            );
        }

        $perms = array_column($verify->json('data', []), 'permission');
        if (! in_array('leads_retrieval', $perms, true)) {
            throw new \RuntimeException(
                'Token is missing leads_retrieval. Regenerate it in Business Manager and click '
                . 'leads_retrieval FIRST (Meta silently drops it if you click another permission first).'
            );
        }

        $this->settings->set('meta_system_user_token_raw', $token);

        return $this->refreshPageTokens();
    }

    public function refreshPageTokens(): array
    {
        $token = $this->getSystemUserToken();
        if (! $token) {
            throw new \RuntimeException('No System User token stored yet.');
        }

        $res = Http::get('https://graph.facebook.com/' . self::GRAPH_VERSION . '/me/accounts', [
            'access_token' => $token,
        ]);

        if (! $res->successful()) {
            throw new \RuntimeException('Failed to fetch pages: ' . ($res->json('error.message') ?? 'unknown'));
        }

        $pageTokens = [];
        $pages      = [];

        foreach ($res->json('data', []) as $page) {
            if (empty($page['id']) || empty($page['access_token'])) {
                continue;
            }
            $pageTokens[$page['id']] = $page['access_token'];
            $pages[] = ['id' => $page['id'], 'name' => $page['name'] ?? '(unnamed)'];
        }

        $this->settings->set('meta_page_tokens',    json_encode($pageTokens));
        $this->settings->set('meta_pages_cache',    json_encode($pages));
        $this->settings->set('meta_pages_synced_at', now()->toDateTimeString());

        // If a page is already connected, refresh the token the job reads
        $connected = (string) $this->settings->get('meta_connected_page_id', '');
        if ($connected && ! empty($pageTokens[$connected])) {
            $this->settings->set('meta_system_user_token', $pageTokens[$connected]);
        }

        return $pages;
    }

    public function getPagesSyncedAt(): ?string
    {
        $v = (string) $this->settings->get('meta_pages_synced_at', '');
        return $v !== '' ? $v : null;
    }

    /* ============================================================
       Multi-page: subscribe all pages to the webhook at once
       ============================================================ */

    public function subscribeAllPages(): array
    {
        $pages   = $this->getPages();
        $results = [
            'subscribed' => [],
            'failed'     => [],
            'already'    => [],
        ];

        foreach ($pages as $page) {
            $pageId = $page['id'] ?? null;
            if (! $pageId) continue;

            try {
                if ($this->isPageSubscribed($pageId)) {
                    $results['already'][] = $page['name'] ?? $pageId;
                    continue;
                }

                $ok = $this->subscribePageToWebhook($pageId);

                if ($ok) {
                    $results['subscribed'][] = $page['name'] ?? $pageId;
                } else {
                    $results['failed'][] = $page['name'] ?? $pageId;
                }
            } catch (\Throwable $e) {
                $results['failed'][] = ($page['name'] ?? $pageId) . ' (' . $e->getMessage() . ')';
            }
        }

        return $results;
    }

    public function getAllPagesSubscriptionStatus(): array
    {
        $out = [];

        foreach ($this->getPages() as $page) {
            $pageId = $page['id'] ?? null;
            if (! $pageId) continue;

            $out[] = [
                'id'         => $pageId,
                'name'       => $page['name'] ?? '(unnamed)',
                'subscribed' => $this->isPageSubscribed($pageId),
            ];
        }

        return $out;
    }

    /**
     * Fetch forms from ALL pages, tagged with their page.
     * Returns a flat array of forms, each with page_id + page_name merged in.
     */
    public function fetchFormsForAllPages(): array
    {
        $all   = [];
        $pages = $this->getPages();

        foreach ($pages as $page) {
            $pageId   = $page['id']   ?? null;
            $pageName = $page['name'] ?? '(unnamed)';

            if (! $pageId) continue;

            try {
                $forms = $this->fetchFormsForPage($pageId);

                foreach ($forms as $form) {
                    $form['page_id']   = $pageId;
                    $form['page_name'] = $pageName;
                    $all[] = $form;
                }
            } catch (\Throwable $e) {
                Log::warning("Forms fetch failed for page {$pageName}", [
                    'page_id' => $pageId,
                    'error'   => $e->getMessage(),
                ]);
            }
        }

        // Sort by lead count desc
        usort($all, fn ($a, $b) => (int) ($b['leads_count'] ?? 0) <=> (int) ($a['leads_count'] ?? 0));

        return $all;
    }

    public function getFormsCacheKey(): string
    {
        return 'meta_forms_cache_all';
    }

    public function getFormsSyncedAtAll(): ?string
    {
        $v = (string) $this->settings->get('meta_forms_synced_at_all', '');
        return $v !== '' ? $v : null;
    }

    public function fetchAndCacheAllForms(): array
    {
        $forms = $this->fetchFormsForAllPages();
        $this->settings->set('meta_forms_cache_all', json_encode($forms));
        $this->settings->set('meta_forms_synced_at_all', now()->toDateTimeString());
        return $forms;
    }

    public function getFormsCachedAll(): array
    {
        return json_decode((string) $this->settings->get('meta_forms_cache_all', '[]'), true) ?: [];
    }

    /* ============================================================
       Pages
       ============================================================ */

    public function getPages(): array
    {
        return json_decode((string) $this->settings->get('meta_pages_cache', '[]'), true) ?: [];
    }

    public function getPageToken(string $pageId): ?string
    {
        $map = json_decode((string) $this->settings->get('meta_page_tokens', '{}'), true) ?: [];
        return $map[$pageId] ?? null;
    }

    public function setConnectedPage(string $pageId): void
    {
        $this->settings->set('meta_connected_page_id', $pageId);

        $token = $this->getPageToken($pageId);
        if ($token) {
            $this->settings->set('meta_system_user_token', $token);
        }
    }

    public function getConnectedPageId(): ?string
    {
        $v = (string) $this->settings->get('meta_connected_page_id', '');
        return $v !== '' ? $v : null;
    }

    public function getConnectedPageName(): ?string
    {
        $id = $this->getConnectedPageId();
        if (! $id) return null;

        foreach ($this->getPages() as $p) {
            if (($p['id'] ?? null) === $id) return $p['name'];
        }
        return null;
    }

    /* ============================================================
       Forms
       ============================================================ */

        public function fetchFormsForPage(string $pageId): array
    {
        $token = $this->getPageToken($pageId);
        if (! $token) {
            throw new \RuntimeException("No page token for {$pageId}. Refresh tokens first.");
        }

        $all = [];
        $url = 'https://graph.facebook.com/' . self::GRAPH_VERSION . "/{$pageId}/leadgen_forms";

        for ($i = 0; $i < 10; $i++) {
            if ($i === 0) {
                // First page — build the query
                $res = Http::get($url, [
                    'access_token' => $token,
                    'fields'       => 'id,name,status,leads_count',
                    'limit'        => 100,
                ]);
            } else {
                // Pagination URL already contains access_token, fields, limit and after cursor.
                // Do NOT pass extra params here — Laravel would append them and Meta
                // would ignore the cursor and re-serve page 1.
                $res = Http::get($url);
            }

            if (! $res->successful()) {
                throw new \RuntimeException(
                    'Failed to fetch forms: ' . ($res->json('error.message') ?? 'unknown error')
                );
            }

            foreach ($res->json('data', []) as $f) {
                $all[] = $f;
            }

            $next = $res->json('paging.next');
            if (! $next) break;
            $url = $next;
        }

        // Defensive dedupe by form id
        $byId = [];
        foreach ($all as $f) {
            if (! empty($f['id'])) {
                $byId[$f['id']] = $f;
            }
        }
        $all = array_values($byId);

        // Sort by lead count desc
        usort($all, fn ($a, $b) => (int) ($b['leads_count'] ?? 0) <=> (int) ($a['leads_count'] ?? 0));

        $this->settings->set('meta_forms_cache_' . $pageId, json_encode($all));
        $this->settings->set('meta_forms_synced_at_' . $pageId, now()->toDateTimeString());

        return $all;
    }

    public function getFormsCached(string $pageId): array
    {
        return json_decode((string) $this->settings->get('meta_forms_cache_' . $pageId, '[]'), true) ?: [];
    }

    public function getFormsSyncedAt(string $pageId): ?string
    {
        $v = (string) $this->settings->get('meta_forms_synced_at_' . $pageId, '');
        return $v !== '' ? $v : null;
    }

    /* ============================================================
       Webhook subscription
       ============================================================ */

    public function isPageSubscribed(string $pageId): bool
    {
        $token = $this->getPageToken($pageId);
        if (! $token) return false;

        $res = Http::get('https://graph.facebook.com/' . self::GRAPH_VERSION . "/{$pageId}/subscribed_apps", [
            'access_token' => $token,
        ]);

        if (! $res->successful()) return false;

        foreach ($res->json('data', []) as $app) {
            if (! empty($app['subscribed_fields']) && in_array('leadgen', $app['subscribed_fields'], true)) {
                return true;
            }
        }
        return false;
    }

    public function subscribePageToWebhook(string $pageId): bool
    {
        $token = $this->getPageToken($pageId);
        if (! $token) {
            throw new \RuntimeException("No page token for {$pageId}.");
        }

        $res = Http::asForm()->post('https://graph.facebook.com/' . self::GRAPH_VERSION . "/{$pageId}/subscribed_apps", [
            'access_token'      => $token,
            'subscribed_fields' => 'leadgen',
        ]);

        $ok = $res->successful() && ($res->json('success') === true);

        if (! $ok) {
            Log::warning('Facebook subscribe failed', [
                'page_id' => $pageId,
                'body'    => $res->body(),
            ]);
        }

        return $ok;
    }

    /* ============================================================
       Form → Project mapping
       ============================================================ */

    public function getFormProjectMap(): array
    {
        return json_decode((string) $this->settings->get('meta_form_project_map', '{}'), true) ?: [];
    }

    public function setFormProject(string $formId, ?int $projectId): void
    {
        $map = $this->getFormProjectMap();
        if ($projectId === null || $projectId === 0) {
            unset($map[$formId]);
        } else {
            $map[$formId] = (int) $projectId;
        }
        $this->settings->set('meta_form_project_map', json_encode($map));
    }

    /* ============================================================
       Diagnostics
       ============================================================ */

    public function getDiagnostics(): array
    {
        return [
            'pending_jobs'    => (int) DB::table('jobs')->count(),
            'failed_jobs_24h' => (int) DB::table('failed_jobs')
                                    ->where('failed_at', '>', now()->subDay())
                                    ->count(),
            'recent_leads'    => Lead::where('source', 'facebook')
                                    ->orderByDesc('id')
                                    ->limit(5)
                                    ->get(['id', 'customer_name', 'phone', 'project_id', 'created_at'])
                                    ->map(fn ($l) => [
                                        'id'         => $l->id,
                                        'name'       => $l->customer_name,
                                        'phone'      => $l->phone,
                                        'project_id' => $l->project_id,
                                        'created_at' => $l->created_at?->format('d M H:i'),
                                    ])
                                    ->all(),
            'active_projects' => Project::where('status', 'active')->count(),
        ];
    }
}