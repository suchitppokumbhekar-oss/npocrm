<?php

namespace App\Services;

use App\Models\Agent;
use App\Models\Team;
use App\Models\TeamMember;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class TeamService
{
    /* ============================================================
       CRUD
       ============================================================ */

    public function create(array $data, array $agentIds = []): Team
    {
        return DB::transaction(function () use ($data, $agentIds) {
            $team = Team::create([
                'name'            => $data['name'],
                'description'     => $data['description'] ?? null,
                'manager_user_id' => $data['manager_user_id'] ?? null,
                'is_active'       => $data['is_active'] ?? true,
            ]);

            $this->syncAgents($team, $agentIds);
            return $team;
        });
    }

    public function update(Team $team, array $data, array $agentIds = []): Team
    {
        return DB::transaction(function () use ($team, $data, $agentIds) {
            $team->update([
                'name'            => $data['name'],
                'description'     => $data['description'] ?? null,
                'manager_user_id' => $data['manager_user_id'] ?? null,
                'is_active'       => $data['is_active'] ?? true,
            ]);

            $this->syncAgents($team, $agentIds);
            return $team;
        });
    }

            /**
     * Sync the team's agents. Also auto-adds the manager as a member.
     * Preserves historical rows by toggling is_active (never hard-deletes).
     */
    public function syncAgents(Team $team, array $agentIds): void
    {
        $agentIds = array_unique(array_map('intval', $agentIds));

        // Always include the manager as a member — they must not be orphaned
        if ($team->manager_user_id) {
            $managerAgentId = \App\Models\Agent::where('user_id', $team->manager_user_id)->value('id');
            if ($managerAgentId && ! in_array($managerAgentId, $agentIds, true)) {
                $agentIds[] = $managerAgentId;
            }
        }

        // Also always include every admin (they belong to all teams by default)
        $adminAgentIds = \App\Models\Agent::whereHas('user', function ($q) {
            $q->where('role', 'admin');
        })->pluck('id')->all();

        foreach ($adminAgentIds as $aid) {
            if (! in_array($aid, $agentIds, true)) {
                $agentIds[] = $aid;
            }
        }

        // Mark all existing memberships as inactive
        \App\Models\TeamMember::where('team_id', $team->id)->update(['is_active' => false]);

        // Reactivate or insert the chosen ones
        foreach ($agentIds as $agentId) {
            \App\Models\TeamMember::updateOrCreate(
                ['team_id' => $team->id, 'agent_id' => $agentId],
                ['is_active' => true]
            );
        }
    }

    public function toggle(Team $team): Team
    {
        $team->update(['is_active' => ! $team->is_active]);
        return $team;
    }

    public function destroy(Team $team): void
    {
        $team->delete();
    }

    /* ============================================================
       SCOPING HELPERS
       ============================================================ */

    /** Agent IDs assigned to the given manager's team(s) */
        public function agentIdsForManager(int $managerUserId): array
{
    $teamIds = Team::where('manager_user_id', $managerUserId)
        ->where('is_active', true)
        ->pluck('id')
        ->all();

    $agentIds = [];

    if (! empty($teamIds)) {
        $agentIds = TeamMember::whereIn('team_id', $teamIds)
            ->where('is_active', true)
            ->pluck('agent_id')
            ->unique()
            ->values()
            ->all();
    }

    // Always include the manager's own agent record
    $selfAgentId = Agent::where('user_id', $managerUserId)->value('id');
    if ($selfAgentId && ! in_array((int) $selfAgentId, $agentIds, true)) {
        $agentIds[] = (int) $selfAgentId;
    }

    return $agentIds;
}

    /**
     * Return the agents this user can assign a lead to, based on role:
     *   - Admin:        every active agent (across teams)
     *   - Team Manager: agents in their team(s) — plus themselves (if they have an Agent record)
     *   - Agent:        only themselves
     */
    public function assignableAgentsFor(User $user): Collection
    {
        if ($user->isAdmin()) {
            return Agent::with(['user', 'teams'])
                ->where('status', 'active')
                ->orderBy('id')
                ->get();
        }

        if ($user->isTeamManager()) {
            $agentIds = $this->agentIdsForManager($user->id);

            // Ensure the TM's own Agent record is included (they may not be a team member)
            $selfAgent = Agent::where('user_id', $user->id)->first();
            if ($selfAgent && ! in_array($selfAgent->id, $agentIds, true)) {
                $agentIds[] = $selfAgent->id;
            }

            if (empty($agentIds)) {
                return collect();
            }

            return Agent::with(['user', 'teams'])
                ->whereIn('id', $agentIds)
                ->where('status', 'active')
                ->orderBy('id')
                ->get();
        }

        // Agent: only themselves
        return Agent::with(['user'])
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->get();
    }
}