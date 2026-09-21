<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Models\Agent;
use App\Models\Project;
use App\Models\Team;
use App\Models\TeamMember;
use App\Models\User;
use App\Services\SettingsService;
use App\Services\AccessService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class MyTeamController extends Controller
{
    public function __construct(private SettingsService $settings, private AccessService $access) {}

    /* ============================================================ */
    private function requireManager(): void
{
    $role = session('user_role');

    if (! in_array($role, ['admin', 'team_manager'], true)) {
        abort(403, 'Only team managers and admins can access this page.');
    }

    /*
    |--------------------------------------------------------------------------
    | Delegated Admins do not have My Team access
    |--------------------------------------------------------------------------
    | Delegated Admins are restricted to their delegated lead scope.
    | They must not access team/agent management, even by typing
    | /my-team directly.
    |
    | Super Admins are not affected because they do not have an
    | active delegated profile.
    */
    if ($role === 'admin' && $this->access->hasDelegatedProfile()) {
        abort(403, 'You do not have permission to access My Team.');
    }

    if ($role === 'admin' && ! $this->access->can('teams.view')) {
        abort(403, 'You do not have team access.');
    }
}

    private function isAdmin(): bool
    {
        return $this->access->isUnrestrictedAdmin();
    }

    /**
     * Team IDs visible to the current user.
     *   - Admin:      all active teams
     *   - TM:         only teams they manage
     */
    private function myTeamIds(): array
    {
        if ($this->isAdmin()) {
            return Team::where('is_active', true)->pluck('id')->all();
        }

        if ($this->access->hasDelegatedProfile()) {
            return $this->access->visibleTeamIds();
        }

        return Team::where('manager_user_id', session('user_id'))
            ->where('is_active', true)
            ->pluck('id')
            ->all();
    }

    /**
     * Agent IDs belonging to any of the visible teams.
     *   - Admin:      every agent who is a member of any active team
     *   - TM:         agents within their team(s)
     */
    private function myAgentIds(): array
    {
        $teamIds = $this->myTeamIds();
        if (empty($teamIds)) return [];

        return TeamMember::whereIn('team_id', $teamIds)
            ->where('is_active', true)
            ->pluck('agent_id')
            ->unique()
            ->all();
    }

    /* ============================================================
       INDEX
       ============================================================ */
        public function index(Request $request)
    {
        $this->requireManager();

        $teamIds  = $this->myTeamIds();
        $agentIds = $this->myAgentIds();

        $teams = Team::whereIn('id', $teamIds ?: [-1])
            ->with(['manager', 'members'])
            ->orderBy('name')
            ->paginate(25, ['*'], 'teams_page')
            ->appends($request->query());

        $agents = Agent::with(['user', 'teams'])
            ->whereIn('id', $agentIds ?: [-1])
            ->orderBy('id')
            ->paginate(25, ['*'], 'agents_page')
            ->appends($request->query());

        $projects = Project::active()
            ->visibleTo(session('user_id'), session('user_role'))
            ->orderBy('name')
            ->paginate(25, ['*'], 'projects_page')
            ->appends($request->query());

        return view('my-team', compact('teams', 'agents', 'projects', 'teamIds', 'agentIds'));
    }

    /* ============================================================
       SAVE AGENT
       ============================================================ */
    public function saveAgent(Request $request, ?int $id = null)
    {
        $this->requireManager();

        $teamIds  = $this->myTeamIds();
        $agentIds = $this->myAgentIds();

        if (! $this->access->can('agents.manage')) {
            abort(403, 'You do not have permission to manage agents.');
        }

        if (empty($teamIds)) {
            return back()->withErrors(['agent' => 'No active team available.']);
        }

        $agent = $id ? Agent::findOrFail($id) : null;

        // If updating, agent must be in my scope — unless I'm admin
        if ($agent && ! $this->isAdmin() && ! in_array($agent->id, $agentIds, true)) {
            abort(403, 'You can only edit agents in your team.');
        }

        $user = $agent?->user;

        $rules = [
            'name'            => 'required|string|max:100',
            'email'           => 'required|email|max:150|unique:users,email' . ($user ? ",{$user->id}" : ''),
            'phone'           => 'required|string|max:20',
            'whatsapp_personal_number' => 'nullable|string|max:20',
            'whatsapp_business_number' => 'nullable|string|max:20',
            'max_daily_leads' => 'nullable|integer|min:1|max:200',
            'status'          => 'required|in:active,inactive',
            'team_ids'        => $user ? 'nullable|array' : 'required|array|min:1',
            'team_ids.*'      => 'integer|exists:teams,id',
        ];

        if (! $user) {
            $rules['password'] = 'required|string|min:6';
        } else {
            $rules['password'] = 'nullable|string|min:6';
        }

        $validated = $request->validate($rules);

        DB::transaction(function () use ($validated, $agent, $user, $teamIds) {
            if (! $user) {
                $user = User::create([
                    'name'     => $validated['name'],
                    'email'    => $validated['email'],
                    'password' => Hash::make($validated['password']),
                    'role'     => UserRole::AGENT,
                ]);

                $agent = Agent::create([
                    'user_id'         => $user->id,
                    'phone'           => $validated['phone'],
                    'whatsapp_personal_number' => $validated['whatsapp_personal_number'] ?? null,
                    'whatsapp_business_number' => $validated['whatsapp_business_number'] ?? null,
                    'max_daily_leads' => $validated['max_daily_leads'] ?? 10,
                    'current_load'    => 0,
                    'status'          => $validated['status'],
                ]);

                // Assign to selected teams (only teams we manage / admin can see)
                $selected = array_intersect(
                    array_map('intval', $validated['team_ids'] ?? []),
                    $teamIds
                );
                foreach ($selected as $tid) {
                    TeamMember::updateOrCreate(
                        ['team_id' => $tid, 'agent_id' => $agent->id],
                        ['is_active' => true]
                    );
                }
            } else {
                // Update user
                $userData = [
                    'name'  => $validated['name'],
                    'email' => $validated['email'],
                ];
                if (! empty($validated['password'])) {
                    $userData['password'] = Hash::make($validated['password']);
                }
                $user->update($userData);

                // Update agent
                $agent->update([
                    'phone'           => $validated['phone'],
                    'whatsapp_personal_number' => $validated['whatsapp_personal_number'] ?? null,
                    'whatsapp_business_number' => $validated['whatsapp_business_number'] ?? null,
                    'max_daily_leads' => $validated['max_daily_leads'] ?? $agent->max_daily_leads,
                    'status'          => $validated['status'],
                ]);

                // Sync team memberships (only for the teams we have access to)
                if (! empty($validated['team_ids'])) {
                    $selected = array_intersect(
                        array_map('intval', $validated['team_ids']),
                        $teamIds
                    );

                    // Deactivate all memberships in our scope
                    TeamMember::where('agent_id', $agent->id)
                        ->whereIn('team_id', $teamIds)
                        ->update(['is_active' => false]);

                    // Reactivate selected
                    foreach ($selected as $tid) {
                        TeamMember::updateOrCreate(
                            ['team_id' => $tid, 'agent_id' => $agent->id],
                            ['is_active' => true]
                        );
                    }
                }
            }
        });

        return redirect('/my-team')->with(
            'success',
            $id ? '✅ Agent updated.' : '✅ Agent added.'
        );
    }

    /* ============================================================ */
    public function toggleAgent(int $id)
    {
        $this->requireManager();

        if (! $this->access->can('agents.manage') || (! $this->isAdmin() && ! in_array($id, $this->myAgentIds(), true))) {
            abort(403, 'You can only toggle agents in your team.');
        }

        $agent = Agent::findOrFail($id);
        $agent->update(['status' => $agent->status === 'active' ? 'inactive' : 'active']);

        return back()->with('success', '✅ Status toggled.');
    }

    /* ============================================================ */
    public function resetAgentLoad(int $id)
    {
        $this->requireManager();

        if (! $this->access->can('agents.manage') || (! $this->isAdmin() && ! in_array($id, $this->myAgentIds(), true))) {
            abort(403, 'You can only reset agents in your team.');
        }

        $agent = Agent::findOrFail($id);

        $activeCount = \App\Models\Lead::where('agent_id', $agent->id)
            ->whereNotIn('status', ['booking', 'lost'])
            ->count();

        $agent->update(['current_load' => $activeCount]);

        return back()->with('success', "✅ Load reset to {$activeCount}.");
    }

    /* ============================================================ */
    public function removeAgent(int $id)
    {
        $this->requireManager();

        $teamIds = $this->myTeamIds();
        if (empty($teamIds)) {
            return back()->with('error', 'No active team.');
        }

        // Admins can remove from any team in their view; TMs only from their own
        TeamMember::whereIn('team_id', $teamIds)
            ->where('agent_id', $id)
            ->update(['is_active' => false]);

        return back()->with('success', '🗑️ Agent removed from team(s).');
    }

    /* ============================================================ */
    public function syncProjectRouting(Request $request, int $id)
    {
        // Admin-only
        if (! $this->access->can('agents.manage')) {
            return response()->json([
                'success' => false,
                'message' => 'Only admins can assign projects to teams.',
            ], 403);
        }

        $this->requireManager();

        $project = Project::findOrFail($id);
        $teamIds  = $this->myTeamIds();
        $agentIds = $this->myAgentIds();

        $validated = $request->validate([
            'team_ids'    => 'nullable|array',
            'team_ids.*'  => 'integer|exists:teams,id',
            'agent_ids'   => 'nullable|array',
            'agent_ids.*' => 'integer|exists:agents,id',
        ]);

        $selectedTeamIds  = array_values(array_intersect(array_map('intval', $validated['team_ids'] ?? []), $teamIds));
        $selectedAgentIds = array_values(array_intersect(array_map('intval', $validated['agent_ids'] ?? []), $agentIds));

        DB::transaction(function () use ($project, $teamIds, $agentIds, $selectedTeamIds, $selectedAgentIds) {
            if (! empty($teamIds)) {
                DB::table('project_team')
                    ->where('project_id', $project->id)
                    ->whereIn('team_id', $teamIds)
                    ->update(['is_active' => 0]);

                foreach ($selectedTeamIds as $tid) {
                    DB::table('project_team')->updateOrInsert(
                        ['project_id' => $project->id, 'team_id' => $tid],
                        ['is_active' => 1, 'priority' => 0, 'updated_at' => now(), 'created_at' => now()]
                    );
                }
            }

            if (! empty($agentIds)) {
                DB::table('project_agent')
                    ->where('project_id', $project->id)
                    ->whereIn('agent_id', $agentIds)
                    ->update(['is_active' => 0]);

                foreach ($selectedAgentIds as $aid) {
                    DB::table('project_agent')->updateOrInsert(
                        ['project_id' => $project->id, 'agent_id' => $aid],
                        ['is_active' => 1, 'priority' => 0, 'updated_at' => now(), 'created_at' => now()]
                    );
                }
            }
        });

        return response()->json([
            'success' => true,
            'message' => '✅ Routing saved for ' . $project->name,
        ]);
    }
}