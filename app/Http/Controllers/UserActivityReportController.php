<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\UserActivitySession;
use Illuminate\Http\Request;

class UserActivityReportController extends Controller
{
    public function index(Request $request)
    {
        if (session('user_role') !== 'admin') {
            abort(403, 'Admin only.');
        }

        $date = $request->input('date', now()->toDateString());

        // Sessions for the picked day
        $sessions = UserActivitySession::with('user')
            ->where('session_date', $date)
            ->get()
            ->keyBy('user_id');

        // All users so we can show even those with zero activity
        $users = User::orderBy('name')->get();

        // Weekly totals (last 7 days including picked date)
        $weekStart = \Carbon\Carbon::parse($date)->subDays(6);
        $weekly = UserActivitySession::selectRaw('user_id, SUM(total_active_seconds) as total_secs, SUM(page_views) as total_views, COUNT(*) as active_days')
            ->whereBetween('session_date', [$weekStart->toDateString(), $date])
            ->groupBy('user_id')
            ->get()
            ->keyBy('user_id');

        return view('reports.user-activity', compact('users', 'sessions', 'weekly', 'date', 'weekStart'));
    }
}