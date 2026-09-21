<?php

namespace App\Services;

use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AuditLogService
{
    /**
     * System-wide vigilance ledger. Never record passwords, CSRF tokens,
     * access tokens, session IDs, or full request bodies here.
     */
    public function record(
        string $category,
        string $action,
        ?Request $request = null,
        array $details = [],
        ?string $targetType = null,
        $targetId = null,
        ?int $actorUserId = null,
        ?string $actorName = null,
        ?string $actorRole = null,
        ?int $statusCode = null,
    ): void {
        try {
            $request ??= request();

            $userId = $actorUserId ?? (int) session('user_id') ?: null;
            $userName = $actorName ?? session('user_name');
            $role = $actorRole ?? session('user_role');

            DB::transaction(function () use (
                $userId, $userName, $role, $category, $action, $targetType, $targetId,
                $request, $statusCode, $details
            ): void {
                // Serialize audit writers, including the empty-ledger case.
                $lockName = 'npo_crm_audit_chain';
                $lockResult = DB::selectOne('SELECT GET_LOCK(?, 5) AS acquired', [$lockName]);
                if ((int) ($lockResult->acquired ?? 0) !== 1) {
                    throw new \RuntimeException('Could not acquire audit-chain writer lock.');
                }
                try {
                    $previousHash = AuditLog::query()
                        ->orderByDesc('id')
                        ->value('hash') ?? AuditLog::GENESIS_HASH;

                $event = new AuditLog([
                    'actor_user_id' => $userId,
                    'actor_name'    => $userName ? substr((string) $userName, 0, 150) : null,
                    'actor_role'    => $role ? substr((string) $role, 0, 50) : null,
                    'event_category'=> substr($category, 0, 50),
                    'action'        => substr($action, 0, 120),
                    'target_type'   => $targetType ? substr($targetType, 0, 100) : null,
                    'target_id'     => is_numeric($targetId) ? (int) $targetId : null,
                    'route_name'    => $request->route()?->getName() ? substr((string) $request->route()->getName(), 0, 150) : null,
                    'method'        => strtoupper($request->method()),
                    'path'          => substr('/' . ltrim($request->path(), '/'), 0, 500),
                    'status_code'   => $statusCode,
                    'ip_address'    => $request->ip() ? substr($request->ip(), 0, 45) : null,
                    'user_agent'    => $request->userAgent() ? substr($request->userAgent(), 0, 500) : null,
                    'details'       => $this->sanitizeDetails($details),
                    'created_at'    => now(),
                    'previous_hash' => $previousHash,
                    'hash_version'  => 1,
                ]);
                    $event->save();
                    $event->forceFill(['hash' => $event->calculateHash()])->saveQuietly();
                } finally {
                    DB::select('SELECT RELEASE_LOCK(?) AS released', [$lockName]);
                }
            });
        } catch (\Throwable $e) {
            // Audit failure must never break the business request.
            Log::warning('AuditLogService failed', [
                'action' => $action,
                'error'  => $e->getMessage(),
            ]);
        }
    }

    /** Initialize or complete integrity metadata for a legacy audit ledger. */
    public function initializeLegacyChain(): int
    {
        return DB::transaction(function (): int {
            $lockName = 'npo_crm_audit_chain';
            $lockResult = DB::selectOne('SELECT GET_LOCK(?, 10) AS acquired', [$lockName]);
            if ((int) ($lockResult->acquired ?? 0) !== 1) {
                throw new \RuntimeException('Could not acquire audit-chain writer lock.');
            }
            try {
                $hasLegacy = AuditLog::query()->whereNull('hash')->exists();
                if (! $hasLegacy) {
                    return 0;
                }

                $previous = AuditLog::GENESIS_HASH;
                $count = 0;
                // Rebuild the complete chain in immutable ID order. This only writes
                // integrity metadata; it never changes the underlying audit event.
                AuditLog::query()->orderBy('id')->chunkById(500, function ($logs) use (&$previous, &$count): void {
                    foreach ($logs as $log) {
                        $log->previous_hash = $previous;
                        $log->hash_version = 1;
                        $hash = $log->calculateHash();
                        DB::table('audit_logs')->where('id', $log->id)->update([
                            'previous_hash' => $previous,
                            'hash' => $hash,
                            'hash_version' => 1,
                        ]);
                        $previous = $hash;
                        $count++;
                    }
                });
                return $count;
            } finally {
                DB::select('SELECT RELEASE_LOCK(?) AS released', [$lockName]);
            }
        });
    }

    public function requestDetails(Request $request, bool $includeInputs = false): array
    {
        $details = [
            'query_keys' => array_values(array_keys($request->query())),
        ];

        if ($includeInputs && $request->isMethodSafe()) {
            $details['input_keys'] = array_values(array_keys($request->query()));
        } elseif ($includeInputs) {
            $details['input_keys'] = array_values(array_keys($request->except([
                '_token', 'password', 'password_confirmation', 'token',
                'access_token', 'page_access_token', 'api_key', 'secret',
            ])));
        }

        return $details;
    }

    private function sanitizeDetails(array $details): array
    {
        $blocked = [
            'password', 'password_confirmation', 'token', 'access_token',
            'page_access_token', 'api_key', 'secret', 'session', 'cookie',
        ];

        $clean = [];
        foreach ($details as $key => $value) {
            $keyString = strtolower((string) $key);
            foreach ($blocked as $word) {
                if (str_contains($keyString, $word)) {
                    continue 2;
                }
            }

            if (is_scalar($value) || $value === null) {
                $clean[$key] = is_string($value) ? substr($value, 0, 500) : $value;
            } elseif (is_array($value)) {
                $clean[$key] = $this->sanitizeDetails($value);
            } else {
                $clean[$key] = substr((string) $value, 0, 500);
            }
        }

        return $clean;
    }
}
