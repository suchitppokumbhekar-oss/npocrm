<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\Request;

class AuditLogController extends Controller
{
    private function requireSuperAdmin(): void
    {
        if (session('user_role') !== 'admin' || ! app(\App\Services\SuperAdminService::class)->isSuperAdmin()) {
            abort(403, 'Super Admin only.');
        }
    }

    public function index(Request $request)
    {
        $this->requireSuperAdmin();

        $query = AuditLog::query()->orderByDesc('id');
        if ($request->filled('user_id')) $query->where('actor_user_id', (int) $request->input('user_id'));
        if ($request->filled('category')) $query->where('event_category', $request->input('category'));
        if ($request->filled('action')) $query->where('action', 'like', '%' . trim($request->input('action')) . '%');
        if ($request->filled('from')) $query->where('created_at', '>=', $request->input('from') . ' 00:00:00');
        if ($request->filled('to')) $query->where('created_at', '<=', $request->input('to') . ' 23:59:59');

        $logs = $query->paginate(50)->withQueryString();
        $users = User::orderBy('name')->get(['id', 'name', 'email', 'role']);
        $categories = AuditLog::query()->select('event_category')->whereNotNull('event_category')->distinct()->orderBy('event_category')->pluck('event_category');
        $integrity = $this->verifyChain();

        return view('admin/audit-logs', compact('logs', 'users', 'categories', 'integrity'));
    }

    public function verify(Request $request)
    {
        $this->requireSuperAdmin();
        $integrity = $this->verifyChain();

        return response()->json([
            'ok' => $integrity['ok'],
            'checked' => $integrity['checked'],
            'first_error' => $integrity['first_error'],
            'verified_at' => now()->format('Y-m-d H:i:s'),
        ], $integrity['ok'] ? 200 : 409);
    }

    public function initialize(Request $request)
    {
        $this->requireSuperAdmin();

        try {
            $count = app(\App\Services\AuditLogService::class)->initializeLegacyChain();
        } catch (\Throwable $e) {
            report($e);
            abort(500, 'Audit chain initialization failed.');
        }

        return redirect()->route('admin.audit-logs')->with('status',
            $count > 0 ? "Initialized integrity metadata for {$count} legacy audit event(s)." : 'Audit chain was already initialized.'
        );
    }

    private function verifyChain(): array
    {
        $previous = AuditLog::GENESIS_HASH;
        $checked = 0;
        $firstError = null;

        AuditLog::query()->orderBy('id')->chunkById(500, function ($logs) use (&$previous, &$checked, &$firstError): void {
            foreach ($logs as $log) {
                $checked++;
                if ($firstError === null && $log->previous_hash !== $previous) {
                    $firstError = "Event #{$log->id}: previous hash does not match the preceding event.";
                }
                if ($firstError === null && ! $log->hashMatches()) {
                    $firstError = "Event #{$log->id}: stored hash does not match the recorded event.";
                }
                $previous = $log->hash;
            }
        });

        return ['ok' => $firstError === null, 'checked' => $checked, 'first_error' => $firstError];
    }
}
