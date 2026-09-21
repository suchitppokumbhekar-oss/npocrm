<?php

namespace App\Http\Middleware;

use App\Services\AuditLogService;
use Closure;
use Illuminate\Http\Request;

class AuditNonLeadActions
{
    public function __construct(private AuditLogService $audit) {}

    public function handle(Request $request, Closure $next)
    {
        // Lead/customer-facing work already has its own canonical history.
        // Do not duplicate those events in the vigilance ledger.
        if ($this->isExcluded($request)) {
            return $next($request);
        }

        $actorId   = (int) session('user_id') ?: null;
        $actorName = session('user_name');
        $actorRole = session('user_role');

        $response = $next($request);

        if (! $actorId) {
            return $response;
        }

        $status = method_exists($response, 'getStatusCode') ? $response->getStatusCode() : 200;
        $disposition = (string) $response->headers->get('Content-Disposition', '');
        $isDownload = $disposition !== '' && str_contains(strtolower($disposition), 'attachment');

        if ($isDownload) {
            $filename = null;
            if (preg_match('/filename\*?=(?:UTF-8\'\')?"?([^";]+)"?/i', $disposition, $m)) {
                $filename = trim($m[1]);
            }

            $this->audit->record(
                'download',
                'Downloaded file',
                $request,
                [
                    'filename' => $filename,
                    'content_type' => $response->headers->get('Content-Type'),
                ],
                null,
                null,
                $actorId,
                $actorName,
                $actorRole,
                $status
            );
        }

        // Record every non-GET mutation outside the lead workflow.
        if (! $request->isMethod('GET') && ! $request->isMethod('HEAD')) {
            $this->audit->record(
                'system_action',
                strtoupper($request->method()) . ' ' . ($request->route()?->getName() ?: $request->path()),
                $request,
                $this->audit->requestDetails($request, true),
                null,
                null,
                $actorId,
                $actorName,
                $actorRole,
                $status
            );
        }

        // Failed authenticated requests are security-relevant even when they are GETs.
        if ($status >= 400) {
            $this->audit->record(
                'security',
                'Request failed with HTTP ' . $status,
                $request,
                $this->audit->requestDetails($request, false),
                null,
                null,
                $actorId,
                $actorName,
                $actorRole,
                $status
            );
        }

        // Sensitive read pages are useful for vigilance too (reports/settings/imports).
        if ($request->isMethod('GET') && $this->isSensitiveRead($request)) {
            $this->audit->record(
                'system_view',
                'Viewed ' . ($request->route()?->getName() ?: $request->path()),
                $request,
                $this->audit->requestDetails($request),
                null,
                null,
                $actorId,
                $actorName,
                $actorRole,
                $status
            );
        }

        return $response;
    }

    private function isExcluded(Request $request): bool
    {
        $path = trim($request->path(), '/');
        foreach ([
            'leads', 'activities', 'followups', 'modals',
            'notifications/unread-count', 'api/session-check',
        ] as $prefix) {
            if ($path === $prefix || str_starts_with($path, $prefix . '/')) {
                return true;
            }
        }

        return $path === 'api/leads' || str_starts_with($path, 'webhooks/meta/lead');
    }

    private function isSensitiveRead(Request $request): bool
    {
        $path = trim($request->path(), '/');
        return $path === 'export/leads'
            || str_starts_with($path, 'reports')
            || str_starts_with($path, 'settings')
            || str_starts_with($path, 'import')
            || str_starts_with($path, 'contacts/import')
            || str_starts_with($path, 'admin/')
            || str_starts_with($path, 'my-whatsapp');
    }
}
