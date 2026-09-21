<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! $response instanceof Response) {
            return $response;
        }

        /* ---------- Hardening headers ---------- */
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');

        // geolocation=(self) allows our own origin to ask for location
        // (attendance check-in). Camera and microphone stay disabled.
        $response->headers->set(
            'Permissions-Policy',
            'geolocation=(self), microphone=(), camera=()'
        );

        /* ---------- Auth pages — hard no-store ---------- */
        // Login / reset pages must never be restored from bfcache:
        // a stale login form with an expired CSRF token is a bad UX.
        // Skipping bfcache here also makes the back button exit the
        // login flow cleanly instead of re-showing the dashboard.
        if ($request->is('login')
            || $request->is('forgot-password')
            || $request->is('reset-password*')
            || $request->is('logout')) {
            $response->headers->set(
                'Cache-Control',
                'no-store, no-cache, must-revalidate, max-age=0, private'
            );
            $response->headers->set('Pragma', 'no-cache');
            $response->headers->set('Expires', 'Sat, 01 Jan 2000 00:00:00 GMT');
        }

        /* ---------- Authenticated pages — allow bfcache ---------- */
        // no-cache allows bfcache but forces revalidation on normal
        // network fetches. Combined with the pageshow handler in
        // app.js, this keeps back navigation instant AND session-safe:
        // if the user logged out or the session expired, the handler
        // reloads the page and the server redirects to /login.
        elseif (session('user_id')) {
            $response->headers->set(
                'Cache-Control',
                'no-cache, must-revalidate, max-age=0, private'
            );
            $response->headers->set('Pragma', 'no-cache');
            // No Expires header — leave it out so bfcache can still store.
        }

        return $response;
    }
}