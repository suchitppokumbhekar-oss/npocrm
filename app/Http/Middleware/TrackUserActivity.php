<?php

namespace App\Http\Middleware;

use App\Models\UserActivitySession;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class TrackUserActivity
{
    // Gap > 5 minutes = considered idle, doesn't count toward active time
    const IDLE_GAP_SECONDS = 300;

    public function handle(Request $request, Closure $next)
    {
        $response = $next($request);

        // Only track authenticated users, skip static assets and API polling
        $userId = session('user_id');
        if (! $userId) return $response;

        $path = $request->path();
        if (
            str_starts_with($path, 'assets/')
            || str_starts_with($path, 'build/')
            || str_starts_with($path, 'notifications/unread-count')  // polling — don't count
            || str_starts_with($path, 'api/session-check')
        ) {
            return $response;
        }

        try {
            $today = now()->toDateString();
            $now   = now();

            $row = UserActivitySession::where('user_id', $userId)
                ->where('session_date', $today)
                ->first();

            if (! $row) {
                UserActivitySession::create([
                    'user_id'              => $userId,
                    'session_date'         => $today,
                    'started_at'           => $now,
                    'last_activity_at'     => $now,
                    'total_active_seconds' => 0,
                    'page_views'           => 1,
                    'last_url'             => substr($request->fullUrl(), 0, 500),
                ]);
                return $response;
            }

            // Accumulate gap only if it's a real active transition (not idle)
            $gap = $row->last_activity_at ? $row->last_activity_at->diffInSeconds($now) : 0;
            $add = ($gap > 0 && $gap < self::IDLE_GAP_SECONDS) ? $gap : 0;

            $row->update([
                'last_activity_at'     => $now,
                'total_active_seconds' => $row->total_active_seconds + $add,
                'page_views'           => $row->page_views + 1,
                'last_url'             => substr($request->fullUrl(), 0, 500),
            ]);
        } catch (\Throwable $e) {
            Log::warning('TrackUserActivity failed', ['error' => $e->getMessage()]);
        }

        return $response;
    }
}