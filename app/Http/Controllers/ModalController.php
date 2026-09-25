<?php

namespace App\Http\Controllers;

use App\Models\Agent;
use App\Models\Config\ActivityType;
use App\Models\Contact;
use App\Models\Config\CallOutcome;
use App\Models\Config\FollowupActionType;
use App\Models\Config\LeadSource;
use App\Models\Config\LeadStatus;
use App\Models\Followup;
use App\Models\Lead;
use App\Models\LeadAgent;
use App\Models\Project;
use App\Models\Team;
use App\Models\TeamMember;
use App\Models\User;
use App\Services\AccessService;
use App\Services\SettingsService;
use App\Services\TeamService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ModalController extends Controller
{
    public function __construct(
        private AccessService $access,
    ) {}

    /* ============================================================
       MAIN DISPATCHER
       ============================================================ */

    public function show(Request $request, string $name)
    {
        if (! session('user_id')) {
            return response('Session expired. Please login again.', 401)
                ->header('X-Modal-Title', 'Error');
        }

        if ($name === 'settings') {
            return $this->showSettingsModal($request);
        }

        $titles = [
            'log-activity'            => 'Log Activity',
            'change-status'           => 'Change Status',
            'schedule-followup'       => 'Schedule Follow-up',
            'assign-project'          => 'Assign Project',
            'assign-agent'            => 'Assign Agent',
            'complete-task'           => 'Complete Task',
            'revive-lead'             => 'Revive Lead',
            'edit-booking'            => 'Edit Booking Details',
            'team-form'               => 'Team',
            'agent-form'              => 'Agent',
            'manager-form'            => 'Team Manager',
            'add-lead'                => '➕ New Lead',
            'add-project'             => '🏗️ New Project',
            'edit-project'            => '✏️ Edit Project',
            'project-routing'         => 'Project Routing',
            'my-team-agent-form'      => 'Team Agent',
            'my-team-project-routing' => 'Project Routing',
            'change-role'             => '🔑 Change Role',
            'log-call'                => 'Log Call',
            'share-lead'              => '📤 Share Lead on WhatsApp',
            'task-share-lead'         => '📤 Share Lead',
            'external-share'          => '📤 Share Lead Externally',
            'edit-tags'               => '🏷️ Tags & Labels',
            'edit-lost-reason'        => '✏️ Edit Lost Reason',
            'bulk-edit-lost-reason'   => '✏️ Bulk Edit Lost Reason',
            'dispose-followup'        => '🗑 Dispose Future Follow-up',
        ];

        if (! isset($titles[$name])) {
            abort(404);
        }

        $data = [];

        /* ========================================================
           LOAD LEAD
           ======================================================== */

        $modalLeadId = $request->input(
            'lead',
            $request->input('lead_id')
        );

        if ($modalLeadId) {
            $data['lead'] = Lead::with([
                'agent.user',
                'project',
                'tag',
                'labels',
            ])->findOrFail($modalLeadId);
        }

        /* ========================================================
           LEAD-RELATED MODAL ACCESS
           ======================================================== */

        /*
         * These modals operate on a specific lead.
         *
         * View-only / display operations require canViewLead().
         * Lead-changing operations require canManageLead().
         */

        $viewLeadModals = [
            'log-activity',
            'schedule-followup',
            'share-lead',
            'external-share',
        ];

        $workLeadModals = [
            'assign-agent',
            'complete-task',
            'edit-booking',
            'task-share-lead',
            'dispose-followup',
        ];

        $manageLeadModals = [
            'change-status',
            'assign-project',
            'revive-lead',
            'edit-lost-reason',
        ];

        if ($name === 'log-call') {
            $contactId = $request->input('contact');

            if (! $contactId) {
                abort(400, 'A contact is required to log a call.');
            }

            $contact = Contact::findOrFail($contactId);

            if (! $this->access->canViewContact($contact)) {
                abort(403, 'You are not allowed to call this contact.');
            }

            $data['contact'] = $contact;
        }

        if (in_array($name, $viewLeadModals, true)) {
            $lead = $data['lead'] ?? null;

            if (! $lead || ! $this->access->canViewLead($lead)) {
                abort(403, 'You do not have permission to view this lead.');
            }
        }

        if (in_array($name, $workLeadModals, true)) {
            $lead = $data['lead'] ?? null;

            if (! $lead || ! $this->access->canWorkLead($lead)) {
                abort(403, 'You are not allowed to work on this lead.');
            }
        }

        if (in_array($name, $manageLeadModals, true)) {
            $lead = $data['lead'] ?? null;

            if (! $lead || ! $this->access->canManageLead($lead)) {
                abort(403, 'You do not have permission to manage this lead.');
            }
        }

        /* ========================================================
           ASSIGN PROJECT
           ======================================================== */

        if ($name === 'assign-project') {
            /*
             * For delegated/scoped users, project choices should be
             * derived from projects represented by leads they can see.
             *
             * Unrestricted Admins retain the existing Project::visibleTo()
             * behavior.
             */
            if ($this->access->hasDelegatedProfile()) {
                $accessibleLeadIds = $this->accessibleLeadIds();

                $projectIds = $accessibleLeadIds === []
                    ? []
                    : Lead::query()
                        ->whereIn('id', $accessibleLeadIds)
                        ->whereNotNull('project_id')
                        ->pluck('project_id')
                        ->unique()
                        ->values()
                        ->all();

                /*
                 * Always retain the lead's current project if present.
                 * The current lead is already authorized, so this prevents
                 * the current selection from disappearing unexpectedly.
                 */
                if (! empty($data['lead']?->project_id)) {
                    $projectIds[] = (int) $data['lead']->project_id;
                    $projectIds = array_values(array_unique($projectIds));
                }

                $data['projects'] = empty($projectIds)
                    ? collect()
                    : Project::active()
                        ->whereIn('id', $projectIds)
                        ->orderBy('name')
                        ->get();
            } else {
                $data['projects'] = Project::active()
                    ->visibleTo(session('user_id'), session('user_role'))
                    ->orderBy('name')
                    ->get();
            }
        }

        /* ========================================================
           ASSIGN AGENT
           ======================================================== */

        if ($name === 'assign-agent') {
            $lead = $data['lead'] ?? null;

            if ($lead) {
                $allowed = session('user_role') === 'agent'
                    ? $this->access->canWorkLead($lead)
                    : $this->access->canManageLead($lead);

                if (! $allowed) {
                    abort(403, 'You do not have permission to work on this lead.');
                }
            }

            $agentsQuery = Agent::with(['user', 'teams'])
                ->where('status', 'active')
                ->orderBy('id');

            /*
             * Delegated users only see agents inside their effective scope.
             */
            if ($this->access->hasDelegatedProfile()) {
                $allowedAgentIds = $this->access->visibleAgentIds();

                $agentsQuery->whereIn(
                    'id',
                    ! empty($allowedAgentIds) ? $allowedAgentIds : [-1]
                );
            }

            $data['agents'] = $agentsQuery->get();

            $data['currentPrimaryId'] = $lead?->agent_id;

            if ($lead) {
                $sharedQuery = $lead->assignments()
                    ->active()
                    ->where('is_primary', false);

                if ($this->access->hasDelegatedProfile()) {
                    $allowedAgentIds = $this->access->visibleAgentIds();

                    $sharedQuery->whereIn(
                        'agent_id',
                        ! empty($allowedAgentIds) ? $allowedAgentIds : [-1]
                    );
                }

                $data['sharedAgentIds'] = $sharedQuery
                    ->pluck('agent_id')
                    ->all();
            } else {
                $data['sharedAgentIds'] = [];
            }
        }

        /* ========================================================
           TASK SHARE LEAD
           ======================================================== */

        if ($name === 'task-share-lead') {
            $followup = $this->resolveFollowup($request);

            if (! $followup) {
                abort(
                    422,
                    'Share Lead is not available for this lead because there is no pending follow-up to complete. Open a current work task or schedule a follow-up first.'
                );
            }

            if ($followup->status !== 'pending') {
                abort(422, 'This follow-up has already been completed.');
            }

            $followupLead = $followup->lead;

            if (! $followupLead || ! $this->access->canWorkLead($followupLead)) {
                abort(403, 'You are not allowed to work on this lead.');
            }

            $data['followup'] = $followup;
            $data['lead'] = $followupLead;

            /*
             * Keep the initial Share Lead modal lightweight.
             */
            $data['knownGroups'] = \App\Models\LeadExternalShare::query()
                ->whereNotNull('group_name')
                ->where('group_name', '!=', '')
                ->distinct()
                ->orderBy('group_name')
                ->pluck('group_name')
                ->take(50);

            $titles['task-share-lead'] = '📤 Share Lead';
        }

        /* ========================================================
           COMPLETE TASK
           ======================================================== */

        if ($name === 'complete-task') {
            $followup = $this->resolveFollowup($request);

            if (! $followup) {
                abort(
                    422,
                    'Done — Log Now is not available for this lead because there is no pending follow-up to complete. Open a current work task or schedule a follow-up first.'
                );
            }

            $followupLead = $followup->lead;

            if (! $followupLead || ! $this->access->canWorkLead($followupLead)) {
                abort(403, 'You are not allowed to work on this lead.');
            }

            $data['followup'] = $followup;
            $data['lead'] = $followupLead;

            $settings = app(SettingsService::class);

            $data['allOutcomes'] = $settings->callOutcomes(true);
            $data['taskContext'] = $followup->action_type;
        }

        /* ========================================================
           DISPOSE FOLLOW-UP
           ======================================================== */

        if ($name === 'dispose-followup') {
            $followup = $this->resolveFollowup($request);

            if (
                ! $followup
                || $followup->status !== 'pending'
                || ! $followup->scheduled_for?->isFuture()
            ) {
                abort(422, 'Only a pending future follow-up can be disposed.');
            }

            $followupLead = $followup->lead;

            if (! $followupLead || ! $this->access->canWorkLead($followupLead)) {
                abort(403, 'You are not allowed to work on this lead.');
            }

            $data['followup'] = $followup;
            $data['lead'] = $followupLead;
        }

        /* ========================================================
           EDIT LOST REASON
           ======================================================== */

        if ($name === 'edit-lost-reason') {
            if (! $this->access->can('leads.status_change')) {
                abort(403);
            }

            $lead = $data['lead'] ?? null;

            if (! $lead || ! $this->access->canManageLead($lead)) {
                abort(403, 'You do not have permission to manage this lead.');
            }

            if (! $lead->isLost()) {
                abort(422, 'This lead is not currently Lost.');
            }

            $data['lostReasonOptions'] =
                \App\Services\LeadStatusService::lostReasonOptions();

            $data['pendingNurture'] = Followup::where('lead_id', $lead->id)
                ->where('status', 'pending')
                ->where('action_type', 'reactivation_call')
                ->orderBy('scheduled_for')
                ->first();
        }

        /* ========================================================
           BULK EDIT LOST REASON
           ======================================================== */

        if ($name === 'bulk-edit-lost-reason') {
            if (! $this->access->can('leads.status_change')) {
                abort(403);
            }

            $ids = collect(
                explode(',', (string) $request->input('lead_ids', ''))
            )
                ->map(fn ($id) => (int) trim($id))
                ->filter(fn ($id) => $id > 0)
                ->unique()
                ->values();

            if ($ids->isEmpty()) {
                abort(422, 'No leads were selected.');
            }

            if ($ids->count() > 100) {
                abort(422, 'Please select a maximum of 100 leads.');
            }

            /*
             * Never trust submitted lead IDs.
             *
             * Reduce them to leads actually visible to the current user.
             */
            $accessibleLeadIds = $this->accessibleLeadIds();

            if (! $this->access->isUnrestrictedAdmin()) {
                $ids = $ids
                    ->filter(fn ($id) => in_array(
                        (int) $id,
                        $accessibleLeadIds,
                        true
                    ))
                    ->values();

                if ($ids->isEmpty()) {
                    abort(
                        403,
                        'None of the selected leads are within your permitted lead scope.'
                    );
                }
            }

            $count = Lead::whereIn('id', $ids)
                ->where('status', 'lost')
                ->count();

            if ($count !== $ids->count()) {
                abort(
                    422,
                    'Bulk Lost Reason editing is available only for Lost leads.'
                );
            }

            $data['leadIdsCsv'] = $ids->implode(',');
            $data['selectedCount'] = $ids->count();
            $data['lostReasonOptions'] =
                \App\Services\LeadStatusService::lostReasonOptions();
        }

        /* ========================================================
           REVIVE LEAD
           ======================================================== */

        if ($name === 'revive-lead') {
            $lead = $data['lead'] ?? null;

            if (! $lead || ! $this->access->canManageLead($lead)) {
                abort(403, 'You do not have permission to manage this lead.');
            }

            $settings = app(SettingsService::class);

            $allowedKeys = $settings->allowedTransitionsFrom(
                $lead->statusKey()
            );

            $data['resumeOptions'] = $settings->statuses()
                ->whereIn('key', $allowedKeys)
                ->where('is_final', false)
                ->values();

            $data['defaultVisit'] = now()
                ->addDay()
                ->setTime(11, 0)
                ->format('Y-m-d\TH:i');
        }

        /* ========================================================
           EDIT BOOKING
           ======================================================== */

        if ($name === 'edit-booking') {
            $lead = $data['lead'] ?? null;

            if (! $lead || ! $this->access->canChangeBooking($lead)) {
                abort(403, 'You do not have permission to edit this lead.');
            }

            $settings = app(SettingsService::class);

            $data['defaultBrokeragePct'] =
                $settings->get('default_brokerage_percentage', 2);
        }

        /* ========================================================
           EDIT TAGS
           ======================================================== */

        if ($name === 'edit-tags') {
            $lead = $data['lead'] ?? null;

            if (! $lead) {
                abort(400, 'Missing lead.');
            }

            // Tags are lead classification, not an admin-only action.
            // Anyone who can view this lead may open/edit its tags.
            if (! $this->access->canViewLead($lead)) {
                abort(403, 'You do not have permission to view this lead.');
            }

            $tagSvc = app(\App\Services\LeadTagService::class);

            $data['allTags'] =
                $tagSvc->tags();

            $data['allGroups'] =
                $tagSvc->labelsGrouped();

            $data['currentTagId'] =
                $lead->tag_id;

            $data['currentLabelIds'] =
                $lead->labels->pluck('id')->all();
        }

        /* ========================================================
           TEAM FORM
           ======================================================== */

        if ($name === 'team-form') {
            if (! $this->access->can('teams.manage')) {
                abort(403);
            }

            $team = null;

            if ($request->filled('team')) {
                $team = Team::with('members')
                    ->findOrFail($request->input('team'));
            }

            $data['team'] = $team;

            $data['managers'] = User::whereIn(
                    'role',
                    ['admin', 'team_manager']
                )
                ->orderBy('name')
                ->get();

            $data['allAgents'] = Agent::with('user')
                ->where('status', 'active')
                ->orderBy('id')
                ->get();

            $data['selectedAgentIds'] =
                $team ? $team->agentIds() : [];

            if ($team) {
                $titles['team-form'] = 'Edit Team';
            }
        }

        /* ========================================================
           AGENT FORM
           ======================================================== */

        if ($name === 'agent-form') {
            if (! $this->access->can('agents.manage')) {
                abort(403);
            }

            $agent = null;

            if ($request->filled('agent')) {
                $agent = Agent::with([
                    'user',
                    'teams',
                ])->findOrFail($request->input('agent'));
            }

            $data['agent'] = $agent;

            $data['allTeams'] = Team::active()
                ->orderBy('name')
                ->get();

            $data['selectedTeamIds'] = $agent
                ? $agent->teams()
                    ->wherePivot('is_active', true)
                    ->pluck('teams.id')
                    ->all()
                : [];

            if ($agent) {
                $titles['agent-form'] = 'Edit Agent';
            }
        }

        /* ========================================================
           MANAGER FORM
           ======================================================== */

        if ($name === 'manager-form') {
            if (! $this->access->can('teams.manage')) {
                abort(403);
            }

            $manager = null;

            if ($request->filled('manager')) {
                $manager = User::with('managedTeams')
                    ->findOrFail($request->input('manager'));
            }

            $data['manager'] = $manager;

            if ($manager) {
                $titles['manager-form'] = 'Edit Team Manager';
            }
        }

        /* ========================================================
           ADD LEAD
           ======================================================== */

        if ($name === 'add-lead') {
            $data['sources'] =
                app(SettingsService::class)->sources();

            // Android device-share payloads are intentionally consumed once.
            // The browser/PWA never gets unrestricted access to the device data.
            if ($request->boolean('device_import')) {
                $deviceImport = session()->pull('device_lead_import', []);
                $data['deviceImport'] = [
                    'name' => (string) ($deviceImport['name'] ?? ''),
                    'phone' => (string) ($deviceImport['phone'] ?? ''),
                    'email' => (string) ($deviceImport['email'] ?? ''),
                    'shared_text' => (string) ($deviceImport['shared_text'] ?? ''),
                    'shared_url' => (string) ($deviceImport['shared_url'] ?? ''),
                    'source' => (string) ($deviceImport['source'] ?? ''),
                ];
            } else {
                $data['deviceImport'] = [
                    'name' => (string) $request->input('device_name', ''),
                    'phone' => (string) $request->input('device_phone', ''),
                    'email' => (string) $request->input('device_email', ''),
                    'shared_text' => '',
                    'shared_url' => '',
                    'source' => (string) $request->input('device_source', ''),
                ];
            }

            $currentUser =
                User::find(session('user_id'));

            $assignable = $currentUser
                ? app(TeamService::class)
                    ->assignableAgentsFor($currentUser)
                : collect();

            /*
             * Delegated users may only assign a new lead to agents
             * inside their effective delegated scope.
             */
            if ($this->access->hasDelegatedProfile()) {
                $allowedAgentIds =
                    $this->access->visibleAgentIds();

                $assignable = $assignable
                    ->whereIn(
                        'id',
                        ! empty($allowedAgentIds)
                            ? $allowedAgentIds
                            : [-1]
                    )
                    ->values();
            }

            $data['agentsByTeam'] =
                $assignable->groupBy(function ($a) {
                    // An agent may retain historical/inactive team memberships.
                    // Assignment UI must display the agent under an active membership,
                    // not simply the first historical team relationship returned.
                    $team = $a->teams->first(function ($team) {
                        $memberActive = $team->pivot->is_active ?? true;

                        return (bool) $team->is_active
                            && (bool) $memberActive;
                    });

                    return $team
                        ? $team->name
                        : 'Other Agents';
                });

            $data['selfAgentId'] =
                Agent::where(
                    'user_id',
                    session('user_id')
                )->value('id');
        }

        /* ========================================================
           ADD PROJECT
           ======================================================== */

        if ($name === 'add-project') {
            if (! $this->access->can('agents.manage')) {
                abort(403);
            }
        }

        /* ========================================================
           EDIT PROJECT
           ======================================================== */

        if ($name === 'edit-project') {
            if (! $this->access->can('team_managers.manage')) {
                abort(403);
            }

            $project = Project::findOrFail(
                $request->input('project')
            );

            $data['project'] = $project;

            $titles['edit-project'] =
                '✏️ ' . $project->name;
        }

        /* ========================================================
           PROJECT ROUTING
           ======================================================== */

        if ($name === 'project-routing') {
            if (! $this->access->can('projects.manage')) {
                abort(403);
            }

            $project = Project::with([
                'activeDirectAgents.user',
                'activeTeams',
            ])->findOrFail(
                $request->input('project')
            );

            $data['project'] = $project;

            $data['routingTeams'] =
                Team::active()
                    ->orderBy('name')
                    ->get();

            $data['routingAgents'] =
                Agent::with('user')
                    ->where('status', 'active')
                    ->orderBy('id')
                    ->get();

            $titles['project-routing'] =
                '🎯 ' . $project->name . ' — Routing';
        }

        /* ========================================================
           MY TEAM AGENT FORM
           ======================================================== */

        if ($name === 'my-team-agent-form') {
            $role = session('user_role');

            if (! in_array(
                $role,
                ['admin', 'team_manager'],
                true
            )) {
                abort(403);
            }

            /*
             * Delegated Admins are intentionally limited to their
             * delegated lead scope and must not regain team-management
             * functionality through this older modal endpoint.
             */
            if (
                $role === 'admin'
                && $this->access->hasDelegatedProfile()
            ) {
                abort(
                    403,
                    'You do not have permission to manage team agents.'
                );
            }

            $agent = null;

            if ($request->filled('agent')) {
                $agent = Agent::with([
                    'user',
                    'teams',
                ])->findOrFail(
                    $request->input('agent')
                );
            }

            $data['agent'] = $agent;

            $titles['my-team-agent-form'] =
                $agent
                    ? '✏️ Edit Agent'
                    : '➕ Add Agent to My Team';
        }

        /* ========================================================
           MY TEAM PROJECT ROUTING
           ======================================================== */

        if ($name === 'my-team-project-routing') {
            $role = session('user_role');

            if (! in_array(
                $role,
                ['admin', 'team_manager'],
                true
            )) {
                abort(403);
            }

            /*
             * Delegated Admins do not receive project/team-management
             * access merely because their account role is admin.
             */
            if (
                $role === 'admin'
                && $this->access->hasDelegatedProfile()
            ) {
                abort(
                    403,
                    'You do not have permission to manage project routing.'
                );
            }

            $project = Project::findOrFail(
                $request->input('project')
            );

            $myTeamIds =
                $this->access->visibleTeamIds();

            $myAgentIds = TeamMember::whereIn(
                    'team_id',
                    $myTeamIds
                )
                ->where('is_active', true)
                ->pluck('agent_id')
                ->unique()
                ->all();

            $data['project'] = $project;

            $data['myTeams'] =
                Team::whereIn(
                    'id',
                    $myTeamIds
                )
                ->orderBy('name')
                ->get();

            $data['myAgents'] =
                Agent::with('user')
                    ->whereIn(
                        'id',
                        $myAgentIds
                    )
                    ->orderBy('id')
                    ->get();

            $data['currentTeamIds'] =
                DB::table('project_team')
                    ->where('project_id', $project->id)
                    ->whereIn('team_id', $myTeamIds)
                    ->where('is_active', 1)
                    ->pluck('team_id')
                    ->all();

            $data['currentAgentIds'] =
                DB::table('project_agent')
                    ->where('project_id', $project->id)
                    ->whereIn('agent_id', $myAgentIds)
                    ->where('is_active', 1)
                    ->pluck('agent_id')
                    ->all();

            $titles['my-team-project-routing'] =
                '🎯 ' . $project->name . ' — Routing';
        }

        /* ========================================================
           CHANGE ROLE
           ======================================================== */

        if ($name === 'change-role') {
            if (! $this->access->can('projects.manage')) {
                abort(403);
            }

            $targetUser =
                User::findOrFail(
                    $request->input('user')
                );

            $agent =
                Agent::where(
                    'user_id',
                    $targetUser->id
                )->first();

            $data['targetUser'] =
                $targetUser;

            $data['targetAgent'] =
                $agent;

            $data['managedTeams'] =
                Team::where(
                    'manager_user_id',
                    $targetUser->id
                )->get();

            $titles['change-role'] =
                '🔑 Change Role — ' . $targetUser->name;
        }

        return response()
            ->view(
                'modals.' . $name,
                $data
            )
            ->header(
                'X-Modal-Title',
                rawurlencode($titles[$name])
            );
    }

    /* ============================================================
       SETTINGS MODAL
       ============================================================ */

    private function showSettingsModal(Request $request)
    {
        if (! $this->access->can('settings.manage')) {
            abort(403);
        }

        $type = $request->input('type');
        $id = $request->input('id');

        if (in_array($type, ['statuses', 'activity-types', 'action-types', 'outcomes'], true)) {
            app(\App\Services\SuperAdminService::class)->requireSuperAdmin();
        }

        $labels = [
            'statuses'       => 'Status',
            'activity-types' => 'Activity Type',
            'action-types'   => 'Follow-up Action',
            'outcomes'       => 'Call Outcome',
            'sources'        => 'Lead Source',
        ];

        if (! isset($labels[$type])) {
            abort(404);
        }

        $title = $id
            ? 'Edit ' . $labels[$type]
            : 'New ' . $labels[$type];

        $row = null;

        if ($id) {
            $row = match ($type) {
                'statuses' =>
                    LeadStatus::findOrFail($id),

                'activity-types' =>
                    ActivityType::findOrFail($id),

                'outcomes' =>
                    CallOutcome::findOrFail($id),

                'action-types' =>
                    FollowupActionType::findOrFail($id),

                'sources' =>
                    LeadSource::findOrFail($id),
            };
        }

        $statuses =
            LeadStatus::ordered()->get();

        $actions =
            FollowupActionType::ordered()->get();

        $activityTypes =
            ActivityType::ordered()->get();

        return response()
            ->view(
                'modals.settings-form',
                compact(
                    'type',
                    'row',
                    'statuses',
                    'actions',
                    'activityTypes'
                )
            )
            ->header(
                'X-Modal-Title',
                rawurlencode($title)
            );
    }

    /* ============================================================
       RESOLVE FOLLOW-UP
       ============================================================ */

    /**
     * Resolve a follow-up from the explicit ID first, then fall back
     * to the lead's current pending follow-up.
     *
     * The resolved follow-up is also checked against the current
     * user's lead visibility scope.
     */
    private function resolveFollowup(
        Request $request
    ): ?Followup {
        $followupId =
            $request->input('followup_id')
            ?? $request->input('followup')
            ?? $request->query('followup_id')
            ?? $request->query('followup-id')
            ?? $request->query('followup');

        if (
            $followupId !== null
            && $followupId !== ''
        ) {
            $followup = Followup::with([
                'lead.project',
                'lead.agent.user',
            ])->find((int) $followupId);

            if ($followup) {
                $lead = $followup->lead;

                if (
                    $lead
                    && $this->access->canViewLead($lead)
                ) {
                    return $followup;
                }

                return null;
            }
        }

        $leadId =
            $request->input('lead_id')
            ?? $request->input('lead')
            ?? $request->query('lead_id')
            ?? $request->query('lead');

        if (
            $leadId === null
            || $leadId === ''
        ) {
            return null;
        }

        $lead = Lead::find((int) $leadId);

        if (
            ! $lead
            || ! $this->access->canViewLead($lead)
        ) {
            return null;
        }

        $query = Followup::with([
            'lead.project',
            'lead.agent.user',
        ])
            ->where(
                'lead_id',
                (int) $leadId
            )
            ->where(
                'status',
                'pending'
            );

        /*
         * Agents should resolve against their own work queue first.
         */
        if (session('user_role') === 'agent') {
            $agentId = Agent::where(
                'user_id',
                session('user_id')
            )->value('id');

            $query->where(
                'agent_id',
                $agentId ?: -1
            );
        }

        return $query
            ->orderByRaw(
                'CASE WHEN scheduled_for <= ? THEN 0 ELSE 1 END',
                [now()]
            )
            ->orderBy('scheduled_for')
            ->first();
    }

    /* ============================================================
       HANDOVER OPTIONS
       ============================================================ */

    /**
     * Lightweight routing lookup for internal handover.
     *
     * Only agents/teams inside the current user's delegated scope
     * are returned when the user has a delegated profile.
     */
    public function handoverOptions(Request $request)
    {
        if (! session('user_id')) {
            return response()->json([
                'ok' => false,
                'message' =>
                    'Session expired. Please login again.',
            ], 401);
        }

        $followup =
            $this->resolveFollowup($request);

        if (! $followup) {
            return response()->json([
                'ok' => true,
                'available' => false,
                'reasons' => [
                    'No pending follow-up is available for this lead to hand over.',
                ],
                'agents' => [],
                'projects_by_agent' => [],
                'teams_by_agent_project' => [],
            ]);
        }

        if ($followup->status !== 'pending') {
            return response()->json([
                'ok' => false,
                'message' =>
                    'This follow-up has already been completed.',
            ], 422);
        }

        $lead = $followup->lead;

        if (
            ! $lead
            || ! $this->access->canWorkLead($lead)
        ) {
            return response()->json([
                'ok' => false,
                'message' =>
                    'You do not have permission to manage this lead.',
            ], 403);
        }

        $currentProjectId =
            (int) ($lead->project_id ?? 0);

        $reasons = [];

        if (! $currentProjectId) {
            $reasons[] =
                'This lead has no current project assigned.';
        }

        if (! $lead) {
            $reasons[] =
                'The lead attached to this follow-up could not be found.';
        }

        if ($reasons) {
            return response()->json([
                'ok' => true,
                'available' => false,
                'reasons' =>
                    array_values(
                        array_unique($reasons)
                    ),
                'agents' => [],
                'projects_by_agent' => [],
                'teams_by_agent_project' => [],
            ]);
        }

        /* ========================================================
           DELEGATED SCOPE
           ======================================================== */

        $delegatedProfile =
            $this->access->hasDelegatedProfile();

        $allowedAgentIds =
            $delegatedProfile
                ? $this->access->visibleAgentIds()
                : [];

        $allowedTeamIds =
            $delegatedProfile
                ? $this->access->visibleTeamIds()
                : [];

        /* ========================================================
           DIRECT PROJECT → AGENT ROUTES
           ======================================================== */

        $rows = DB::query()
            ->from('project_agent as pa')
            ->join(
                'projects as p',
                'p.id',
                '=',
                'pa.project_id'
            )
            ->join(
                'agents as a',
                'a.id',
                '=',
                'pa.agent_id'
            )
            ->join(
                'users as u',
                'u.id',
                '=',
                'a.user_id'
            )
            ->where(
                'pa.is_active',
                1
            )
            ->where(
                'a.status',
                'active'
            )
            ->where(
                'u.role',
                '!=',
                'admin'
            )
            ->where(
                'p.status',
                'active'
            )
            ->where(
                'pa.project_id',
                '!=',
                $currentProjectId
            )
            ->when(
                $delegatedProfile,
                function ($q) use ($allowedAgentIds) {
                    $q->whereIn(
                        'pa.agent_id',
                        ! empty($allowedAgentIds)
                            ? $allowedAgentIds
                            : [-1]
                    );
                }
            )
            ->select(
                'pa.agent_id',
                'pa.project_id',
                DB::raw(
                    'NULL as team_id'
                ),
                DB::raw(
                    'NULL as team_name'
                ),
                'p.name as project_name',
                'u.name as agent_name',
                'u.role'
            )
            ->get();

        /* ========================================================
           PROJECT → TEAM → AGENT ROUTES
           ======================================================== */

        $teamRows = DB::query()
            ->from('project_team as pt')
            ->join(
                'projects as p',
                'p.id',
                '=',
                'pt.project_id'
            )
            ->join(
                'team_members as tm',
                function ($join) {
                    $join->on(
                        'tm.team_id',
                        '=',
                        'pt.team_id'
                    )->where(
                        'tm.is_active',
                        1
                    );
                }
            )
            ->join(
                'agents as a',
                'a.id',
                '=',
                'tm.agent_id'
            )
            ->join(
                'users as u',
                'u.id',
                '=',
                'a.user_id'
            )
            ->where(
                'pt.is_active',
                1
            )
            ->where(
                'a.status',
                'active'
            )
            ->where(
                'u.role',
                '!=',
                'admin'
            )
            ->where(
                'p.status',
                'active'
            )
            ->where(
                'pt.project_id',
                '!=',
                $currentProjectId
            )
            ->when(
                $delegatedProfile,
                function ($q) use ($allowedTeamIds) {
                    $q->whereIn(
                        'pt.team_id',
                        ! empty($allowedTeamIds)
                            ? $allowedTeamIds
                            : [-1]
                    );
                }
            )
            ->join(
                'teams as t',
                't.id',
                '=',
                'pt.team_id'
            )
            ->select(
                'tm.agent_id',
                'pt.project_id',
                'pt.team_id',
                't.name as team_name',
                'p.name as project_name',
                'u.name as agent_name',
                'u.role'
            )
            ->get();

        /* ========================================================
           BUILD RESPONSE
           ======================================================== */

        $agents = [];
        $projectsByAgent = [];
        $teamsByAgentProject = [];
        $seen = [];

        foreach (
            $rows->concat($teamRows)
            as $row
        ) {
            $agentId =
                (int) $row->agent_id;

            $projectId =
                (int) $row->project_id;

            $pairKey =
                $agentId . ':' . $projectId;

            $teamId =
                $row->team_id !== null
                    ? (int) $row->team_id
                    : null;

            $teamKey =
                $pairKey . ':' . ($teamId ?? 0);

            if (! isset($agents[$agentId])) {
                $agents[$agentId] = [
                    'id' =>
                        $agentId,
                    'name' =>
                        $row->agent_name,
                    'role' =>
                        $row->role,
                ];

                $projectsByAgent[$agentId] = [];
            }

            if (! isset($seen[$pairKey])) {
                $projectsByAgent[$agentId][] = [
                    'id' =>
                        $projectId,
                    'name' =>
                        $row->project_name,
                ];

                $seen[$pairKey] = true;
            }

            if (
                $teamId
                && ! isset(
                    $teamsByAgentProject[$agentId][$projectId]
                )
            ) {
                $teamsByAgentProject[$agentId][$projectId] = [];
            }

            if (
                $teamId
                && ! isset($seen[$teamKey])
            ) {
                $teamsByAgentProject[
                    $agentId
                ][$projectId][] = [
                    'id' =>
                        $teamId,
                    'name' =>
                        $row->team_name,
                ];

                $seen[$teamKey] = true;
            }
        }

        $agents = array_values($agents);

        usort(
            $agents,
            fn ($a, $b) =>
                strcasecmp(
                    $a['name'] ?? '',
                    $b['name'] ?? ''
                )
        );

        foreach (
            $projectsByAgent as &$projectList
        ) {
            usort(
                $projectList,
                fn ($a, $b) =>
                    strcasecmp(
                        $a['name'] ?? '',
                        $b['name'] ?? ''
                    )
            );
        }

        unset($projectList);

        if (! $agents) {
            $reasons[] =
                'No active team member is currently routed to another active project.';
        }

        return response()->json([
            'ok' =>
                true,

            'available' =>
                ! empty($agents),

            'reasons' =>
                array_values(
                    array_unique($reasons)
                ),

            'agents' =>
                $agents,

            'projects_by_agent' =>
                $projectsByAgent,

            'teams_by_agent_project' =>
                $teamsByAgentProject,
        ]);
    }

    /* ============================================================
       ACCESSIBLE LEAD IDS
       ============================================================ */

    /**
     * Return lead IDs belonging to the current user's effective
     * agent scope.
     *
     * This is intentionally based on active lead_agents assignments,
     * because shared leads are part of the user's permitted scope.
     */
    private function accessibleLeadIds(): array
    {
        $agentIds =
            $this->access->visibleAgentIds();

        if (empty($agentIds)) {
            return [];
        }

        return LeadAgent::query()
            ->whereIn(
                'agent_id',
                $agentIds
            )
            ->where(
                'is_active',
                true
            )
            ->pluck('lead_id')
            ->map(
                fn ($id) => (int) $id
            )
            ->unique()
            ->values()
            ->all();
    }
}
