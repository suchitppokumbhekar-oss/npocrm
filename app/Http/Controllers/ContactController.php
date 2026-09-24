<?php

namespace App\Http\Controllers;

use App\Models\Config\CallOutcome;
use App\Models\Agent;
use App\Models\Contact;
use App\Models\ContactProjectAssignment;
use App\Models\Project;
use App\Models\TeamMember;
use App\Services\ContactService;
use App\Services\ContactDuplicateCleanupService;
use App\Services\AccessService;
use App\Services\TeamService;
use App\Services\ContactFollowupService;
use App\Services\SettingsService;
use App\Models\ContactFollowup;
use App\Models\ContactCallSession;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ContactController extends Controller
{
    public function __construct(
        private ContactService $contacts,
        private ContactDuplicateCleanupService $duplicates,
        private TeamService $teams,
        private AccessService $access,
        private ContactFollowupService $contactFollowups,
        private SettingsService $settings,
    ) {}

        private function requireAccess(): void
    {
        if (! app(\App\Services\AccessService::class)->canAccessContacts()) {
            abort(404);
        }
    }

    /** Agent IDs visible to the current user for contact assignment. */
    private function visibleAgentIds(): array
    {
        return array_map('intval', $this->access->visibleAgentIds());
    }

    /** Projects the current user may use as a contact pitch context. */
    private function visiblePitchProjects()
    {
        $userId = (int) session('user_id');
        $role = session('user_role');
        if ($this->access->isUnrestrictedAdmin($userId)) {
            return Project::orderBy('name')->get(['id', 'name']);
        }

        $agentIds = $this->access->visibleAgentIds();
        $teamIds = $this->access->visibleTeamIds($userId);

        // Agents inherit pitch-project visibility from their active team memberships
        // as well as direct project-agent routes.
        if ($role === 'agent' && $agentIds) {
            $teamIds = array_values(array_unique(array_merge(
                $teamIds,
                TeamMember::whereIn('agent_id', $agentIds)
                    ->where('is_active', true)
                    ->pluck('team_id')
                    ->map(fn ($id) => (int) $id)
                    ->all()
            )));
        }

        return Project::query()
            ->where(function ($q) use ($agentIds, $teamIds) {
                if ($agentIds) {
                    $q->whereExists(function ($x) use ($agentIds) {
                        $x->selectRaw('1')->from('project_agent')
                            ->whereColumn('project_agent.project_id', 'projects.id')
                            ->whereIn('project_agent.agent_id', $agentIds)
                            ->where('project_agent.is_active', 1);
                    });
                }
                if ($teamIds) {
                    $q->orWhereExists(function ($x) use ($teamIds) {
                        $x->selectRaw('1')->from('project_team')
                            ->whereColumn('project_team.project_id', 'projects.id')
                            ->whereIn('project_team.team_id', $teamIds)
                            ->where('project_team.is_active', 1);
                    });
                }
            })
            ->orderBy('name')->get(['id', 'name']);
    }

    private function canUsePitchProject(int $projectId): bool
    {
        return $this->visiblePitchProjects()->contains('id', $projectId);
    }

    /** Projects visible to the current user and eligible for one selected caller. */
    private function assignableProjectsForAgent(int $agentId)
    {
        if (! in_array($agentId, $this->visibleAgentIds(), true)) {
            abort(403, 'That caller is outside your scope.');
        }

        return $this->visiblePitchProjects()
            ->filter(fn ($project) => in_array($agentId, $this->pitchEligibleAgentIds((int) $project->id), true))
            ->values();
    }

    /** Agents who are actually eligible to receive a Contact for this pitch project. */
    private function pitchEligibleAgentIds(int $projectId): array
    {
        $project = Project::find($projectId);
        return $project ? array_map('intval', $project->eligibleAgentIds()) : [];
    }

    /** Apply the same list visibility rules used by the Contacts index. */
    private function filteredQuery(Request $request)
    {
        $q = trim((string) $request->input('q', ''));
        $status = $request->input('status', '');
        $source = $request->input('source', '');
        $projectId = $request->input('filter_project_id', $request->input('project_id'));
        $agentId = $request->input('filter_agent_id', $request->input('agent_id'));

        $query = Contact::query()->orderByDesc('id');
        $role = session('user_role');

        if ($role === 'agent') {
            $agentIds = $this->visibleAgentIds();
            $query->whereIn('assigned_to_agent_id', $agentIds ?: [-1]);

        } elseif ($role === 'team_manager') {
            // Team Managers see the Contacts owned by their own team only.
            // This is a server-side scope boundary, not a UI filter. The
            // optional mine_only flag is therefore no longer allowed to
            // broaden visibility; it may only further narrow the same scope.
            $agentIds = $this->visibleAgentIds();
            $query->whereIn('assigned_to_agent_id', $agentIds ?: [-1]);

            if ($request->boolean('mine_only')) {
                $selfAgentId = Agent::where('user_id', session('user_id'))->value('id');
                $query->where('assigned_to_agent_id', $selfAgentId ?: -1);
            }
        } elseif ($role === 'admin' && ! $this->access->isUnrestrictedAdmin((int) session('user_id'))) {
            // Delegated Admins are server-side scoped exactly like their
            // effective agent visibility. Never let the Contacts index/search
            // become broader than canViewContact(). Unassigned Contacts remain
            // visible because canViewContact() treats them as unowned.
            $agentIds = $this->visibleAgentIds();
            $query->where(function ($scope) use ($agentIds) {
                $scope->whereNull('assigned_to_agent_id');
                if ($agentIds) {
                    $scope->orWhereIn('assigned_to_agent_id', $agentIds);
                }
            });
        }

        if ($q !== '') {
            $phoneLikes = array_map(fn ($v) => '%' . $v . '%', phone_search_variants($q));
            $query->where(function ($qq) use ($q, $phoneLikes) {
                $qq->where('name', 'like', "%{$q}%")
                    ->orWhere('email', 'like', "%{$q}%");
                foreach ($phoneLikes as $phoneLike) {
                    $qq->orWhere('phone', 'like', $phoneLike);
                }
            });
        }
        if ($status) $query->where('status', $status);
        if ($projectId) $query->where('project_id', $projectId);
        if ($agentId) $query->where('assigned_to_agent_id', $agentId);
        if ($source) $query->where('source', $source);

        return $query;
    }

    /* ============================================================ */
    public function index(Request $request)
    {
        $this->requireAccess();

        $q          = trim((string) $request->input('q', ''));
        $status     = $request->input('status', '');
        $projectId  = $request->input('project_id');
        $agentId    = $request->input('agent_id');
        $source     = $request->input('source', '');
        $contactView = $request->input('view', 'active');
        $sort        = (string) $request->input('sort', 'id');
        $direction   = strtolower((string) $request->input('dir', 'desc')) === 'asc' ? 'asc' : 'desc';

        $query = Contact::with(['project', 'agent.user', 'activeProjectAssignment.project', 'activeProjectAssignment.agent.user']);

        // Keep sorting server-side and whitelist every sortable column.
        // This makes the table genuinely sortable without allowing arbitrary
        // SQL/order expressions through the query string.
        switch ($sort) {
            case 'name':
                $query->orderBy('name', $direction);
                break;
            case 'phone':
                $query->orderBy('phone', $direction);
                break;
            case 'project':
                $query->orderBy(
                    Project::select('name')->whereColumn('projects.id', 'contacts.project_id'),
                    $direction
                );
                break;
            case 'assigned':
                $query->orderBy(
                    Agent::query()
                        ->join('users', 'users.id', '=', 'agents.user_id')
                        ->select('users.name')
                        ->whereColumn('agents.id', 'contacts.assigned_to_agent_id'),
                    $direction
                );
                break;
            case 'attempts':
                $query->orderBy('attempts', $direction);
                break;
            case 'connected':
                $query->orderBy('connected_count', $direction);
                break;
            case 'last_outcome':
                $query->orderBy('last_outcome_key', $direction);
                break;
            case 'status':
                $query->orderBy('status', $direction);
                break;
            case 'id':
            default:
                $sort = 'id';
                $query->orderBy('id', $direction);
                break;
        }
        $query->orderBy('id', 'desc');

        // Super Admin/unrestricted Admin sees all; Team Manager is team-scoped; Agent is self-scoped.
        $role = session('user_role');
        if ($role === 'agent') {
            $agentIds = $this->visibleAgentIds();
            $query->whereIn('assigned_to_agent_id', $agentIds ?: [-1]);

            // Caller work is intentionally clean: converted Contacts leave the
            // active work list completely. They are available only through the
            // explicit Converted to Leads view.
            if ($contactView === 'converted') {
                $query->whereNotNull('promoted_to_lead_id');
            } else {
                $contactView = 'active';
                $status = '';
                $query->whereNull('promoted_to_lead_id')
                    ->whereNotIn('status', ['dnc', 'invalid', 'converted']);
            }
        } elseif ($role === 'team_manager') {
            // Team Managers see the Contacts owned by their own team only.
            // This is a server-side scope boundary, not a UI filter. The
            // optional mine_only flag is therefore no longer allowed to
            // broaden visibility; it may only further narrow the same scope.
            $agentIds = $this->visibleAgentIds();
            $query->whereIn('assigned_to_agent_id', $agentIds ?: [-1]);

            if ($request->boolean('mine_only')) {
                $selfAgentId = Agent::where('user_id', session('user_id'))->value('id');
                $query->where('assigned_to_agent_id', $selfAgentId ?: -1);
            }
        } elseif ($role === 'admin' && ! $this->access->isUnrestrictedAdmin((int) session('user_id'))) {
            // Delegated Admins are server-side scoped exactly like their
            // effective agent visibility. Never let the Contacts index/search
            // become broader than canViewContact(). Unassigned Contacts remain
            // visible because canViewContact() treats them as unowned.
            $agentIds = $this->visibleAgentIds();
            $query->where(function ($scope) use ($agentIds) {
                $scope->whereNull('assigned_to_agent_id');
                if ($agentIds) {
                    $scope->orWhereIn('assigned_to_agent_id', $agentIds);
                }
            });
        }

        if ($q !== '') {
            $query->where(function ($qq) use ($q) {
                $qq->where('name', 'like', "%{$q}%")
                   ->orWhere('phone', 'like', "%{$q}%")
                   ->orWhere('email', 'like', "%{$q}%");
            });
        }
        if ($status)    $query->where('status', $status);
        if ($projectId) $query->where('project_id', $projectId);
        if ($agentId)   $query->where('assigned_to_agent_id', $agentId);
        if ($source)    $query->where('source', $source);

        $contacts = $query->paginate(50, ['*'], 'contacts_page')
            ->appends($request->query());

        // Contact stores the stable outcome key. The list must show the
        // configured business label, not the internal key.
        $outcomeKeys = $contacts->getCollection()
            ->pluck('last_outcome_key')
            ->filter()
            ->unique()
            ->values();
        $outcomeLabels = $outcomeKeys->isEmpty()
            ? collect()
            : CallOutcome::whereIn('key', $outcomeKeys)->pluck('label', 'key');
        $contacts->getCollection()->each(function ($contact) use ($outcomeLabels) {
            $contact->last_outcome_label = $outcomeLabels->get($contact->last_outcome_key);
        });

        $projects = $this->visiblePitchProjects();
        $agents   = Agent::with('user')->whereIn('id', $this->access->visibleAgentIds())
            ->orderBy('id')->get();

        $callerWorkCount = 0;
        $callerMissedCount = 0;
        $callerConvertedCount = 0;
        if (session('user_role') === 'agent') {
            $myAgentId = Agent::where('user_id', session('user_id'))->value('id');
            if ($myAgentId) {
                $callerWorkCount = app(ContactFollowupService::class)->dueForAgent((int) $myAgentId, 200)->count();
                $callerMissedCount = ContactCallSession::where('agent_id', (int) $myAgentId)
                    ->whereNull('completed_at')
                    ->where('started_at', '<=', now()->subMinutes(2))
                    ->whereHas('contact', fn ($cq) => $cq->whereNotIn('status', ['dnc','invalid','converted'])->whereNull('promoted_to_lead_id'))
                    ->count();
                $callerConvertedCount = Contact::where('assigned_to_agent_id', (int) $myAgentId)
                    ->whereNotNull('promoted_to_lead_id')
                    ->count();
            }
        }

        return view('contacts.index', compact(
            'contacts', 'projects', 'agents',
            'q', 'status', 'projectId', 'agentId', 'source', 'contactView', 'sort', 'direction',
            'callerWorkCount', 'callerMissedCount', 'callerConvertedCount'
        ));
    }

    /* ============================================================ */
    public function show(int $id)
    {
        $this->requireAccess();

        $contact = Contact::with(['project', 'agent.user', 'projectAssignments.project', 'projectAssignments.agent.user', 'projectAssignments.assignedBy', 'calls.agent.user', 'lead'])
            ->findOrFail($id);

        if (! $this->access->canViewContact($contact)) {
            abort(403, 'You are not allowed to view this contact.');
        }

        if (session('user_role') === 'agent') {
            $myAgentId = Agent::where('user_id', session('user_id'))->value('id');
            $contact->setRelation('calls', $contact->calls->where('agent_id', $myAgentId)->values());
        }

        // A contact can outlive its promoted lead's current access scope.
        // Never let the contact page become a lead-information backdoor.
        if ($contact->lead && ! $this->access->canViewLead($contact->lead)) {
            $contact->setRelation('lead', null);
        }

        // Self-heal the operational work projection before rendering. Historical
        // calls/actions are never rewritten; only stale/missing pending work is
        // reconciled from the latest recorded outcome.
        $this->contactFollowups->reconcileContactWork($contact);
        $freshOutcome = $contact->fresh(['project', 'agent.user', 'lead']);
        if ($freshOutcome) {
            $contact->last_outcome_key = $freshOutcome->last_outcome_key;
            $contact->status = $freshOutcome->status;
            $contact->site_visit_scheduled_at = $freshOutcome->site_visit_scheduled_at;
        }

        $projects = $this->visiblePitchProjects();
        $agents = Agent::with('user')->whereIn('id', $this->access->visibleAgentIds())->orderBy('id')->get();

        // The Contact page is the caller's operating console. Keep the current
        // next action visible here so the caller never has to infer what to do
        // from historical calls alone. Calls still use the dialer; non-call
        // actions are completed from the Contact page itself.
        $workFollowups = ContactFollowup::query()
            ->where('contact_id', $contact->id)
            ->where('status', 'pending')
            ->orderBy('scheduled_for')
            ->get();
        $workOutcomes = collect();
        if ($workFollowups->isNotEmpty()) {
            $actionKey = $workFollowups->first()->action_type;
            $workOutcomes = app(\App\Services\WorkflowPresentationService::class)
                ->presentOutcomes($this->settings->callOutcomesForContactWork($contact, $actionKey), (int) session('user_id'));
        }
        $pitchAgentIdsByProject = [];
        foreach ($projects as $project) {
            $pitchAgentIdsByProject[$project->id] = $project->eligibleAgentIds();
        }

        return view('contacts.show', [
            'contact' => $contact,
            'projects' => $projects,
            'agents' => $agents,
            'pitchAgentIdsByProject' => $pitchAgentIdsByProject,
            'workFollowups' => $workFollowups,
            'workOutcomes' => $workOutcomes,
        ]);
    }

    /* ============================================================ */
    public function bulkAssignmentOptions(Request $request)
    {
        $this->requireAccess();

        $validated = $request->validate([
            'agent_id' => 'required|integer|exists:agents,id',
        ]);

        $projects = $this->assignableProjectsForAgent((int) $validated['agent_id']);

        return response()->json([
            'projects' => $projects->map(fn ($project) => [
                'id' => (int) $project->id,
                'name' => $project->name,
            ])->values(),
        ]);
    }

    /* ============================================================ */
    public function assign(Request $request, int $id)
    {
        $this->requireAccess();

        $validated = $request->validate([
            'agent_id'   => 'nullable|integer|exists:agents,id',
            'project_id' => 'required|integer|exists:projects,id',
        ]);

        $contact = Contact::findOrFail($id);
        if (! $this->access->canViewContact($contact)) {
            abort(403, 'You are not allowed to modify this contact.');
        }
        if (! $this->access->canAssignContactTo($contact, $validated['agent_id'] ? (int) $validated['agent_id'] : null)) {
            abort(403, 'That assignee is outside your scope.');
        }
        $projectId = (int) $validated['project_id'];
        if (! $this->canUsePitchProject($projectId)) {
            abort(403, 'That pitch project is outside your scope.');
        }

        $agentId = $validated['agent_id'] ? (int) $validated['agent_id'] : null;
        if ($agentId !== null && ! in_array($agentId, $this->pitchEligibleAgentIds($projectId), true)) {
            abort(403, 'That agent is not authorized for this pitch project.');
        }
        if ($contact->status === 'dnc') {
            return back()->with('error', 'DND contacts cannot be circulated.');
        }

        $this->contacts->assignPitch(
            $contact,
            $projectId,
            $agentId,
            (int) session('user_id')
        );

        return back()->with('success', '✅ Contact assigned for the selected pitch project.');
    }

    /* ============================================================ */
    public function promote(Request $request, int $id)
    {
        $this->requireAccess();

        $contact = Contact::findOrFail($id);

        if (! $this->access->canViewContact($contact)) {
            abort(403, 'You are not allowed to promote this contact.');
        }

        if ($contact->isPromoted()) {
            return redirect('/leads/' . $contact->promoted_to_lead_id)
                ->with('success', 'Already a lead.');
        }

        $validated = $request->validate([
            'project_id' => 'nullable|integer|exists:projects,id',
        ]);

        // By default a Lead inherits the Contact's current Pitch Project.
        // A different project is selected only when the customer gives a different requirement.
        $leadProjectId = (int) ($validated['project_id'] ?? 0);
        if ($leadProjectId <= 0) {
            $leadProjectId = (int) ($contact->project_id ?? 0);
        }
        if ($leadProjectId <= 0) {
            return back()->withErrors(['project_id' => 'This contact has no Pitch Project. Assign a Pitch Project before creating the Lead.']);
        }
        if (! $this->canUsePitchProject($leadProjectId)) {
            abort(403, 'That project is outside your scope.');
        }

        $lead = $this->contacts->promoteToLead($contact, (int) session('user_id'), $leadProjectId);

        return redirect('/leads/' . $lead->id)
            ->with('success', '🎯 Promoted to lead!');
    }


    /* ============================================================ */
    public function bulk(Request $request)
    {
        $this->requireAccess();

        $validated = $request->validate([
            'action' => 'required|in:assign_project,assign_agent,clear_assignment',
            'contact_ids' => 'array|max:2000',
            'contact_ids.*' => 'integer|exists:contacts,id',
            'select_all_matching' => 'nullable|boolean',
            'project_id' => 'nullable|integer|exists:projects,id',
            'agent_id' => 'nullable|integer|exists:agents,id',
            'q' => 'nullable|string|max:255',
            'status' => 'nullable|string|max:50',
            'filter_project_id' => 'nullable|integer',
            'filter_agent_id' => 'nullable|integer',
        ]);

        $action = $validated['action'];
        $selectAll = (bool) ($validated['select_all_matching'] ?? false);
        $ids = array_values(array_unique(array_map('intval', $validated['contact_ids'] ?? [])));

        if (! $selectAll && ! $ids) {
            return back()->withErrors(['bulk' => 'Select at least one contact.']);
        }

        $projectId = isset($validated['project_id']) && $validated['project_id'] !== null
            ? (int) $validated['project_id'] : null;
        $agentId = isset($validated['agent_id']) && $validated['agent_id'] !== null
            ? (int) $validated['agent_id'] : null;

        if ($action === 'assign_project') {
            if ($projectId === null || ! $this->canUsePitchProject($projectId)) {
                return back()->withErrors(['project_id' => 'Select a pitch project within your scope.']);
            }
            if ($agentId !== null) {
                if (! in_array($agentId, $this->access->visibleAgentIds(), true)) {
                    return back()->withErrors(['agent_id' => 'That caller is outside your scope.']);
                }
                if (! in_array($agentId, $this->pitchEligibleAgentIds($projectId), true)) {
                    return back()->withErrors(['agent_id' => 'That caller is not authorized for the selected pitch project.']);
                }
            }
        }

        if ($action === 'assign_agent') {
            if ($agentId === null || ! in_array($agentId, $this->access->visibleAgentIds(), true)) {
                return back()->withErrors(['agent_id' => 'Select a caller within your scope.']);
            }
        }

        $query = $selectAll ? $this->filteredQuery($request) : Contact::query()->whereIn('id', $ids);
        $processed = 0;
        $changed = 0;
        $skipped = 0;
        $reasons = [];

        $query->chunkById(200, function ($contacts) use (&$processed, &$changed, &$skipped, &$reasons, $action, $projectId, $agentId) {
            foreach ($contacts as $contact) {
                $processed++;

                if (! $this->access->canViewContact($contact)) {
                    $skipped++;
                    $reasons[] = "#{$contact->id} {$contact->name}: outside your contact scope.";
                    continue;
                }
                if ($contact->status === 'dnc' && $action !== 'clear_assignment') {
                    $skipped++;
                    $reasons[] = "#{$contact->id} {$contact->name}: DND contacts cannot be circulated.";
                    continue;
                }

                try {
                    if ($action === 'assign_project') {
                        if (! $this->access->canAssignContactTo($contact, $agentId)) {
                            throw new \RuntimeException('target caller is outside your scope');
                        }
                        $this->contacts->assignPitch($contact, $projectId, $agentId, (int) session('user_id'));
                        $changed++;
                    } elseif ($action === 'assign_agent') {
                        if (! $contact->project_id) {
                            throw new \RuntimeException('has no Pitch Project');
                        }
                        if (! in_array($agentId, $this->pitchEligibleAgentIds((int) $contact->project_id), true)) {
                            throw new \RuntimeException('selected caller is not authorized for this contact\'s Pitch Project');
                        }
                        if (! $this->access->canAssignContactTo($contact, $agentId)) {
                            throw new \RuntimeException('target caller is outside your scope');
                        }
                        $this->contacts->assignPitch($contact, (int) $contact->project_id, $agentId, (int) session('user_id'));
                        $changed++;
                    } elseif ($action === 'clear_assignment') {
                        if (! $contact->project_id && ! $contact->assigned_to_agent_id) {
                            continue;
                        }
                        $this->contacts->clearPitchAssignment($contact, (int) session('user_id'));
                        $changed++;
                    }
                } catch (\Throwable $e) {
                    $skipped++;
                    $reasons[] = "#{$contact->id} {$contact->name}: {$e->getMessage()}";
                }
            }
        });

        $message = "Bulk action complete: {$changed} changed, {$skipped} skipped out of {$processed} processed.";
        if ($reasons) {
            $message .= ' ' . implode(' | ', array_slice($reasons, 0, 8));
            if (count($reasons) > 8) $message .= ' | +' . (count($reasons) - 8) . ' more.';
        }

        return back()->with('success', $message);
    }

    /* ============================================================ */
    public function duplicateAudit()
    {
        if (session('user_role') !== 'admin' || ! app(\App\Services\SuperAdminService::class)->isSuperAdmin()) {
            abort(403, 'Super Admin only.');
        }

        $groups = $this->duplicates->duplicateGroups();
        return view('admin/contact-duplicate-audit', compact('groups'));
    }

    /* ============================================================ */
    public function duplicateMerge(Request $request)
    {
        if (session('user_role') !== 'admin' || ! app(\App\Services\SuperAdminService::class)->isSuperAdmin()) {
            abort(403, 'Super Admin only.');
        }

        $validated = $request->validate([
            'phone' => 'required|string|max:30',
        ]);

        $result = $this->duplicates->merge((string) $validated['phone']);
        if (! $result['merged']) {
            return back()->withErrors(['phone' => $result['reason']]);
        }

        $removed = implode(', #', $result['removed_ids']);
        return back()->with('success', "Duplicate cleanup complete. Kept Contact #{$result['survivor_id']}; removed Contact(s) #{$removed}. Calls and pitch-project assignment history were preserved.");
    }

    /* ============================================================ */
    public function assignmentAudit()
    {
        if (session('user_role') !== 'admin' || ! app(\App\Services\SuperAdminService::class)->isSuperAdmin()) {
            abort(403, 'Super Admin only.');
        }

        $invalid = [];
        ContactProjectAssignment::with(['contact', 'project', 'agent.user', 'assignedBy'])
            ->where('status', 'active')
            ->orderBy('id')
            ->chunkById(200, function ($assignments) use (&$invalid) {
                foreach ($assignments as $assignment) {
                    if (! $assignment->contact || ! $assignment->project || ! $assignment->assigned_to_agent_id) {
                        continue;
                    }
                    if (! in_array((int) $assignment->assigned_to_agent_id, array_map('intval', $assignment->project->eligibleAgentIds()), true)) {
                        $invalid[] = $assignment;
                    }
                }
            });

        return view('admin/contact-assignment-audit', compact('invalid'));
    }

    /* ============================================================ */
    public function reconcileAssignments(Request $request)
    {
        if (session('user_role') !== 'admin' || ! app(\App\Services\SuperAdminService::class)->isSuperAdmin()) {
            abort(403, 'Super Admin only.');
        }

        $validated = $request->validate([
            'assignment_ids' => 'required|array|max:2000',
            'assignment_ids.*' => 'integer|exists:contact_project_assignments,id',
            'mode' => 'required|in:clear',
        ]);

        $cleared = 0;
        foreach (array_unique(array_map('intval', $validated['assignment_ids'])) as $assignmentId) {
            DB::transaction(function () use ($assignmentId, &$cleared) {
                $assignment = ContactProjectAssignment::lockForUpdate()->find($assignmentId);
                if (! $assignment || $assignment->status !== 'active') return;

                $contact = Contact::lockForUpdate()->find($assignment->contact_id);
                $project = Project::find($assignment->project_id);
                if (! $contact || ! $project || ! $assignment->assigned_to_agent_id) return;

                if (in_array((int) $assignment->assigned_to_agent_id, array_map('intval', $project->eligibleAgentIds()), true)) return;

                $assignment->update(['status' => 'completed', 'completed_at' => now(), 'updated_at' => now()]);
                if ((int) $contact->project_id === (int) $assignment->project_id
                    && (int) $contact->assigned_to_agent_id === (int) $assignment->assigned_to_agent_id) {
                    $contact->update(['project_id' => null, 'assigned_to_agent_id' => null]);
                }
                $cleared++;
            });
        }

        return redirect()->route('admin.contactAssignmentAudit')
            ->with('success', "Reconciliation complete: {$cleared} invalid active assignment(s) cleared. Contact/call/import history was preserved.");
    }
}
