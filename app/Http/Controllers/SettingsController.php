<?php

namespace App\Http\Controllers;

use App\Models\Agent;
use App\Models\Config\ActivityType;
use App\Models\Config\CallOutcome;
use App\Models\Config\FollowupActionType;
use App\Models\Config\LeadSource;
use App\Models\Config\LeadStatus;
use App\Models\Config\Setting;
use App\Models\FacebookIntegration;
use App\Models\OfficeLocation;
use App\Models\Project;
use App\Models\Team;
use App\Models\TeamMember;
use App\Models\User;
use App\Services\SettingsService;
use App\Services\TeamService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use App\Services\DelegatedAccessService;

class SettingsController extends Controller
{
    public function __construct(private SettingsService $settings) {}

    /* ============================================================
       GUARD
       ============================================================ */
    private function requireAdmin(): void
{
    if (session('user_role') !== 'admin') {
        abort(403, 'Only admins can access settings.');
    }

    /*
    |--------------------------------------------------------------------------
    | Delegated Admins do not have Settings access
    |--------------------------------------------------------------------------
    | Delegated Admins are intended to work with their permitted
    | leads and lead-related data only. They must not access
    | Settings, even by typing a Settings URL directly.
    |
    | Super Admins are never blocked here.
    */
    $userId = (int) session('user_id');

    $delegatedAccess = app(DelegatedAccessService::class);

    if ($delegatedAccess->hasProfile($userId)) {
        abort(403, 'You do not have permission to access Settings.');
    }
}

    private function requireWorkflowConfigurationAccess(string $type): void
    {
        if (! in_array($type, ['statuses', 'activity-types', 'action-types', 'outcomes'], true)) {
            return;
        }

        app(\App\Services\SuperAdminService::class)->requireSuperAdmin();
    }

    /* ============================================================
       MODEL RESOLVER
       ============================================================ */
    private function modelFor(string $type): string
    {
        return match ($type) {
            'statuses'       => LeadStatus::class,
            'activity-types' => ActivityType::class,
            'action-types'   => FollowupActionType::class,
            'outcomes'       => CallOutcome::class,
            'sources'        => LeadSource::class,
            'teams'          => Team::class,
            default          => abort(404, 'Unknown settings type: ' . $type),
        };
    }

    /* ============================================================
       VALIDATION RULES
       ============================================================ */
    private function rulesFor(string $type): array
    {
        return match ($type) {
            'statuses' => [
                'key'        => 'required|string|max:50|alpha_dash',
                'label'      => 'required|string|max:100',
                'color'      => 'required|string|max:20',
                'is_final'   => 'nullable|boolean',
                'is_active'  => 'nullable|boolean',
                'sort_order' => 'nullable|integer|min:0',
            ],
            'activity-types' => [
                'key'              => 'required|string|max:50|alpha_dash',
                'label'            => 'required|string|max:100',
                'icon'             => 'nullable|string|max:20',
                'requires_outcome' => 'nullable|boolean',
                'is_active'        => 'nullable|boolean',
                'sort_order'       => 'nullable|integer|min:0',
            ],
            'action-types' => [
                'key'        => 'required|string|max:50|alpha_dash',
                'label'      => 'required|string|max:100',
                'is_active'  => 'nullable|boolean',
                'sort_order' => 'nullable|integer|min:0',
            ],
            'outcomes' => [
                'key'                     => 'required|string|max:50|alpha_dash',
                'label'                   => 'required|string|max:100',
                'category'                => 'required|in:positive,neutral,negative',
                'is_connected'            => 'nullable|boolean',
                'activity_scope'           => 'required|in:all,restricted',
                'activity_type_keys'       => 'nullable|array',
                'activity_type_keys.*'     => 'string|exists:activity_types,key',
                'prompts_whatsapp_send'   => 'nullable|boolean',
                'next_action_type_id'     => 'nullable|integer|exists:followup_action_types,id',
                'next_action_delay_hours' => 'required|integer|min:0|max:20000',
                'priority'                => 'required|in:low,normal,high',
                'suggested_status_id'     => 'nullable|integer|exists:lead_statuses,id',
                'context_action_key'      => 'nullable|string|max:80',
                'contact_status_key'      => 'nullable|string|max:50',
                'requires_site_visit_datetime' => 'nullable|boolean',
                'next_action_anchor'       => 'nullable|in:now,visit_before,visit_after',
                'is_active'               => 'nullable|boolean',
                'sort_order'              => 'nullable|integer|min:0',
            ],
            'sources' => [
                'key'        => 'required|string|max:50|alpha_dash',
                'label'      => 'required|string|max:100',
                'is_active'  => 'nullable|boolean',
                'sort_order' => 'nullable|integer|min:0',
            ],
            default => [],
        };
    }

    /* ============================================================
       ROUTING FILTER HELPER
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
        if ($loc !== '') {
            $query->where('location', $loc);
        }
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

    /* ============================================================
       SETTINGS INDEX
       ============================================================ */
    
public function index()
{
    $this->requireAdmin();

    $integration = FacebookIntegration::where('user_id', session('user_id'))->first();

    /*
    |--------------------------------------------------------------------------
    | Delegated access scope
    |--------------------------------------------------------------------------
    */
    $currentUserId = (int) session('user_id');

    $delegatedAccess = app(DelegatedAccessService::class);
    $isDelegated = $delegatedAccess->hasProfile($currentUserId);

    $visibleAgentIds = $isDelegated
        ? $delegatedAccess->visibleAgentIds($currentUserId)
        : [];

    $visibleTeamIds = $isDelegated
        ? $delegatedAccess->allowedTeamIds($currentUserId)
        : [];

    /*
    |--------------------------------------------------------------------------
    | Project Routing filters
    |--------------------------------------------------------------------------
    */
    $routingFilters = [
        'q'        => trim((string) request('routing_q', '')),
        'location' => trim((string) request('routing_location', '')),
        'routed'   => request('routing_filter', 'all'),
        'agents'   => request('routing_agents', 'all'),
        'teams'    => request('routing_teams', 'all'),
    ];

    /*
    |--------------------------------------------------------------------------
    | Project Routing project scope
    |--------------------------------------------------------------------------
    | Normal / unrestricted admins:
    |     All projects remain visible.
    |
    | Delegated admins:
    |     A project is visible ONLY if it has an active routing to:
    |       1. one of the delegated teams, OR
    |       2. one of the delegated agents.
    |
    | No project permission table is used.
    */
    $routingProjectQuery = Project::query();

    if ($isDelegated) {
        if ($visibleAgentIds === [] && $visibleTeamIds === []) {
            // Delegated user has no effective routing scope.
            $routingProjectQuery->whereRaw('1 = 0');
        } else {
            $routingProjectQuery->where(function ($q) use ($visibleAgentIds, $visibleTeamIds) {

                if ($visibleAgentIds !== []) {
                    $q->whereHas('activeDirectAgents', function ($agentQuery) use ($visibleAgentIds) {
                        $agentQuery->whereIn('agents.id', $visibleAgentIds);
                    });
                }

                if ($visibleTeamIds !== []) {
                    $method = $visibleAgentIds !== [] ? 'orWhereHas' : 'whereHas';

                    $q->{$method}('activeTeams', function ($teamQuery) use ($visibleTeamIds) {
                        $teamQuery->whereIn('teams.id', $visibleTeamIds);
                    });
                }
            });
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Apply normal Project Routing filters
    |--------------------------------------------------------------------------
    */
    $routingProjectsQuery = $this->applyRoutingFilters(
        $routingProjectQuery,
        $routingFilters
    );

    /*
    |--------------------------------------------------------------------------
    | Routing counts
    |--------------------------------------------------------------------------
    | Delegated users only see counts for their accessible agents/teams.
    */
    if ($isDelegated) {
        $routingProjectsQuery->withCount([
            'activeDirectAgents' => function ($q) use ($visibleAgentIds) {
                if ($visibleAgentIds === []) {
                    $q->whereRaw('1 = 0');
                } else {
                    $q->whereIn('agents.id', $visibleAgentIds);
                }
            },
            'activeTeams' => function ($q) use ($visibleTeamIds) {
                if ($visibleTeamIds === []) {
                    $q->whereRaw('1 = 0');
                } else {
                    $q->whereIn('teams.id', $visibleTeamIds);
                }
            },
        ]);
    } else {
        $routingProjectsQuery->withCount([
            'activeDirectAgents',
            'activeTeams',
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Routing project list
    |--------------------------------------------------------------------------
    */
    $routingProjects = $routingProjectsQuery
        ->orderByRaw('(
            (SELECT COUNT(*)
             FROM project_agent pa
             WHERE pa.project_id = projects.id
               AND pa.is_active = 1)
            +
            (SELECT COUNT(*)
             FROM project_team pt
             WHERE pt.project_id = projects.id
               AND pt.is_active = 1)
        ) ASC')
        ->orderBy('name')
        ->paginate(50, ['*'], 'routing_page')
        ->appends([
            'tab'              => 'routing',
            'routing_q'        => $routingFilters['q'],
            'routing_location' => $routingFilters['location'],
            'routing_filter'   => $routingFilters['routed'],
            'routing_agents'   => $routingFilters['agents'],
            'routing_teams'    => $routingFilters['teams'],
        ]);

    /*
    |--------------------------------------------------------------------------
    | Unrouted count
    |--------------------------------------------------------------------------
    */
    $routingUnroutedQuery = Project::query()
        ->whereDoesntHave('activeDirectAgents')
        ->whereDoesntHave('activeTeams');

    if ($isDelegated) {
        /*
         * A delegated user cannot see globally-unrouted projects.
         * Their project scope is based on accessible routing.
         * Therefore this remains zero for a delegated user.
         */
        $routingUnroutedCount = 0;
    } else {
        $routingUnroutedCount = $routingUnroutedQuery->count();
    }

    /*
    |--------------------------------------------------------------------------
    | Routing locations
    |--------------------------------------------------------------------------
    | Delegated users only receive locations belonging to their
    | accessible projects.
    */
    $routingLocationsQuery = Project::query()
        ->whereNotNull('location')
        ->where('location', '!=', '');

    if ($isDelegated) {
        if ($visibleAgentIds === [] && $visibleTeamIds === []) {
            $routingLocationsQuery->whereRaw('1 = 0');
        } else {
            $routingLocationsQuery->where(function ($q) use ($visibleAgentIds, $visibleTeamIds) {

                if ($visibleAgentIds !== []) {
                    $q->whereHas('activeDirectAgents', function ($agentQuery) use ($visibleAgentIds) {
                        $agentQuery->whereIn('agents.id', $visibleAgentIds);
                    });
                }

                if ($visibleTeamIds !== []) {
                    $method = $visibleAgentIds !== [] ? 'orWhereHas' : 'whereHas';

                    $q->{$method}('activeTeams', function ($teamQuery) use ($visibleTeamIds) {
                        $teamQuery->whereIn('teams.id', $visibleTeamIds);
                    });
                }
            });
        }
    }

    $routingLocations = $routingLocationsQuery
        ->distinct()
        ->orderBy('location')
        ->pluck('location');

    /*
    |--------------------------------------------------------------------------
    | Matching count
    |--------------------------------------------------------------------------
    */
    $routingMatchingQuery = Project::query();

    if ($isDelegated) {
        if ($visibleAgentIds === [] && $visibleTeamIds === []) {
            $routingMatchingQuery->whereRaw('1 = 0');
        } else {
            $routingMatchingQuery->where(function ($q) use ($visibleAgentIds, $visibleTeamIds) {

                if ($visibleAgentIds !== []) {
                    $q->whereHas('activeDirectAgents', function ($agentQuery) use ($visibleAgentIds) {
                        $agentQuery->whereIn('agents.id', $visibleAgentIds);
                    });
                }

                if ($visibleTeamIds !== []) {
                    $method = $visibleAgentIds !== [] ? 'orWhereHas' : 'whereHas';

                    $q->{$method}('activeTeams', function ($teamQuery) use ($visibleTeamIds) {
                        $teamQuery->whereIn('teams.id', $visibleTeamIds);
                    });
                }
            });
        }
    }

    $routingMatchingCount = $this->applyRoutingFilters(
        $routingMatchingQuery,
        $routingFilters
    )->count();

    /*
    |--------------------------------------------------------------------------
    | Routing teams
    |--------------------------------------------------------------------------
    */
    $routingTeams = $isDelegated
        ? Team::active()
            ->whereIn('id', $visibleTeamIds)
            ->orderBy('name')
            ->get()
        : Team::active()
            ->orderBy('name')
            ->get();

    /*
    |--------------------------------------------------------------------------
    | Routing team member data
    |--------------------------------------------------------------------------
    */
    $teamMembersJson = [];

    foreach ($routingTeams as $team) {
        $members = TeamMember::where('team_id', $team->id)
            ->where('is_active', true)
            ->with('agent.user')
            ->get()
            ->filter(fn ($m) =>
                $m->agent &&
                $m->agent->user &&
                $m->agent->status === 'active' &&
                (
                    ! $isDelegated ||
                    in_array((int) $m->agent->id, $visibleAgentIds, true)
                )
            )
            ->map(fn ($m) => [
                'id'   => $m->agent->id,
                'name' => $m->agent->user->name,
            ])
            ->values()
            ->all();

        $teamMembersJson[$team->id] = $members;
    }

    /*
    |--------------------------------------------------------------------------
    | Settings teams
    |--------------------------------------------------------------------------
    */
    $teamsQuery = Team::with(['manager', 'members'])
        ->orderBy('name');

    if ($isDelegated) {
        $teamsQuery->whereIn('id', $visibleTeamIds);
    }

    $settingsTeams = $teamsQuery->get();

    /*
    |--------------------------------------------------------------------------
    | Settings agents
    |--------------------------------------------------------------------------
    */
    $agentsQuery = Agent::with(['user', 'teams'])
        ->whereHas('user', fn ($q) => $q->where('role', 'agent'))
        ->orderBy('id');

    if ($isDelegated) {
        $agentsQuery->whereIn('id', $visibleAgentIds);
    }

    $settingsAgents = $agentsQuery->get();

    /*
    |--------------------------------------------------------------------------
    | Settings managers
    |--------------------------------------------------------------------------
    */
    $managersQuery = User::where('role', 'team_manager')
        ->with('managedTeams')
        ->orderBy('name');

    if ($isDelegated) {
        if ($visibleTeamIds === []) {
            $managersQuery->whereRaw('1 = 0');
        } else {
            $managersQuery->whereHas('managedTeams', function ($q) use ($visibleTeamIds) {
                $q->whereIn('teams.id', $visibleTeamIds);
            });
        }
    }

    $settingsManagers = $managersQuery->get();

    /*
    |--------------------------------------------------------------------------
    | Final view
    |--------------------------------------------------------------------------
    */
    return view('settings.index', [

        /* -------- Lookup lists -------- */
        'statuses'      => LeadStatus::ordered()->get(),
        'activityTypes' => ActivityType::ordered()->get(),
        'actionTypes'   => FollowupActionType::ordered()->get(),

        'outcomes' => CallOutcome::with([
            'nextActionType',
            'suggestedStatus'
        ])->ordered()->get(),

        'sources' => LeadSource::ordered()->get(),

        /* -------- Teams / Managers / Agents -------- */
        'teams'    => $settingsTeams,
        'agents'   => $settingsAgents,
        'managers' => $settingsManagers,

        /* -------- Generic settings -------- */
        'settingsMap' => Setting::pluck('value', 'key')->all(),
        'officeLocation' => OfficeLocation::active()->first(),

        /* -------- Shared for modals -------- */
        'allStatuses' => LeadStatus::ordered()->get(),
        'allActions'  => FollowupActionType::ordered()->get(),

        /* -------- Project Routing -------- */
        'routingProjects'      => $routingProjects,
        'routingUnroutedCount' => $routingUnroutedCount,
        'routingLocations'     => $routingLocations,
        'routingMatchingCount' => $routingMatchingCount,
        'routingFilters'       => $routingFilters,
        'routingTeams'         => $routingTeams,

        'routingAgents' => $isDelegated
            ? Agent::with('user')
                ->where('status', 'active')
                ->whereIn('id', $visibleAgentIds)
                ->orderBy('id')
                ->get()
            : Agent::with('user')
                ->where('status', 'active')
                ->orderBy('id')
                ->get(),

        /* -------- Website Intake -------- */
        'websiteIntake' => [
            'enabled'       => $this->settings->get('website_intake_enabled', '1') === '1',
            'requireKey'    => $this->settings->get('website_intake_require_api_key', '0') === '1',
            'apiKey'        => $this->settings->get('website_intake_api_key', ''),
            'defaultSource' => $this->settings->get('website_intake_default_source', 'website'),
            'rateLimit'     => (int) $this->settings->get('website_intake_rate_per_minute', 5),
            'endpoint'      => url('/api/leads'),
            'sources'       => LeadSource::active()->ordered()->get(),
        ],

        /* -------- Facebook Leads -------- */
        'facebook' => [
            'integration' => $integration,
            'pages'       => $integration ? $integration->pages() : [],
            'isExpired'   => $integration ? $integration->isExpired() : false,
        ],
    ]);
}

    /* ============================================================
       SAVE generic settings row
       ============================================================ */
    public function save(Request $request, string $type, ?int $id = null)
    {
        $this->requireAdmin();
        $this->requireWorkflowConfigurationAccess($type);

        $model = $this->modelFor($type);
        $rules = $this->rulesFor($type);

        $validated = $request->validate($rules);

        $validated['is_active'] = $request->boolean('is_active', true);

        if ($type === 'statuses') {
            $validated['is_final'] = $request->boolean('is_final', false);
        }
        if ($type === 'activity-types') {
            $validated['requires_outcome'] = $request->boolean('requires_outcome', false);
        }
        if ($type === 'outcomes') {
            $validated['is_connected'] = $request->boolean('is_connected', false);
            $validated['requires_site_visit_datetime'] = $request->boolean('requires_site_visit_datetime', false);
            $validated['prompts_whatsapp_send'] = $request->boolean('prompts_whatsapp_send', false);

            $activityTypeKeys = collect($validated['activity_type_keys'] ?? [])
                ->map(fn ($key) => trim((string) $key))
                ->filter()
                ->unique()
                ->values();

            $activityScope = $validated['activity_scope'];
            unset($validated['activity_scope'], $validated['activity_type_keys']);

            if ($activityScope === 'restricted' && $activityTypeKeys->isEmpty()) {
                return back()->withErrors(['activity_type_keys' => 'Select at least one activity type when the outcome is restricted.'])->withInput();
            }

            $validated['activity_type_filter'] = $activityScope === 'all'
                ? null
                : $activityTypeKeys->implode(',');
        }
        if (empty($validated['sort_order'])) {
            $validated['sort_order'] = (int) $model::max('sort_order') + 1;
        }

        if ($id) {
            $row = $model::findOrFail($id);

            // Configuration keys are canonical identities used by CRM logic,
            // historical records, automation and reporting. Labels may change;
            // keys must remain stable after creation.
            if ($validated['key'] !== $row->key) {
                return back()->withErrors(['key' => 'Configuration keys cannot be changed after creation. Change the user-facing label instead.']);
            }

            $row->update($validated);
            $this->settings->flush();
            return redirect('/settings?tab=' . $type)->with('success', "✅ {$type} updated.");
        }

        try {
            $model::create($validated);
        } catch (\Illuminate\Database\QueryException $e) {
            if ($e->getCode() === '23000') {
                return back()
                    ->withErrors(['key' => 'This key already exists. Choose a different one.'])
                    ->withInput();
            }
            throw $e;
        }

        $this->settings->flush();
        return redirect('/settings?tab=' . $type)->with('success', "✅ {$type} added.");
    }

    /* ============================================================
       TOGGLE / DELETE / MOVE
       ============================================================ */
    public function toggle(string $type, int $id)
    {
        $this->requireAdmin();
        $this->requireWorkflowConfigurationAccess($type);
        $model = $this->modelFor($type);
        $row = $model::findOrFail($id);

        if ($type === 'activity-types' && $row->is_system) {
            return back()->with('error', 'System activity types cannot be disabled.');
        }

        $row->update(['is_active' => ! $row->is_active]);
        $this->settings->flush();
        return back()->with('success', '✅ Status toggled.');
    }

    public function destroy(string $type, int $id)
    {
        $this->requireAdmin();
        $this->requireWorkflowConfigurationAccess($type);
        $model = $this->modelFor($type);
        $row = $model::findOrFail($id);

        if ($type === 'activity-types' && $row->is_system) {
            return back()->with('error', 'System activity types cannot be deleted.');
        }

        try {
            $row->delete();
        } catch (\Illuminate\Database\QueryException $e) {
            return back()->with('error', 'Cannot delete: this item is in use. Disable it instead.');
        }

        $this->settings->flush();
        return back()->with('success', '🗑️ Deleted.');
    }

    public function move(string $type, int $id, string $direction)
    {
        $this->requireAdmin();
        $this->requireWorkflowConfigurationAccess($type);

        if (! in_array($direction, ['up', 'down'], true)) {
            abort(400);
        }

        $model = $this->modelFor($type);
        $row = $model::findOrFail($id);

        $op = $direction === 'up' ? '<' : '>';
        $order = $direction === 'up' ? 'desc' : 'asc';

        $neighbor = $model::where('sort_order', $op, $row->sort_order)
            ->orderBy('sort_order', $order)
            ->first();

        if (! $neighbor) return back();

        $tmp = $row->sort_order;
        $row->update(['sort_order' => $neighbor->sort_order]);
        $neighbor->update(['sort_order' => $tmp]);

        $this->settings->flush();
        return back();
    }

    /* ============================================================
       TEAMS
       ============================================================ */
    public function saveTeam(Request $request, ?int $id = null)
    {
        $this->requireAdmin();

        $validated = $request->validate([
            'name'            => 'required|string|max:100',
            'description'     => 'nullable|string|max:255',
            'manager_user_id' => 'nullable|integer|exists:users,id',
            'is_active'       => 'nullable|boolean',
            'agent_ids'       => 'nullable|array',
            'agent_ids.*'     => 'integer|exists:agents,id',
        ]);

        $agentIds = $validated['agent_ids'] ?? [];

        $data = [
            'name'            => $validated['name'],
            'description'     => $validated['description'] ?? null,
            'manager_user_id' => $validated['manager_user_id'] ?? null,
            'is_active'       => $request->boolean('is_active', true),
        ];

        $svc = app(TeamService::class);

        if ($id) {
            $team = Team::findOrFail($id);
            $svc->update($team, $data, $agentIds);
            $msg = '✅ Team updated.';
        } else {
            $created = $svc->create($data, $agentIds);
            $team = $created instanceof Team ? $created : Team::latest('id')->first();
            $msg  = '✅ Team created.';
        }

        // Auto-add: admins + this team's manager as team members
        if ($team instanceof Team) {
            $this->ensureTeamAutoMembers($team);
        }

        return redirect('/settings?tab=teams')->with('success', $msg);
    }

    /**
     * Every team gets its manager + all admins auto-added as members,
     * so they show up in team-lead distribution and can be assigned.
     */
    private function ensureTeamAutoMembers(Team $team): void
    {
        // Collect user_ids we want on the team
        $userIds = [];

        if ($team->manager_user_id) {
            $userIds[] = (int) $team->manager_user_id;
        }

        $userIds = [];

        if ($team->manager_user_id) {
            $userIds[] = (int) $team->manager_user_id;
        }

        if (empty($userIds)) return;

        $agentIds = Agent::whereIn('user_id', $userIds)->pluck('id')->all();
        if (empty($agentIds)) return;

        foreach ($agentIds as $aid) {
            TeamMember::updateOrCreate(
                ['team_id' => $team->id, 'agent_id' => $aid],
                ['is_active' => true]
            );
        }
    }

    public function toggleTeam(int $id)
    {
        $this->requireAdmin();
        $team = Team::findOrFail($id);
        app(TeamService::class)->toggle($team);
        return back()->with('success', '✅ Team status toggled.');
    }

    public function destroyTeam(int $id)
    {
        $this->requireAdmin();
        $team = Team::findOrFail($id);
        app(TeamService::class)->destroy($team);
        return back()->with('success', '🗑️ Team deleted.');
    }

    /* ============================================================
       WEBSITE INTAKE
       ============================================================ */
    public function websiteIntake()
    {
        $this->requireAdmin();

        return view('settings.website-intake', [
            'enabled'       => $this->settings->get('website_intake_enabled', '1') === '1',
            'requireKey'    => $this->settings->get('website_intake_require_api_key', '0') === '1',
            'apiKey'        => $this->settings->get('website_intake_api_key', ''),
            'defaultSource' => $this->settings->get('website_intake_default_source', 'website'),
            'rateLimit'     => (int) $this->settings->get('website_intake_rate_per_minute', 5),
            'endpoint'      => url('/api/leads'),
            'sources'       => LeadSource::active()->ordered()->get(),
        ]);
    }

    public function saveWebsiteIntake(Request $request)
    {
        $this->requireAdmin();

        $validated = $request->validate([
            'enabled'         => 'nullable|boolean',
            'require_api_key' => 'nullable|boolean',
            'default_source'  => 'required|string|max:50',
            'rate_per_minute' => 'required|integer|min:1|max:60',
        ]);

        $this->settings->set('website_intake_enabled',         $request->boolean('enabled') ? '1' : '0');
        $this->settings->set('website_intake_require_api_key', $request->boolean('require_api_key') ? '1' : '0');
        $this->settings->set('website_intake_default_source',  $validated['default_source']);
        $this->settings->set('website_intake_rate_per_minute', (string) $validated['rate_per_minute']);

        return back()->with('success', '✅ Website intake settings saved.');
    }

    public function regenerateApiKey()
    {
        $this->requireAdmin();
        $newKey = Str::random(48);
        $this->settings->set('website_intake_api_key', $newKey);
        return back()->with('success', '🔑 New API key generated. Update your website form.');
    }

    /* ============================================================
       GENERAL SETTINGS
       ============================================================ */
        public function saveGeneral(Request $request)
    {
        $this->requireAdmin();

        $validated = $request->validate([
            'company_name'                  => 'nullable|string|max:100',
            'activity_log_window_minutes'   => 'nullable|integer|min:1|max:1440',
            'first_contact_delay_minutes'   => 'nullable|integer|min:1|max:1440',
            'followup_escalation_hours'     => 'nullable|integer|min:1|max:72',
            'default_agent_max_daily_leads' => 'nullable|integer|min:1|max:200',
            'intelligent_engine_enabled'    => 'nullable|boolean',
            'contacts_visibility'           => 'required|in:telecallers_only,everyone',
            'followup_work_start'           => ['required','date_format:H:i'],
            'followup_work_end'             => ['required','date_format:H:i'],
            'followup_working_days'         => 'required|array|min:1',
            'followup_working_days.*'       => 'integer|min:0|max:6',
            'attendance_radius_meters'       => 'required|integer|min:100|max:1000',
        ]);

        $validated['intelligent_engine_enabled'] = $request->boolean('intelligent_engine_enabled');

        if ($validated['followup_work_start'] >= $validated['followup_work_end']) {
            return back()->withErrors(['followup_work_end' => 'Automatic follow-up end time must be later than the start time.'])->withInput();
        }

        $validated['followup_working_days'] = implode(',', array_map('intval', $validated['followup_working_days']));

        $attendanceRadius = (int) $validated['attendance_radius_meters'];
        unset($validated['attendance_radius_meters']);

        $office = OfficeLocation::active()->first();
        if (! $office) {
            return back()
                ->withErrors(['attendance_radius_meters' => 'No active office location is configured. Configure an office location before changing the check-in radius.'])
                ->withInput();
        }

        foreach ($validated as $key => $value) {
            Setting::put($key, (string) $value);
        }

        $office->update(['radius_meters' => $attendanceRadius]);

        $this->settings->flush();
        return back()->with('success', '✅ Settings saved. Office check-in radius is now ' . $attendanceRadius . 'm.');
    }
}