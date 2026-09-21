<?php

namespace App\Http\Controllers;

use App\Models\Project;
use Illuminate\Http\Request;

class ProjectController extends Controller
{
    /**
     * Project master list. Agents/managers see only projects routed to their scope.
     * Admins see all projects.
     */
    public function index(Request $request)
    {
        if (! session('user_id')) return redirect('/login');

        $q = trim((string) $request->input('q'));
        $statusInput = $request->input('status', 'active');
        $status = in_array($statusInput, ['active', 'inactive', 'all'], true) ? $statusInput : 'active';
        $allowedSorts = ['name', 'location', 'rera_number', 'leads_count', 'status'];
        $sort = in_array($request->input('sort'), $allowedSorts, true) ? $request->input('sort') : 'name';
        $direction = strtolower((string) $request->input('direction', 'asc')) === 'desc' ? 'desc' : 'asc';

        $projects = Project::query()
            // Non-admins see ONLY projects routed to one of their active teams.
            // Direct project-agent assignments are intentionally excluded from this workspace.
            ->when(session('user_role') !== 'admin', function ($query) {
                $userId = (int) session('user_id');
                $agentId = \App\Models\Agent::where('user_id', $userId)->value('id');
                $teamIds = $agentId
                    ? \App\Models\TeamMember::where('agent_id', $agentId)->where('is_active', true)->pluck('team_id')->all()
                    : [];
                if (session('user_role') === 'team_manager') {
                    $teamIds = array_merge($teamIds, \App\Models\Team::where('manager_user_id', $userId)->where('is_active', true)->pluck('id')->all());
                }
                $teamIds = array_values(array_unique(array_map('intval', $teamIds)));
                $directAgentId = $agentId ? (int) $agentId : 0;
                if (!$teamIds && !$directAgentId) return $query->whereRaw('1 = 0');
                return $query->where(function ($scope) use ($teamIds, $directAgentId) {
                    if ($directAgentId) {
                        $scope->whereExists(function ($q) use ($directAgentId) {
                            $q->selectRaw('1')->from('project_agent')->whereColumn('project_agent.project_id', 'projects.id')->where('project_agent.agent_id', $directAgentId)->where('project_agent.is_active', 1);
                        });
                    }
                    if ($teamIds) {
                        $scope->orWhereExists(function ($q) use ($teamIds) {
                            $q->selectRaw('1')->from('project_team')->whereColumn('project_team.project_id', 'projects.id')->whereIn('project_team.team_id', $teamIds)->where('project_team.is_active', 1);
                        });
                    }
                });
            })
            ->withCount('leads')
            ->when($status !== 'all', fn ($query) => $query->where('status', $status))
            ->when($q !== '', function ($query) use ($q) {
                $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $q) . '%';
                $query->where(function ($inner) use ($like) {
                    $inner->where('name', 'like', $like)
                          ->orWhere('location', 'like', $like)
                          ->orWhere('rera_number', 'like', $like);
                });
            })
            ->orderBy($sort, $direction)
            ->orderBy('id', 'asc')
            ->paginate(40)
            ->withQueryString();

        return view('projects.index', compact(
            'projects', 'q', 'status', 'sort', 'direction'
        ));
    }

    /**
     * Admin: add a new project.
     */
    public function store(Request $request)
    {
        if (session('user_role') !== 'admin') {
            return redirect('/')->with('error', 'Only admins can add projects.');
        }

        $validated = $request->validate([
            'name'        => 'required|string|max:255',
            'location'    => 'required|string|max:255',
            'rera_number' => 'nullable|string|max:255',
        ]);

        Project::create([
            'name'        => $validated['name'],
            'location'    => $validated['location'],
            'rera_number' => $validated['rera_number'] ?? null,
            'status'      => 'active',
        ]);

        return redirect('/projects')->with('success', '🏗️ Project added!');
    }
    
        /**
     * AJAX project search for autocomplete pickers.
     * Returns top 20 matches as JSON: id, name, location.
     */
    public function search(Request $request)
    {
        if (! session('user_id')) {
            return response()->json(['results' => []], 401);
        }

        $q = trim((string) $request->input('q', ''));
        if (mb_strlen($q) < 2) {
            return response()->json(['results' => []]);
        }

        $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $q) . '%';

        $query = \App\Models\Project::query()
            ->where(function ($qq) use ($like) {
                $qq->where('name', 'like', $like)
                   ->orWhere('location', 'like', $like)
                   ->orWhere('rera_number', 'like', $like);
            });

        // Respect role scoping — agents see only their visible projects
        $query->visibleTo(session('user_id'), session('user_role'));

        $rows = $query->orderBy('name')->limit(20)->get(['id', 'name', 'location']);

        return response()->json([
            'results' => $rows->map(fn ($p) => [
                'id'       => $p->id,
                'name'     => $p->name,
                'location' => $p->location,
            ])->values(),
        ]);
    }
    
        /**
     * Update an existing project (name, location, RERA, status).
     * Admin-only.
     */
    public function update(Request $request, int $id)
    {
        if (session('user_role') !== 'admin') {
            abort(403, 'Only admins can edit projects.');
        }

        $validated = $request->validate([
            'name'        => 'required|string|max:255',
            'location'    => 'required|string|max:255',
            'rera_number' => 'nullable|string|max:255',
            'status'      => 'required|in:active,inactive',
        ]);

        $project = Project::findOrFail($id);

        $project->update([
            'name'        => $validated['name'],
            'location'    => $validated['location'],
            'rera_number' => $validated['rera_number'] ?? null,
            'status'      => $validated['status'],
        ]);

        return redirect('/projects')
            ->with('success', '✅ Project updated.');
    }
}