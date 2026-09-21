<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Models\Agent;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class ManagerController extends Controller
{
    private function requireAdmin(): void
    {
        if (session('user_role') !== 'admin') {
            abort(403, 'Admin only.');
        }
    }

    /**
     * Create or update a team manager.
     * Team managers ALSO get an Agent record so they can be assigned
     * to projects and receive leads — they handle projects too.
     */
    public function save(Request $request, ?int $id = null)
    {
        $this->requireAdmin();

        $user = $id ? User::findOrFail($id) : null;

        $rules = [
            'name'       => 'required|string|max:100',
            'email'      => 'required|email|max:150|unique:users,email' . ($user ? ",{$user->id}" : ''),
            'phone'      => 'nullable|string|max:20',
            'is_active'  => 'nullable|boolean',
            'password'   => $user ? 'nullable|string|min:6' : 'required|string|min:6',
        ];

        $validated = $request->validate($rules);

        if (! $user) {
            $user = User::create([
                'name'     => $validated['name'],
                'email'    => $validated['email'],
                'password' => Hash::make($validated['password']),
                'role'     => UserRole::TEAM_MANAGER,
            ]);
            $msg = '✅ Team Manager created.';
        } else {
            $data = [
                'name'  => $validated['name'],
                'email' => $validated['email'],
                'role'  => UserRole::TEAM_MANAGER,
            ];
            if (! empty($validated['password'])) {
                $data['password'] = Hash::make($validated['password']);
            }
            $user->update($data);
            $msg = '✅ Team Manager updated.';
        }

        // Ensure this manager also has an Agent record so they show
        // up in project routing and can receive leads.
        $this->ensureAgentRecord($user, $validated);

        return redirect('/settings?tab=managers')->with('success', $msg);
    }

    /**
     * Ensure the given user has an active Agent record.
     * Idempotent — safe to call repeatedly.
     */
    private function ensureAgentRecord(User $user, array $validated): void
    {
        $agent = Agent::where('user_id', $user->id)->first();

        if ($agent) {
            $updates = [];

            if ($agent->status !== 'active') {
                $updates['status'] = 'active';
            }

            if (empty($agent->phone) && ! empty($validated['phone'])) {
                $updates['phone'] = $validated['phone'];
            }

            if ($updates) {
                $agent->update($updates);
            }

            return;
        }

        Agent::create([
            'user_id'         => $user->id,
            'phone'           => $validated['phone'] ?? null,
            'max_daily_leads' => 10,
            'current_load'    => 0,
            'status'          => 'active',
        ]);
    }

    /**
     * Toggle the manager's linked agent on/off.
     */
    public function toggle(int $id)
    {
        $this->requireAdmin();

        $user  = User::findOrFail($id);
        $agent = Agent::where('user_id', $user->id)->first();

        if (! $agent) {
            return back()->with('error', 'This manager has no linked agent record.');
        }

        $agent->update([
            'status' => $agent->status === 'active' ? 'inactive' : 'active',
        ]);

        return back()->with('success', '✅ Status toggled.');
    }
}