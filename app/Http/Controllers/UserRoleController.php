<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Models\Agent;
use App\Models\Team;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class UserRoleController extends Controller
{
    /**
     * Change a user's role between 'agent' and 'team_manager'.
     * Admin-only.
     *
     * Rules:
     *   Agent → Team Manager: role updated. Team assignment is done later
     *                          via Settings → Teams.
     *   TM → Agent:            role updated AND any teams they manage
     *                          are released (manager_user_id = null).
     */
    public function change(Request $request, int $id)
    {
        if (session('user_role') !== 'admin') {
            return back()->with('error', 'Only admins can change user roles.');
        }

        $user = User::findOrFail($id);

        $validated = $request->validate([
            'role' => 'required|in:agent,team_manager',
        ]);

        $newRole = $validated['role'];
        $oldRole = $user->role instanceof UserRole ? $user->role->value : (string) $user->role;

        if ($oldRole === $newRole) {
            return back()->with('info', 'Role unchanged.');
        }

        // Must have an agent record to switch roles (both roles use it)
        $agent = Agent::where('user_id', $user->id)->first();
        if (! $agent) {
            return back()->with('error', 'This user has no agent record. Create one in Agents first.');
        }

        DB::transaction(function () use ($user, $oldRole, $newRole) {
            // Update the role
            $user->update(['role' => $newRole]);

            // If stepping down from team manager → release their teams
            if ($oldRole === 'team_manager' && $newRole === 'agent') {
                Team::where('manager_user_id', $user->id)
                    ->update(['manager_user_id' => null]);
            }
        });

        app(\App\Services\AuditLogService::class)->record(
            'user_admin',
            'Changed user role',
            $request,
            ['old_role' => $oldRole, 'new_role' => $newRole],
            'User',
            $user->id
        );

        $label = $newRole === 'team_manager' ? '🎯 Team Manager' : '👤 Agent';

        $extra = '';
        if ($oldRole === 'team_manager' && $newRole === 'agent') {
            $extra = ' Any teams they managed are now unassigned — assign a new manager in Settings → Teams.';
        } elseif ($oldRole === 'agent' && $newRole === 'team_manager') {
            $extra = ' Assign them a team in Settings → Teams to activate their team dashboard.';
        }

        return back()->with('success', "✅ Role changed to {$label}.{$extra}");
    }
}