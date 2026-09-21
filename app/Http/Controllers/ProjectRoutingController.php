<?php

namespace App\Http\Controllers;

use App\Models\Agent;
use App\Models\Project;
use App\Models\Team;
use App\Services\SettingsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProjectRoutingController extends Controller
{
    private function requireAdmin(): void
    {
        if (session('user_role') !== 'admin') {
            abort(403);
        }
    }

    public function addTeam(Request $request, int $id)
    {
        $this->requireAdmin();

        $validated = $request->validate([
            'team_id'  => 'required|integer|exists:teams,id',
            'priority' => 'nullable|integer|min:0|max:999',
        ]);

        $project = Project::findOrFail($id);
        $now = now();

        DB::table('project_team')->updateOrInsert(
            ['project_id' => $project->id, 'team_id' => $validated['team_id']],
            [
                'priority'   => $validated['priority'] ?? 0,
                'is_active'  => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]
        );

        return back()->with('success', '✅ Team added to project routing.');
    }

    public function addAgent(Request $request, int $id)
    {
        $this->requireAdmin();

        $validated = $request->validate([
            'agent_id' => 'required|integer|exists:agents,id',
            'priority' => 'nullable|integer|min:0|max:999',
        ]);

        $project = Project::findOrFail($id);
        $now = now();

        DB::table('project_agent')->updateOrInsert(
            ['project_id' => $project->id, 'agent_id' => $validated['agent_id']],
            [
                'priority'   => $validated['priority'] ?? 0,
                'is_active'  => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]
        );

        return back()->with('success', '✅ Agent added to project routing.');
    }

    public function removeTeam(Request $request, int $id)
    {
        $this->requireAdmin();
        $request->validate(['team_id' => 'required|integer']);

        DB::table('project_team')
            ->where('project_id', $id)
            ->where('team_id', $request->team_id)
            ->update(['is_active' => 0, 'updated_at' => now()]);

        return back()->with('success', '🗑️ Team removed from routing.');
    }

    public function removeAgent(Request $request, int $id)
    {
        $this->requireAdmin();
        $request->validate(['agent_id' => 'required|integer']);

        DB::table('project_agent')
            ->where('project_id', $id)
            ->where('agent_id', $request->agent_id)
            ->update(['is_active' => 0, 'updated_at' => now()]);

        return back()->with('success', '🗑️ Agent removed from routing.');
    }

    public function saveStrict(Request $request)
    {
        $this->requireAdmin();

        app(SettingsService::class)
            ->set('project_routing_strict', $request->boolean('strict') ? '1' : '0');

        return back()->with('success', '✅ Strict routing mode ' .
            ($request->boolean('strict') ? 'enabled' : 'disabled') . '.');
    }
    public function teamMembers(int $teamId)
{
    $this->requireAdmin();

    $members = DB::table('team_members')
        ->join('agents', 'agents.id', '=', 'team_members.agent_id')
        ->join('users',  'users.id',  '=', 'agents.user_id')
        ->where('team_members.team_id', $teamId)
        ->where('team_members.is_active', 1)
        ->where('agents.status', 'active')
        ->where('users.role', '!=', 'admin')
        ->orderBy('users.name')
        ->get(['agents.id', 'users.name']);

    return response()->json([
        'success' => true,
        'members' => $members,
    ]);
}
    public function sync(Request $request, int $id)
    {
        $this->requireAdmin();

        $validated = $request->validate([
            'agent_ids'   => 'nullable|array',
            'agent_ids.*' => 'integer|exists:agents,id',
            'team_ids'    => 'nullable|array',
            'team_ids.*'  => 'integer|exists:teams,id',
        ]);

        $project = Project::findOrFail($id);

        $agentIds = array_unique(array_map('intval', $validated['agent_ids'] ?? []));
        $teamIds  = array_unique(array_map('intval', $validated['team_ids']  ?? []));

        DB::transaction(function () use ($project, $agentIds, $teamIds) {
            $now = now();

            DB::table('project_agent')
                ->where('project_id', $project->id)
                ->update(['is_active' => 0, 'updated_at' => $now]);

            foreach ($agentIds as $aid) {
                DB::table('project_agent')->updateOrInsert(
                    ['project_id' => $project->id, 'agent_id' => $aid],
                    ['is_active' => 1, 'priority' => 0, 'created_at' => $now, 'updated_at' => $now]
                );
            }

            DB::table('project_team')
                ->where('project_id', $project->id)
                ->update(['is_active' => 0, 'updated_at' => $now]);

            foreach ($teamIds as $tid) {
                DB::table('project_team')->updateOrInsert(
                    ['project_id' => $project->id, 'team_id' => $tid],
                    ['is_active' => 1, 'priority' => 0, 'created_at' => $now, 'updated_at' => $now]
                );
            }
        });

        return response()->json([
            'success' => true,
            'message' => '✅ Routing saved for ' . $project->name,
        ]);
    }
/**
 * Bulk assign a specific team member as the direct agent for all projects
 * routed to a given team that currently have no direct agent.
 */
public function bulkAssignAgent(Request $request)
{
    $this->requireAdmin();

    $data = $request->validate([
        'team_id'  => 'required|integer|exists:teams,id',
        'agent_id' => 'required|integer|exists:agents,id',
    ]);

    $teamId  = $data['team_id'];
    $agentId = $data['agent_id'];

    // Find projects routed to this team that have NO direct agent yet
    $projectIds = DB::table('project_team as pt')
        ->join('projects as p', 'p.id', '=', 'pt.project_id')
        ->where('pt.team_id', $teamId)
        ->where('pt.is_active', 1)
        ->where('p.status', 'active')
        ->whereNotExists(function ($q) {
            $q->select(DB::raw(1))
              ->from('project_agent as pa')
              ->whereColumn('pa.project_id', 'pt.project_id')
              ->where('pa.is_active', 1);
        })
        ->pluck('p.id')
        ->all();

    if (empty($projectIds)) {
        return response()->json([
            'success' => false,
            'message' => 'No unassigned projects found for this team.',
        ], 422);
    }

    $now = now();
    $added = 0;

    DB::transaction(function () use ($projectIds, $agentId, $now, &$added) {
        foreach ($projectIds as $projectId) {
            DB::table('project_agent')->updateOrInsert(
                ['project_id' => $projectId, 'agent_id' => $agentId],
                [
                    'is_active'  => 1,
                    'priority'   => 0,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]
            );
            $added++;
        }
    });

    return response()->json([
        'success'   => true,
        'message'   => "Assigned agent to {$added} project(s).",
        'projects'  => count($projectIds),
        'assigned'  => $added,
    ]);
}
public function bulkAssign(Request $request)
{
    $this->requireAdmin();

    $data = $request->validate([
        'team_id'         => 'required|integer|exists:teams,id',
        'agent_id'        => 'nullable|integer|exists:agents,id',
        'action'          => 'required|in:assign,remove',
        'project_ids'     => 'nullable|array',
        'project_ids.*'   => 'integer|exists:projects,id',
        'use_filter'      => 'nullable|boolean',
        'filter_q'        => 'nullable|string|max:255',
        'filter_location' => 'nullable|string|max:255',
        'filter_routed'   => 'nullable|in:all,routed,unrouted',
        'filter_agents'   => 'nullable|in:all,has,none',
        'filter_teams'    => 'nullable|in:all,has,none',
    ]);

    $teamId  = (int) $data['team_id'];
    $agentId = ! empty($data['agent_id']) ? (int) $data['agent_id'] : null;
    $action  = $data['action'];

    // Resolve target projects
    if (! empty($data['project_ids'])) {
        $projectIds = $data['project_ids'];
    } elseif (! empty($data['use_filter'])) {
        $filters = [
            'q'        => $data['filter_q']        ?? '',
            'location' => $data['filter_location'] ?? '',
            'routed'   => $data['filter_routed']   ?? 'all',
            'agents'   => $data['filter_agents']   ?? 'all',
            'teams'    => $data['filter_teams']    ?? 'all',
        ];
        $projectIds = $this->applyRoutingFilters(Project::query(), $filters)->pluck('id')->all();
    } else {
        return response()->json(['success' => false, 'message' => 'Select projects or use matching filter.'], 422);
    }

    if (empty($projectIds)) {
        return response()->json(['success' => false, 'message' => 'No projects matched.'], 422);
    }

    // If a member was picked, verify they're actually on this team
    if ($agentId !== null) {
        $ok = DB::table('team_members')
            ->where('team_id', $teamId)
            ->where('agent_id', $agentId)
            ->where('is_active', 1)
            ->exists();

        if (! $ok) {
            return response()->json([
                'success' => false,
                'message' => 'That agent is not an active member of the selected team.',
            ], 422);
        }
    }

    $now = now();
    $teamCount   = 0;
    $agentCount  = 0;

    DB::transaction(function () use ($projectIds, $teamId, $agentId, $action, $now, &$teamCount, &$agentCount) {
        foreach ($projectIds as $pid) {

            // ---------------- TEAM ROUTING ----------------
            if ($action === 'assign') {
                DB::table('project_team')->updateOrInsert(
                    ['project_id' => $pid, 'team_id' => $teamId],
                    ['is_active' => 1, 'priority' => 0, 'created_at' => $now, 'updated_at' => $now]
                );
                $teamCount++;
            } else {
                DB::table('project_team')
                    ->where('project_id', $pid)
                    ->where('team_id', $teamId)
                    ->update(['is_active' => 0, 'updated_at' => $now]);
            }

            // ---------------- DIRECT AGENT (only if a member was picked) ----------------
            if ($agentId !== null) {
                if ($action === 'assign') {
                    DB::table('project_agent')->updateOrInsert(
                        ['project_id' => $pid, 'agent_id' => $agentId],
                        ['is_active' => 1, 'priority' => 0, 'created_at' => $now, 'updated_at' => $now]
                    );
                    $agentCount++;
                } else {
                    DB::table('project_agent')
                        ->where('project_id', $pid)
                        ->where('agent_id', $agentId)
                        ->update(['is_active' => 0, 'updated_at' => $now]);
                }
            }
        }
    });

    $msg = $action === 'assign'
        ? "Assigned to {$teamCount} project(s)"
        : "Removed from " . count($projectIds) . " project(s)";
    if ($agentId !== null && $action === 'assign') {
        $msg .= ", {$agentCount} direct agent link(s) created";
    }

    return response()->json([
        'success'  => true,
        'message'  => $msg . '.',
        'projects' => count($projectIds),
        'teams'    => $teamCount,
        'agents'   => $agentCount,
    ]);
}
    /* ============================================================
       BULK ASSIGN TEAM
       ------------------------------------------------------------
       Assign (or remove) ONE team to MANY projects at once.
       Called from Settings → Routing → Bulk Assign to Team.
       ============================================================ */
    public function bulkAssignTeam(Request $request)
    {
        $this->requireAdmin();

        $data = $request->validate([
            'project_ids'     => 'nullable|array',
            'project_ids.*'   => 'integer|exists:projects,id',
            'use_filter'      => 'nullable|boolean',

            'filter_q'        => 'nullable|string|max:255',
            'filter_location' => 'nullable|string|max:255',
            'filter_routed'   => 'nullable|in:all,routed,unrouted',
            'filter_agents'   => 'nullable|in:all,has,none',
            'filter_teams'    => 'nullable|in:all,has,none',

            'team_id'         => 'required|integer|exists:teams,id',
            'action'          => 'required|in:assign,remove',
        ]);

        /* Resolve target project IDs */
        if (!empty($data['project_ids'])) {
            $projectIds = $data['project_ids'];
        } elseif (!empty($data['use_filter'])) {
            $filters = [
                'q'        => $data['filter_q']        ?? '',
                'location' => $data['filter_location'] ?? '',
                'routed'   => $data['filter_routed']   ?? 'all',
                'agents'   => $data['filter_agents']   ?? 'all',
                'teams'    => $data['filter_teams']    ?? 'all',
            ];
            $projectIds = $this->applyRoutingFilters(Project::query(), $filters)->pluck('id')->all();
        } else {
            return response()->json([
                'success' => false,
                'message' => 'Select projects, or switch scope to "matching filter".',
            ], 422);
        }

        if (empty($projectIds)) {
            return response()->json([
                'success' => false,
                'message' => 'No projects matched.',
            ], 422);
        }

        $teamId = (int) $data['team_id'];
        $action = $data['action'];
        $now    = now();
        $count  = 0;

        DB::transaction(function () use ($projectIds, $teamId, $action, $now, &$count) {
            foreach ($projectIds as $pid) {
                if ($action === 'assign') {
                    DB::table('project_team')->updateOrInsert(
                        ['project_id' => $pid, 'team_id' => $teamId],
                        ['is_active' => 1, 'priority' => 0, 'created_at' => $now, 'updated_at' => $now]
                    );
                    $count++;
                } else {
                    $count += DB::table('project_team')
                        ->where('project_id', $pid)
                        ->where('team_id', $teamId)
                        ->update(['is_active' => 0, 'updated_at' => $now]);
                }
            }
        });

        $team = Team::find($teamId);

        return response()->json([
            'success' => true,
            'message' => $action === 'assign'
                ? "Team \"{$team->name}\" assigned to {$count} project(s)."
                : "Team \"{$team->name}\" removed from {$count} project(s).",
        ]);
    }

    /* ============================================================
       FILTER HELPER — must mirror SettingsController@applyRoutingFilters
       ============================================================ */
    private function applyRoutingFilters($query, array $f)
    {
        $q      = trim((string) ($f['q']        ?? ''));
        $loc    = trim((string) ($f['location'] ?? ''));
        $routed = $f['routed'] ?? 'all';
        $agents = $f['agents'] ?? 'all';
        $teams  = $f['teams']  ?? 'all';

        if ($q !== '') {
            $query->where(function ($qq) use ($q) {
                $qq->where('name', 'like', "%{$q}%")
                   ->orWhere('location', 'like', "%{$q}%");
            });
        }
        if ($loc !== '') { $query->where('location', $loc); }
        if ($routed === 'routed') {
            $query->where(function ($qq) {
                $qq->whereHas('activeDirectAgents')->orWhereHas('activeTeams');
            });
        } elseif ($routed === 'unrouted') {
            $query->whereDoesntHave('activeDirectAgents')->whereDoesntHave('activeTeams');
        }
        if ($agents === 'has')  { $query->whereHas('activeDirectAgents'); }
        if ($agents === 'none') { $query->whereDoesntHave('activeDirectAgents'); }
        if ($teams === 'has')   { $query->whereHas('activeTeams'); }
        if ($teams === 'none')  { $query->whereDoesntHave('activeTeams'); }

        return $query;
    }
}