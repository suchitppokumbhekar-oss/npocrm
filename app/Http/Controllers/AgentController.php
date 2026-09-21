<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Models\Agent;
use App\Models\Team;
use App\Models\TeamMember;
use App\Models\User;
use App\Services\TeamService;
use App\Services\AccessService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class AgentController extends Controller
{
    public function __construct(private TeamService $teams, private AccessService $access) {}

    /* ============================================================
       GUARD
       ============================================================ */
    private function requireAdmin(): void
    {
        if (! $this->access->can('agents.manage')) {
            abort(403, 'Admin only.');
        }
    }

    /* ============================================================
       CREATE / UPDATE
       ============================================================ */
    public function save(Request $request, ?int $id = null)
    {
        $this->requireAdmin();

        $agent = $id ? Agent::findOrFail($id) : null;
        $user  = $agent?->user;

        // ---- Validation ----
        $rules = [
            'name'                  => 'required|string|max:100',
            'email'                 => 'required|email|max:150|unique:users,email' . ($user ? ",{$user->id}" : ''),
            'phone'                 => 'required|string|max:20',
            'max_daily_leads'       => 'nullable|integer|min:1|max:200',
            'status'                => 'required|in:active,inactive',
            // New agents MUST belong to at least one team
            'team_ids'              => $user ? 'nullable|array' : 'required|array|min:1',
            'team_ids.*'            => 'integer|exists:teams,id',
            'is_telecaller'         => 'nullable|boolean',
            'is_on_payroll'         => 'nullable|boolean',
        ];

        if (! $user) {
            $rules['password'] = 'required|string|min:6';
        } else {
            $rules['password'] = 'nullable|string|min:6';
        }

        $validated = $request->validate($rules);

        // ---- Atomic write ----
        DB::transaction(function () use ($validated, $agent, $user, $request) {

            if (! $user) {
                // 1a. Create user
                $user = User::create([
                    'name'          => $validated['name'],
                    'email'         => $validated['email'],
                    'password'      => Hash::make($validated['password']),
                    'role'          => UserRole::AGENT,
                    'is_telecaller' => $request->boolean('is_telecaller'),
                    'is_on_payroll' => $request->boolean('is_on_payroll'),
                ]);

                // 1b. Create agent record
                $agent = Agent::create([
                    'user_id'         => $user->id,
                    'phone'           => $validated['phone'],
                    'whatsapp_personal_number' => $validated['whatsapp_personal_number'] ?? null,
                    'whatsapp_business_number' => $validated['whatsapp_business_number'] ?? null,
                    'max_daily_leads' => $validated['max_daily_leads'] ?? 10,
                    'current_load'    => 0,
                    'status'          => $validated['status'],
                ]);
            } else {
                // 2a. Update user
                $userData = [
                    'name'          => $validated['name'],
                    'email'         => $validated['email'],
                    'is_telecaller' => $request->boolean('is_telecaller'),
                    'is_on_payroll' => $request->boolean('is_on_payroll'),
                ];
                if (! empty($validated['password'])) {
                    $userData['password'] = Hash::make($validated['password']);
                }
                $user->update($userData);

                // 2b. Update agent
                $agent->update([
                    'phone'           => $validated['phone'],
                    'whatsapp_personal_number' => $validated['whatsapp_personal_number'] ?? null,
                    'whatsapp_business_number' => $validated['whatsapp_business_number'] ?? null,
                    'max_daily_leads' => $validated['max_daily_leads'] ?? 10,
                    'status'          => $validated['status'],
                ]);
            }

            // 3. Sync team memberships
            $teamIds = $validated['team_ids'] ?? [];
            $this->syncTeams($agent, $teamIds);
        });

        $msg = $id ? '✅ Agent updated.' : '✅ Agent created.';

        return redirect('/settings?tab=agents')->with('success', $msg);
    }

    /* ============================================================
       TEAM MEMBERSHIP SYNC
       ============================================================ */
    private function syncTeams(Agent $agent, array $teamIds): void
    {
        TeamMember::where('agent_id', $agent->id)->update(['is_active' => false]);

        foreach (array_unique(array_map('intval', $teamIds)) as $teamId) {
            TeamMember::updateOrCreate(
                ['team_id' => $teamId, 'agent_id' => $agent->id],
                ['is_active' => true]
            );
        }
    }

    /* ============================================================
       TOGGLE STATUS
       ============================================================ */
    public function toggle(int $id)
    {
        $this->requireAdmin();

        $agent = Agent::findOrFail($id);

        $agent->update([
            'status' => $agent->status === 'active' ? 'inactive' : 'active',
        ]);

        return back()->with('success', '✅ Status toggled.');
    }

    /* ============================================================
       RESET LOAD COUNTER
       ============================================================ */
    public function resetLoad(int $id)
    {
        $this->requireAdmin();

        $agent = Agent::findOrFail($id);

        $activeCount = \App\Models\Lead::where('agent_id', $agent->id)
            ->whereNotIn('status', ['booking', 'lost'])
            ->count();

        $agent->update(['current_load' => $activeCount]);

        return back()->with('success', "✅ Load reset to {$activeCount}.");
    }
}