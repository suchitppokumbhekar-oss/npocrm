<?php

namespace App\Http\Middleware;

use App\Models\Agent;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Central authentication boundary for the CRM's custom session auth.
 *
 * The application intentionally uses a lightweight session contract instead
 * of Laravel's Auth guard. This middleware makes that contract explicit so a
 * newly-added route cannot accidentally become public just because its
 * controller forgot a session check.
 */
class RequireAuthenticatedSession
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($this->isPublic($request)) {
            return $next($request);
        }

        $userId = (int) $request->session()->get('user_id', 0);
        if ($userId < 1) {
            return $this->unauthenticated($request);
        }

        $user = User::find($userId);
        if (! $user) {
            return $this->invalidate($request, $request->session()->get('user_id'));
        }

        $currentRole = $user->role instanceof \BackedEnum
            ? $user->role->value
            : (string) $user->role;

        // A role change must invalidate an old privileged session immediately.
        if ((string) $request->session()->get('user_role') !== $currentRole) {
            return $this->invalidate($request, $userId);
        }

        // Agent/team-manager access is also tied to an active agent record.
        // Admin users do not require an Agent row.
        if (in_array($currentRole, ['agent', 'team_manager'], true)) {
            $agentStatus = Agent::where('user_id', $userId)->value('status');
            if ($agentStatus !== 'active') {
                return $this->invalidate($request, $userId);
            }
        }

        return $next($request);
    }

    private function isPublic(Request $request): bool
    {
        return $request->is('login')
            || $request->is('logout')
            || $request->is('forgot-password')
            || $request->is('reset-password/*')
            || $request->is('api/leads')
            || $request->is('webhooks/meta/lead')
            || $request->is('api/session-check');
    }

    private function unauthenticated(Request $request): Response
    {
        if ($request->is('api/*') || $request->expectsJson()) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        return redirect('/login');
    }

    private function invalidate(Request $request, mixed $userId): Response
    {
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        if ($request->is('api/*') || $request->expectsJson()) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        return redirect('/login')->withErrors([
            'email' => 'Your session is no longer valid. Please sign in again.',
        ]);
    }
}
