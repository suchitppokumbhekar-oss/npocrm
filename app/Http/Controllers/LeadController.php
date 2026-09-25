<?php

namespace App\Http\Controllers;

use App\Models\Activity;
use App\Models\Agent;
use App\Models\Followup;
use App\Models\Lead;
use App\Models\ManagedFile;
use App\Models\Project;
use App\Models\User;
use App\Services\LeadAssignmentService;
use App\Services\LeadTransferService;
use App\Services\LeadDuplicateGuard;
use App\Services\CustomerService;
use App\Services\BookingControlService;
use App\Services\AccessService;
use App\Services\LeadStatusService;
use App\Services\ManagedDocumentService;
use App\Services\FollowupService;
use App\Services\SettingsService;
use App\Services\TeamService;
use App\Services\WorkflowPresentationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class LeadController extends Controller
{
    public function __construct(
        private LeadAssignmentService $assignment,
        private LeadStatusService $statusService,
        private SettingsService $settings,
        private AccessService $access,
        private FollowupService $followups,
        private BookingControlService $bookingControls,
        private LeadTransferService $leadTransfers,
        private LeadDuplicateGuard $duplicateGuard,
        private WorkflowPresentationService $workflowPresentations,
        private ManagedDocumentService $documents,
    ) {}

    public function show(Request $request, int $id)
    {
        if (! session('user_id')) return redirect('/login');

        // Preserve the user's real navigation context without ever accepting an
        // external/open-redirect destination. Relative CRM paths only.
        $returnTo = (string) $request->query('return_to', '');
        if (
            $returnTo === ''
            || ! str_starts_with($returnTo, '/')
            || str_starts_with($returnTo, '//')
            || preg_match('/^[a-z][a-z0-9+.-]*:/i', $returnTo)
        ) {
            $returnTo = '';
        }

        $lead = Lead::with(['agent.user', 'project', 'activeAgents.user'])->findOrFail($id);

        if (! $this->access->canViewLead($lead)) {
            return redirect('/')->with('error', '🚫 You are not allowed to view this lead.');
        }

        $activities = Activity::with('agent.user')
            ->where('lead_id', $lead->id)
            ->orderByDesc('logged_at')
            ->get();

        // Admin safety correction: if an active lead has most recently been given
        // a standard permanent-closing outcome such as Already bought, expose a
        // one-click correction to Lost. This handles historical interactions that
        // were recorded before the controlled Lost-reason gate was introduced.
        $adminCorrectionLostReasonKey = null;
        $adminCorrectionLostReasonLabel = null;
        if ($this->access->can('leads.status_change') && ! $lead->isLost() && ! $lead->isWon()) {
            $permanentOutcomeKeys = array_column(
                LeadStatusService::lostReasonOptions()["closed"],
                "key"
            );
            $candidate = $activities->first();
            if (! $candidate || ! $candidate->outcome_key || ! in_array($candidate->outcome_key, $permanentOutcomeKeys, true)) {
                $candidate = null;
            }
            if ($candidate) {
                $adminCorrectionLostReasonKey = $candidate->outcome_key;
                $adminCorrectionLostReasonLabel = LeadStatusService::lostReasonLabel($candidate->outcome_key);
            }
        }

        $pendingTasksQuery = Followup::where('lead_id', $lead->id)
            ->where('status', 'pending');

        // Agents must only see follow-up tasks assigned to themselves.
        // Admins/managers retain full lead task visibility.
        if (session('user_role') === 'agent') {
            $sessionAgentId = Agent::where('user_id', session('user_id'))->value('id');
            $pendingTasksQuery->where('agent_id', $sessionAgentId ?: -1);
        }

        $pendingTasks = $pendingTasksQuery
            ->orderBy('scheduled_for', 'asc')
            ->get();

        $projects = Project::active()
            ->visibleTo(session('user_id'), session('user_role'))
            ->orderBy('name')
            ->get();

        $sessionAgentId = Agent::where('user_id', session('user_id'))->value('id');

        // Work-first history mode: when an agent comes from a Do Now card, keep
        // the exact follow-up in focus while the timeline is open.
        $focusedFollowup = null;
        if ($request->boolean('focus_history') || $request->boolean('focus_work')) {
            $focusedFollowupId = (int) $request->query('focus_followup_id', 0);
            if ($focusedFollowupId > 0) {
                $focusedFollowup = $pendingTasks->firstWhere('id', $focusedFollowupId);
                if ($focusedFollowup && $focusedFollowup->scheduled_for && $focusedFollowup->scheduled_for->isFuture()) {
                    $focusedFollowup = null;
                }
            }
        }
        $focusHistory = $request->boolean('focus_history') && (bool) $focusedFollowup;
        $focusWork = $request->boolean('focus_work') && (bool) $focusedFollowup;

        // Completion context is only shown when the IDs in the URL genuinely
        // belong to this lead.  It gives the agent a clear end-state after pressing
        // Done instead of making the new future follow-up look like the same task.
        $completionContext = null;
        if ($request->boolean('task_completed')) {
            $completedFollowupId = (int) $request->query('completed_followup_id', 0);
            $nextFollowupId      = (int) $request->query('next_followup_id', 0);

            $completedFollowup = $completedFollowupId > 0
                ? Followup::where('lead_id', $lead->id)
                    ->where('id', $completedFollowupId)
                    ->where('status', 'done')
                    ->first()
                : null;

            $nextFollowup = $nextFollowupId > 0
                ? Followup::where('lead_id', $lead->id)
                    ->where('id', $nextFollowupId)
                    ->where('status', 'pending')
                    ->first()
                : null;

            if ($completedFollowup) {
                $completedType = $this->settings->actionTypeByKey($completedFollowup->action_type);
                $nextType      = $nextFollowup
                    ? $this->settings->actionTypeByKey($nextFollowup->action_type)
                    : null;

                $completionContext = [
                    'completed_label' => $completedType?->label ?? $completedFollowup->action_type,
                    'next'            => $nextFollowup,
                    'next_label'      => $nextFollowup
                        ? ($nextType?->label ?? $nextFollowup->action_type)
                        : null,
                ];
            }
        }

        // Unified project-workstream context. Every additional project interest is
        // represented by a separate child lead under the root enquiry; no existing
        // workstream is overwritten.
        $rootLead = $lead;
        $rootGuard = 0;
        while ($rootLead->parent_lead_id && $rootGuard < 20) {
            $parent = Lead::with(['project', 'agent.user'])->find($rootLead->parent_lead_id);
            if (! $parent) break;
            $rootLead = $parent;
            $rootGuard++;
        }
        $relatedProjectLeads = Lead::with(['project', 'agent.user'])
            ->where(function ($q) use ($rootLead) {
                $q->where('id', $rootLead->id)->orWhere('parent_lead_id', $rootLead->id);
            })
            ->orderBy('id')
            ->get()
            ->filter(fn (Lead $workstream) => $this->access->canViewLead($workstream))
            ->values();

        $requiresProjectChange = false;
        if (session('user_role') === 'agent' && $sessionAgentId) {
            $isSharedAgent = $lead->assignments()
                ->where('agent_id', $sessionAgentId)
                ->where('is_primary', false)
                ->where('is_active', true)
                ->exists();

            $requiresProjectChange = $isSharedAgent
                && ! $lead->agentHandlesProject($sessionAgentId);
        }

        // Private CRM evidence and approved project collateral linked to this lead.
        $leadDocuments = ManagedFile::query()
            ->with(['uploader', 'links'])
            ->whereHas('links', function ($q) use ($lead) {
                $q->where('entity_type', 'lead')->where('entity_id', $lead->id);
            })
            ->whereNull('removed_at')
            ->orderByDesc('created_at')
            ->get();

        $documentsByVisit = $leadDocuments
            ->filter(fn ($file) => $file->links->contains(fn ($link) => $link->context_type === 'site_visit' && $link->context_id))
            ->groupBy(function ($file) {
                $link = $file->links->first(fn ($item) => $item->context_type === 'site_visit' && $item->context_id);
                return (int) $link->context_id;
            });

        $generalLeadDocuments = $leadDocuments
            ->reject(fn ($file) => $file->links->contains(fn ($link) => in_array($link->context_type, ['site_visit', 'booking'], true)))
            ->values();

        $bookingDocuments = $leadDocuments
            ->filter(fn ($file) => $file->links->contains(fn ($link) => $link->context_type === 'booking'))
            ->values();

        $projectMedia = collect();
        if ($lead->project_id) {
            $projectMedia = ManagedFile::query()
                ->with(['uploader', 'links'])
                ->approvedForSharing()
                ->whereHas('links', function ($q) use ($lead) {
                    $q->where('entity_type', 'project')
                        ->where('entity_id', $lead->project_id)
                        ->where('relationship', 'project_media');
                })
                ->orderBy('document_category')
                ->orderByDesc('version_number')
                ->get();
        }

        $projectShareHistory = \App\Models\ProjectSharePackage::query()
            ->with(['files.file', 'creator'])
            ->where('lead_id', $lead->id)
            ->where('project_id', $lead->project_id)
            ->whereIn('share_status', ['sent', 'not_sent'])
            ->orderByDesc('confirmed_at')
            ->orderByDesc('id')
            ->limit(10)
            ->get();

        $lastSuccessfulProjectShare = $projectShareHistory
            ->first(fn ($package) => $package->share_status === 'sent');
        $bookingControl = $lead->bookingControl;
        $canChangeBooking = $this->access->canChangeBooking($lead);
        $canAddProjectWorkstream = $this->access->canAddProjectWorkstream($lead)
            && session('user_role') === 'agent'
            && $sessionAgentId
            && $lead->isAssignedTo((int) $sessionAgentId);

        // Structured site-visit history. This is intentionally separate from the
        // lead's single visit_scheduled_at field: one lead may have many actual
        // visits, and each visit may cover many projects with independent outcomes.
        $siteVisits = DB::table('site_visits as sv')
            ->leftJoin('users as u', 'u.id', '=', 'sv.recorded_by_user_id')
            ->leftJoin('agents as ag', 'ag.id', '=', 'sv.agent_id')
            ->where('sv.lead_id', $lead->id)
            ->orderByDesc('sv.visit_at')
            ->orderByDesc('sv.id')
            ->select([
                'sv.id', 'sv.visit_at', 'sv.client_attended', 'sv.notes',
                'sv.recorded_by_user_id', 'sv.agent_id',
                DB::raw("COALESCE(u.name, 'Unknown') as recorded_by_name"),
                DB::raw("(SELECT COUNT(*) FROM site_visit_projects svpx WHERE svpx.site_visit_id = sv.id) as project_count"),
            ])
            ->get();

        $siteVisitProjectRows = collect();
        if ($siteVisits->isNotEmpty()) {
            $siteVisitProjectRows = DB::table('site_visit_projects as svp')
                ->join('projects as p', 'p.id', '=', 'svp.project_id')
                ->whereIn('svp.site_visit_id', $siteVisits->pluck('id')->all())
                ->orderBy('svp.id')
                ->select('svp.id', 'svp.site_visit_id', 'svp.project_id', 'svp.outcome_key', 'svp.notes', 'p.name as project_name')
                ->get()
                ->groupBy('site_visit_id');
        }

        $siteVisitOutcomeOptions = collect($this->workflowPresentations->presentSiteVisitOutcomes((int) session("user_id")))
            ->pluck("display_label", "key")
            ->all();

        $siteVisitProjectOptions = $projects;
        $hasConfirmedSiteVisit = (bool) $lead->visit_scheduled_at
            || $activities->contains(fn ($a) => in_array((string) $a->outcome_key, ['site_visit_scheduled', 'visit_scheduled'], true));
        $canRecordSiteVisit = $this->access->canWorkLead($lead) && $hasConfirmedSiteVisit;

        // Preserve the existing lead-management capability for the view.
        // Individual management actions still enforce their own permissions.
        $canManageLead = $this->access->canManageLead($lead);
        $canChangeProject = $this->access->canReassignLead() && $canManageLead;

        // Customer tab is optional. It reuses the existing deterministic
        // intelligence snapshot only to show documented information; it does
        // not make any field mandatory and does not mutate the lead.
        $customerInformationProfile = null;
        if ($this->access->canWorkLead($lead)) {
            $customerInformationProfile = app(\App\Services\TimelineIntelligenceService::class)
                ->analyze($lead)['information_readiness'] ?? [];
        }

        if (session('user_role') === 'agent') {
            $canRecordSiteVisit = $canRecordSiteVisit && $sessionAgentId && $lead->isAssignedTo((int) $sessionAgentId);
        } else {
            $canRecordSiteVisit = $canRecordSiteVisit && in_array(session('user_role'), ['admin', 'team_manager'], true);
        }

        return view('leads.show', compact(
            'lead', 'activities', 'pendingTasks', 'projects', 'requiresProjectChange',
            'completionContext', 'focusedFollowup', 'focusHistory', 'focusWork',
            'adminCorrectionLostReasonKey', 'adminCorrectionLostReasonLabel',
            'bookingControl', 'canChangeBooking',
            'rootLead', 'relatedProjectLeads', 'canAddProjectWorkstream',
            'siteVisits', 'siteVisitProjectRows', 'siteVisitOutcomeOptions',
            'siteVisitProjectOptions', 'canRecordSiteVisit', 'canManageLead', 'canChangeProject', 'returnTo',
            'customerInformationProfile',
            'leadDocuments', 'generalLeadDocuments', 'documentsByVisit', 'bookingDocuments', 'projectMedia',
            'projectShareHistory', 'lastSuccessfulProjectShare'
        ));
    }

    /* ============================================================
       STORE — ROLE-AWARE ASSIGNMENT + FIRST-CONTACT SCHEDULING
       ============================================================ */
    public function store(Request $request)
    {
        if (! session('user_id')) {
            return redirect('/login');
        }

        $validated = $request->validate([
            'customer_name'        => 'required|string|max:255',
            'phone'                => 'required|string|max:20',
            'email'                => 'nullable|email|max:255',
            'source'               => 'required|string|max:255',
            'budget'               => 'nullable|numeric',
            'project_id'           => 'required|exists:projects,id',
            'assign_to_agent_id'   => 'nullable|string',   // 'auto' or an integer id
        ]);

        $validated['phone'] = phone_canonical($validated['phone']);

        // One customer + one project = one enquiry. Protect the manual Add Lead
        // path as well as the automated intake path, including older leads that
        // may not have customer_id populated.
        $existing = $this->duplicateGuard->findExisting(
            (int) $validated['project_id'],
            null,
            $validated['phone'],
            $validated['email'] ?? null,
        );
        if ($existing) {
            return redirect('/leads/' . $existing->id)
                ->with('error', '⚠️ ' . $this->duplicateGuard->message($existing));
        }

        $customer = app(CustomerService::class)->findOrCreateByPhone($validated['phone'], [
            'name' => $validated['customer_name'],
            'email' => $validated['email'] ?? null,
        ]);

        // Re-check after resolving the canonical customer identity.
        $existing = $this->duplicateGuard->findExisting(
            (int) $validated['project_id'],
            $customer?->id,
            $validated['phone'],
            $validated['email'] ?? null,
        );
        if ($existing) {
            return redirect('/leads/' . $existing->id)
                ->with('error', '⚠️ ' . $this->duplicateGuard->message($existing));
        }

        $lead = Lead::create([
            'customer_id'  => $customer?->id,
            'customer_name' => $validated['customer_name'],
            'phone'         => $validated['phone'],
            'email'         => $validated['email'] ?? null,
            'source'        => $validated['source'],
            'budget'        => $validated['budget'] ?? null,
            'project_id'    => $validated['project_id'],
            'status'        => 'new',
        ]);

        $this->applyAssignment($lead, $request->input('assign_to_agent_id'));

        // Schedule the first-contact follow-up for the assigned agent
        $this->scheduleFirstContact($lead);

        return redirect('/')->with('success', '✅ Lead saved! First-contact follow-up scheduled.');
    }

    /**
     * Role-aware assignment:
     *   - Agent:        always self
     *   - Team Manager: self / team member / auto within team
     *   - Admin:        specific agent / auto (any agent)
     */
    private function applyAssignment(Lead $lead, ?string $requested): void
    {
        $userId = session('user_id');
        $role   = session('user_role');
        $user   = User::find($userId);
        $self   = Agent::where('user_id', $userId)->first();

        /* ---------- AGENT ---------- */
        if ($role === 'agent') {
            if ($self) {
                $this->assignment->assignTo($lead, $self);
            } else {
                // No Agent record — fall back to auto within nothing (unassigned)
            }
            return;
        }

        /* ---------- TEAM MANAGER ---------- */
        if ($role === 'team_manager') {
            $teamAgentIds = app(TeamService::class)->agentIdsForManager($userId);

            // "auto" → pick least-loaded among team agents (+ self if present)
            if ($requested === 'auto' || empty($requested)) {
                if ($self && ! in_array($self->id, $teamAgentIds, true)) {
                    $teamAgentIds[] = $self->id;
                }
                $this->assignment->assignLeastLoaded($lead, $teamAgentIds);
                return;
            }

            // Specific agent → must be within the team, or self
            $targetId = (int) $requested;

            if ($self && $targetId === $self->id) {
                $this->assignment->assignTo($lead, $self);
                return;
            }

            if (in_array($targetId, $teamAgentIds, true)) {
                $target = Agent::find($targetId);
                if ($target) {
                    $this->assignment->assignTo($lead, $target);
                }
                return;
            }

            // Fallback: auto within team
            if ($self && ! in_array($self->id, $teamAgentIds, true)) {
                $teamAgentIds[] = $self->id;
            }
            $this->assignment->assignLeastLoaded($lead, $teamAgentIds);
            return;
        }

        /* ---------- ADMIN ---------- */
        if ($requested === 'auto' || empty($requested)) {
            // Auto across all active agents
            $this->assignment->assignLeastLoaded($lead);
            return;
        }

        $target = Agent::find((int) $requested);
        if ($target && $target->status === 'active') {
            $this->assignment->assignTo($lead, $target);
        } else {
            $this->assignment->assignLeastLoaded($lead);
        }
    }

    /**
     * Create the "First Contact" follow-up so a fresh lead never sits idle.
     * Delay configurable via settings.first_contact_delay_minutes (default 15).
     */
    private function scheduleFirstContact(Lead $lead): void
    {
        if (! $lead->agent_id) {
            return; // unassigned — safety net will catch it later
        }

        // Cancel any prior auto-created pending followups (fresh start)
        Followup::where('lead_id', $lead->id)
            ->where('status', 'pending')
            ->where('auto_created', true)
            ->update(['status' => 'cancelled', 'updated_at' => now()]);

        $this->followups->scheduleNewLeadCall($lead);
    }

    /* ============================================================
       UPDATE STATUS (ADMIN ONLY)
       ============================================================ */
    /** Admin-only correction of a Lost lead classification. */
    public function updateLostReason(Request $request)
    {
        if (! $this->access->can('leads.status_change')) {
            return redirect('/')->with('error', '🚫 Only admins can edit a Lost reason.');
        }

        $validated = $request->validate([
            'lead_id'              => 'required|integer|exists:leads,id',
            'lost_reason_key'      => 'required|string|max:80',
            'lost_reason'          => 'nullable|string|max:2000',
            'nurture_enabled'      => 'nullable|boolean',
            'nurture_scheduled_at' => 'nullable|date',
        ]);

        $lead = Lead::findOrFail($validated['lead_id']);

        if (! $this->access->canManageLead($lead)) {
            return redirect('/')->with('error', '🚫 This lead is outside your management scope.');
        }

        try {
            $this->statusService->updateLostReason(
                $lead,
                $validated['lost_reason_key'],
                $validated['lost_reason'] ?? null,
                ! empty($validated['nurture_enabled']),
                ! empty($validated['nurture_enabled']) ? ($validated['nurture_scheduled_at'] ?? null) : null,
            );
        } catch (\DomainException $e) {
            return redirect('/leads/' . $lead->id)->with('error', '🚫 ' . $e->getMessage());
        }

        return redirect('/leads/' . $lead->id)->with('success', '✅ Lost reason updated successfully.');
    }

    /** Admin-only correction for a historical permanent closing outcome that left an active lead open. */
    public function correctToLostFromOutcome(Request $request)
    {
        if (! $this->access->can('leads.status_change')) {
            return back()->with('error', '🚫 Only admins can correct lead classification.');
        }

        $validated = $request->validate([
            'lead_id'         => 'required|integer|exists:leads,id',
            'lost_reason_key' => 'required|string|max:80',
        ]);

        $lead = Lead::findOrFail($validated['lead_id']);

        if (! $this->access->canManageLead($lead)) {
            return back()->with('error', '🚫 This lead is outside your management scope.');
        }

        $reasonLabel = LeadStatusService::lostReasonLabel($validated['lost_reason_key']);
        if (! $reasonLabel) {
            return back()->with('error', '🚫 Invalid Lost reason.');
        }
        if ($lead->isLost()) {
            return redirect('/leads/' . $lead->id)->with('success', '✅ Lead is already Lost. Use ✏️ Edit Lost Reason for classification changes.');
        }
        if ($lead->isWon()) {
            return redirect('/leads/' . $lead->id)->with('error', '🎉 Booked leads cannot be corrected to Lost.');
        }

        try {
            $this->statusService->change(
                $lead,
                'lost',
                'Admin correction from previously recorded closing outcome: ' . $reasonLabel,
                null,
                null,
                true,
                null,
                $validated['lost_reason_key'],
            );
        } catch (\DomainException $e) {
            return redirect('/leads/' . $lead->id)->with('error', '🚫 ' . $e->getMessage());
        }

        return redirect('/leads/' . $lead->id)->with('success', '✅ Lead corrected to Lost: ' . $reasonLabel);
    }

    public function updateStatus(Request $request)
    {
        if (! $this->access->can('leads.status_change')) {
            return redirect('/')->with(
                'error',
                '🚫 Only admins can change status directly. Log an activity with an outcome to advance the lead.'
            );
        }

        $validated = $request->validate([
            'lead_id'               => 'required|integer|exists:leads,id',
            'status'                => 'required|string',
            'lost_reason'           => 'nullable|string|max:2000',
            'lost_reason_key'       => 'nullable|string|max:80',
            'nurture_scheduled_at'  => 'nullable|date',
            'nurture_enabled'       => 'nullable|boolean',
            'visit_scheduled_at'    => 'nullable|date',
            'property_area_sqft'    => 'nullable|numeric|min:0',
            'rate_per_sqft'         => 'nullable|numeric|min:0',
            'booking_amount'        => 'nullable|numeric|min:0',
            'booking_unit'          => 'nullable|string|max:100',
            'booking_payment_mode'  => 'nullable|string|max:50',
            'booking_date'          => 'nullable|date',
            'brokerage_percentage'  => 'nullable|numeric|min:0|max:100',
            'brokerage_amount'      => 'nullable|numeric|min:0',
            'brokerage_expected_at' => 'nullable|date',
            'co_broker_name'        => 'nullable|string|max:100',
        ]);

        $lead = Lead::findOrFail($validated['lead_id']);

        if (! $this->access->canManageLead($lead)) {
            return redirect('/')->with('error', '🚫 This lead is outside your management scope.');
        }

        if ($lead->isLost()) {
            return redirect('/')->with('error', '🚫 This lead is Lost. Use Revive Lead first.');
        }
        if ($lead->isWon()) {
            return redirect('/')->with('error', '🎉 This lead is already Booked. Use ✏️ Edit Booking to change details.');
        }

        if ($validated["status"] === "lost") {
            try {
                $this->workflowPresentations->assertLostReasonAllowedForUser(
                    $validated["lost_reason_key"] ?? null,
                    (int) session("user_id"),
                );
            } catch (\DomainException $e) {
                return redirect("/")->with("error", "🚫 ".$e->getMessage());
            }
        }

        $bookingDetails   = [];
        $brokerageDetails = [];

        if ($validated['status'] === 'booking') {
            if (empty($validated['booking_amount'])
                && (empty($validated['property_area_sqft']) || empty($validated['rate_per_sqft']))) {
                return redirect('/')->with('error', '🚫 Please enter area and rate, or provide the booking amount.');
            }

            $bookingDetails = [
                'property_area_sqft'   => $validated['property_area_sqft'] ?? null,
                'rate_per_sqft'        => $validated['rate_per_sqft'] ?? null,
                'booking_amount'       => $validated['booking_amount'] ?? null,
                'booking_unit'         => $validated['booking_unit'] ?? null,
                'booking_payment_mode' => $validated['booking_payment_mode'] ?? null,
                'booking_date'         => $validated['booking_date'] ?? now()->toDateString(),
            ];
            $brokerageDetails = [
                'brokerage_percentage'  => $validated['brokerage_percentage'] ?? null,
                'brokerage_amount'      => $validated['brokerage_amount'] ?? null,
                'brokerage_expected_at' => $validated['brokerage_expected_at'] ?? null,
                'co_broker_name'        => $validated['co_broker_name'] ?? null,
            ];
        }

        try {
            $this->statusService->change(
                $lead,
                $validated['status'],
                $validated['lost_reason'] ?? null,
                $validated['visit_scheduled_at'] ?? null,
                null,
                false,
                ! empty($validated['nurture_enabled']) ? ($validated['nurture_scheduled_at'] ?? null) : null,
                $validated['lost_reason_key'] ?? null,
                $bookingDetails,
                $brokerageDetails,
            );
        } catch (\DomainException $e) {
            return redirect('/')->with('error', '🚫 ' . $e->getMessage());
        }

        return redirect('/')->with('success', '✅ Status updated to ' . $this->settings->statusLabel($validated['status']));
    }

    public function revive(Request $request)
    {
        if (! $this->access->can('leads.status_change')) {
            return back()->with('error', '🚫 Only admins can revive a closed lead.');
        }
        $validated = $request->validate([
            'lead_id'            => 'required|integer|exists:leads,id',
            'reason'             => 'required|string|min:3|max:2000',
            'resume_status'      => 'required|string',
            'visit_scheduled_at' => 'nullable|date',
        ]);

        $lead = Lead::findOrFail($validated['lead_id']);

        if (! $this->access->canManageLead($lead)) {
            return back()->with('error', '🚫 This lead is outside your management scope.');
        }

        if (! $lead->isFinal()) {
            return redirect('/leads/' . $lead->id)
                ->with('error', 'This lead is not final — no need to revive.');
        }

        try {
            $this->statusService->change(
                $lead,
                $validated['resume_status'],
                null,
                $validated['visit_scheduled_at'] ?? null,
                $validated['reason'],
                true,
            );
        } catch (\DomainException $e) {
            return redirect('/leads/' . $lead->id)->with('error', '🚫 ' . $e->getMessage());
        }

        return redirect('/leads/' . $lead->id)->with('success', '🔄 Lead revived!');
    }

        /**
     * Assign or share a lead with agents. Anyone can share with anyone.
     * The primary agent is set on the lead; additional agents get shared access.
     */
        /**
     * Assign or share a lead with agents. Anyone can share with anyone.
     * A "reason" note is required for sharing so the receiving agent understands why.
     */
    public function assignAgent(Request $request)
    {
        $validated = $request->validate([
            'lead_id'                => 'required|integer|exists:leads,id',
            'agent_id'               => 'required|integer|exists:agents,id',
            'additional_agent_ids'   => 'nullable|array',
            'additional_agent_ids.*' => 'integer|exists:agents,id',
            'share_note'             => 'nullable|string|max:500',
            'share_type' => 'nullable|in:internal,site_team',
        ]);

        $lead  = Lead::findOrFail($validated['lead_id']);
        $agent = Agent::findOrFail($validated['agent_id']);

        $role = session('user_role');
        if ($role === 'admin') {
            if (! $this->access->can('leads.reassign') || ! $this->access->canManageLead($lead)) {
                return response()->json([
                    'success' => false,
                    'message' => 'You are not allowed to assign or share this lead.',
                ], 403);
            }

            $visibleAgentIds = $this->access->visibleAgentIds();
            if (! in_array((int) $agent->id, $visibleAgentIds, true)) {
                return response()->json([
                    'success' => false,
                    'message' => 'The selected agent is outside your assignment scope.',
                ], 403);
            }
            foreach (($validated['additional_agent_ids'] ?? []) as $sharedId) {
                if (! in_array((int) $sharedId, $visibleAgentIds, true)) {
                    return response()->json([
                        'success' => false,
                        'message' => 'One of the selected shared agents is outside your assignment scope.',
                    ], 403);
                }
            }
        } elseif ($role === 'team_manager') {
            if (! $this->access->canManageLead($lead)) {
                return back()->with('error', '🚫 This lead is outside your team scope.');
            }
            $managerAgentIds = app(TeamService::class)->agentIdsForManager((int) session('user_id'));
            if (! in_array((int) $agent->id, $managerAgentIds, true)) {
                return back()->with('error', '🚫 That agent is outside your team scope.');
            }
            foreach (($validated['additional_agent_ids'] ?? []) as $sharedId) {
                if (! in_array((int) $sharedId, $managerAgentIds, true)) {
                    return back()->with('error', '🚫 One of the selected shared agents is outside your team scope.');
                }
            }
        } elseif ($role === 'agent') {
            // Agents may share a lead they are allowed to work on, but they may
            // never change the primary owner from this management form. This
            // keeps ordinary agent sharing safe while preserving admin/manager
            // assignment authority.
            if (! $this->access->canWorkLead($lead)) {
                return response()->json([
                    'success' => false,
                    'message' => 'You are not allowed to work on this lead.',
                ], 403);
            }

            if ((int) $validated['agent_id'] !== (int) $lead->agent_id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Agents cannot change the primary owner. Use the shared-agent options to share this lead.',
                ], 403);
            }

            if (empty($validated['additional_agent_ids'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Select at least one other agent to share this lead with.',
                ], 422);
            }
        } else {
            abort(403);
        }

        if ($agent->status !== 'active') {
            return back()->with('error', '🚫 That agent is not active.');
        }

        // Require a note if this is a fresh assign/reassign and no note is present
        $hasNote = trim((string) ($validated['share_note'] ?? '')) !== '';
        $hasShares = ! empty($validated['additional_agent_ids']);

        // Require reason when: (a) sharing with someone, or (b) reassigning to a new primary
        $oldPrimaryId = $lead->agent_id;
        $isReassignment = $oldPrimaryId && (int) $oldPrimaryId !== (int) $agent->id;

        if (($hasShares || $isReassignment) && ! $hasNote) {
            return back()->withErrors([
                'share_note' => 'Please explain why this lead is being shared/reassigned.',
            ])->withInput();
        }

        $this->assignment->assignTo(
            $lead,
            $agent,
            (int) session('user_id'),
            $validated['additional_agent_ids'] ?? [],
            $validated['share_note'] ?? null,
            $validated['share_type'] ?? 'internal'
        );

        return redirect('/leads/' . $lead->id)
            ->with('success', '👤 Lead assignments updated.');
    }

    /**
 * Remove a co-agent from a shared lead (primary cannot be removed).
 * Admin-only. Returns JSON when requested via fetch/AJAX.
 */
public function unshareAgent(Request $request)
{
    $wantsJson = $request->wantsJson() || $request->ajax();

    // Admin-only
    if (! $this->access->can('leads.reassign')) {
        if ($wantsJson) {
            return response()->json([
                'success' => false,
                'message' => 'Only admins can remove shared agents.',
            ], 403);
        }
        return back()->with('error', '🚫 Only admins can remove shared agents.');
    }

    $validated = $request->validate([
        'lead_id'  => 'required|integer|exists:leads,id',
        'agent_id' => 'required|integer|exists:agents,id',
    ]);

    $lead = Lead::findOrFail($validated['lead_id']);

    if (! $this->access->canManageLead($lead)) {
        if ($wantsJson) {
            return response()->json(['success' => false, 'message' => 'This lead is outside your management scope.'], 403);
        }
        return back()->with('error', '🚫 This lead is outside your management scope.');
    }

    if ($lead->isPrimaryAgent($validated['agent_id'])) {
        if ($wantsJson) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot unshare the primary agent. Reassign instead.',
            ], 422);
        }
        return back()->with('error', '🚫 Cannot unshare the primary agent. Reassign instead.');
    }

    $this->assignment->unshare($lead, (int) $validated['agent_id']);

    if ($wantsJson) {
        return response()->json([
            'success' => true,
            'message' => '✅ Agent removed from lead.',
        ]);
    }

    return back()->with('success', '✅ Agent removed from lead.');
}

    /**
     * Record one actual site visit. A lead may have unlimited visits and each
     * visit may contain multiple projects, with one independent outcome per
     * project. This does not overwrite the lead's project or scheduled-visit
     * field and does not independently change pipeline status.
     */
    public function recordSiteVisit(Request $request)
    {
        if (! session('user_id')) {
            return back()->with('error', 'Session expired. Please log in again.');
        }

        $validated = $request->validate([
            'lead_id' => 'required|integer|exists:leads,id',
            'visit_at' => 'required|date',
            'client_attended' => 'required|boolean',
            'notes' => 'nullable|string|max:3000',
            'projects' => 'nullable|array',
            'projects.*.project_id' => 'required|integer|exists:projects,id',
            'projects.*.outcome_key' => 'required|string|max:80',
            'projects.*.notes' => 'nullable|string|max:1500',
        ]);

        $lead = Lead::with(['project', 'agent.user'])->findOrFail((int) $validated['lead_id']);
        if (! $this->access->canWorkLead($lead)) {
            return back()->with('error', '🚫 You are not allowed to record a site visit for this lead.');
        }

        // An actual site visit may only be recorded after the lead has reached
        // the confirmed/scheduled-visit stage. This prevents visit controls from
        // appearing or being used after an ordinary first call. Historical visits
        // remain available if one already exists.
        $hasConfirmedSiteVisit = (bool) $lead->visit_scheduled_at
            || Activity::where('lead_id', $lead->id)
                ->whereIn('outcome_key', ['site_visit_scheduled', 'visit_scheduled'])
                ->exists();
        if (! $hasConfirmedSiteVisit) {
            return back()->withInput()->with('error', '🏠 Record a site visit only after the customer has a confirmed/scheduled site visit.');
        }

        $sessionAgentId = Agent::where('user_id', session('user_id'))->value('id');
        if (session('user_role') === 'agent') {
            if (! $sessionAgentId || ! $lead->isAssignedTo((int) $sessionAgentId)) {
                return back()->with('error', '🚫 You can record a site visit only for a lead assigned/shared to you.');
            }
            $recordingAgentId = (int) $sessionAgentId;
        } elseif (in_array(session('user_role'), ['admin', 'team_manager'], true)) {
            $recordingAgentId = (int) ($lead->agent_id ?: ($sessionAgentId ?: 0));
            if (! $recordingAgentId) {
                return back()->with('error', 'This lead has no responsible agent for the visit record.');
            }
        } else {
            return back()->with('error', '🚫 You are not allowed to record site visits.');
        }

        $projects = collect($validated['projects'] ?? []);
        if ((bool) $validated['client_attended'] && $projects->isEmpty()) {
            return back()->withInput()->with('error', 'Select at least one project shown during the visit.');
        }

        $outcomeKeys = array_keys(WorkflowPresentationService::siteVisitOutcomeOptions());

        $projectIds = $projects->pluck('project_id')->map(fn ($id) => (int) $id)->values();
        if ($projectIds->unique()->count() !== $projectIds->count()) {
            return back()->withInput()->with('error', 'A project can be recorded only once within the same visit.');
        }

        if ($projects->contains(fn ($row) => ! in_array($row['outcome_key'] ?? '', $outcomeKeys, true))) {
            return back()->withInput()->with('error', 'One of the selected project outcomes is invalid.');
        }

        try {
            foreach ($projects as $row) {
                $this->workflowPresentations->assertSiteVisitOutcomeAllowedForUser(
                    (string) ($row["outcome_key"] ?? ""),
                    (int) session("user_id"),
                );
            }
        } catch (\DomainException $e) {
            return back()->withInput()->with("error", "🚫 ".$e->getMessage());
        }

        if ($projectIds->isNotEmpty()) {
            $visibleIds = Project::active()
                ->visibleTo((int) session('user_id'), session('user_role'))
                ->whereIn('id', $projectIds->all())
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();
            $missing = $projectIds->diff($visibleIds);
            if ($missing->isNotEmpty()) {
                return back()->withInput()->with('error', 'You are not routed/allowed to record one of the selected projects.');
            }
        }

        $visitId = DB::transaction(function () use ($validated, $lead, $recordingAgentId, $projects) {
            $visitId = DB::table('site_visits')->insertGetId([
                'lead_id' => $lead->id,
                'recorded_by_user_id' => (int) session('user_id'),
                'agent_id' => $recordingAgentId,
                'visit_at' => $validated['visit_at'],
                'client_attended' => (int) $validated['client_attended'],
                'notes' => isset($validated['notes']) ? trim((string) $validated['notes']) : null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            foreach ($projects as $row) {
                DB::table('site_visit_projects')->insert([
                    'site_visit_id' => $visitId,
                    'project_id' => (int) $row['project_id'],
                    'outcome_key' => $row['outcome_key'],
                    'notes' => isset($row['notes']) ? trim((string) $row['notes']) : null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            return $visitId;
        });

        // Add one concise canonical timeline entry without changing pipeline status.
        $projectSummary = [];
        if ($projects->isNotEmpty()) {
            $names = Project::whereIn('id', $projectIds->all())->pluck('name', 'id');
            foreach ($projects as $row) {
                $projectSummary[] = ($names[(int) $row['project_id']] ?? 'Project') . ' → ' . ($row['outcome_key'] ?? '');
            }
        }
        Activity::create([
            'lead_id' => $lead->id,
            'agent_id' => $recordingAgentId,
            'type' => 'site_visit',
            'outcome' => (bool) $validated['client_attended'] ? '🏠 Actual site visit recorded' : '🏠 Site visit — client did not attend',
            'outcome_key' => 'site_visit_recorded',
            'notes' => trim(
                'Site visit #' . $visitId . ' recorded on ' . $validated['visit_at'] . '. ' .
                ((bool) $validated['client_attended'] ? 'Client attended.' : 'Client did not attend.') .
                ($projectSummary ? "\nProjects shown:\n• " . implode("\n• ", $projectSummary) : '') .
                (! empty($validated['notes']) ? "\nVisit notes: " . trim((string) $validated['notes']) : '')
            ),
            'logged_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
            'action_source' => 'manual',
        ]);
        // An attended actual visit completes the scheduled-visit pipeline stage.
        // Only advance from visit_scheduled; never move a later-stage lead
        // backwards because another historical visit was recorded.
        if ((bool) $validated['client_attended'] && $lead->status === 'visit_scheduled') {
            $this->statusService->change(
                $lead->fresh(),
                'visit_done',
                null,
                null,
                null,
                true,
                null,
                null,
                [],
                [],
                'manual',
                false
            );
        }



        return redirect('/leads/' . $lead->id . '#site-visits')
            ->with('success', '🏠 Site visit #' . $visitId . ' recorded with ' . $projects->count() . ' project outcome' . ($projects->count() === 1 ? '' : 's') . '.');
    }

    /**
     * Add a separate project-specific workstream for the same customer/enquiry.
     * Normal agents may only create it for themselves and only for a project they
     * are currently routed to. Existing workstreams are never overwritten.
     */
    public function addProjectInterest(Request $request)
    {
        if (! session('user_id')) {
            return response()->json(['success' => false, 'message' => 'Session expired.'], 401);
        }

        $validated = $request->validate([
            'lead_id' => 'required|integer|exists:leads,id',
            'project_id' => 'required|integer|exists:projects,id',
            'agent_id' => 'nullable|integer|exists:agents,id',
            'reason' => 'required|string|max:1000',
        ]);

        $lead = Lead::with(['project', 'agent.user'])->findOrFail($validated['lead_id']);
        if (! $this->access->canAddProjectWorkstream($lead)) {
            return response()->json([
                'success' => false,
                'message' => 'You must be connected to this lead to add another project workstream.',
            ], 403);
        }

        if ($lead->isLost()) {
            return response()->json(['success' => false, 'message' => 'This lead is Lost. Revive it before adding another project.'], 422);
        }

        $project = Project::active()->findOrFail((int) $validated['project_id']);
        $visibleProject = Project::active()
            ->visibleTo((int) session('user_id'), session('user_role'))
            ->whereKey($project->id)
            ->exists();
        if (! $visibleProject) {
            return response()->json(['success' => false, 'message' => 'You are not routed to the selected project.'], 403);
        }

        $currentAgentId = Agent::where('user_id', session('user_id'))->value('id');
        if (session('user_role') === 'agent') {
            if (! $currentAgentId) {
                return response()->json(['success' => false, 'message' => 'Your agent profile could not be found.'], 403);
            }
            $targetAgentId = (int) $currentAgentId;
            if (! empty($validated['agent_id']) && (int) $validated['agent_id'] !== $targetAgentId) {
                return response()->json(['success' => false, 'message' => 'Agents can create a project workstream only for themselves.'], 403);
            }
        } else {
            $targetAgentId = (int) ($validated['agent_id'] ?? $currentAgentId ?? 0);
            if (! $targetAgentId) {
                return response()->json(['success' => false, 'message' => 'Select a responsible agent.'], 422);
            }
        }

        $target = Agent::with('user')->where('status', 'active')->findOrFail($targetAgentId);
        if (! $lead->agentHandlesProject($target->id, $project->id)) {
            return response()->json(['success' => false, 'message' => 'The responsible agent is not routed to the selected project.'], 422);
        }

        if (session('user_role') === 'team_manager') {
            $allowed = app(TeamService::class)->agentIdsForManager((int) session('user_id'));
            if (! in_array($target->id, $allowed, true)) {
                return response()->json(['success' => false, 'message' => 'That agent is outside your team scope.'], 403);
            }
        } elseif (! in_array(session('user_role'), ['admin', 'agent'], true)) {
            return response()->json(['success' => false, 'message' => 'You are not allowed to add project workstreams.'], 403);
        }

        // Resolve the root enquiry so all project interests remain one customer-level
        // family. Do not create a duplicate active workstream for the same project.
        $rootId = $lead->id;
        $guard = 0;
        while ($rootId && $guard < 20) {
            $parentId = Lead::whereKey($rootId)->value('parent_lead_id');
            if (! $parentId) break;
            $rootId = (int) $parentId;
            $guard++;
        }

        $duplicate = Lead::query()
            ->where(function ($q) use ($rootId) {
                $q->whereKey($rootId)->orWhere('parent_lead_id', $rootId);
            })
            ->where('project_id', $project->id)
            ->whereNotIn('status', ['lost'])
            ->exists();
        if ($duplicate) {
            return response()->json(['success' => false, 'message' => 'This customer already has an active workstream for the selected project.'], 422);
        }

        $reason = trim($validated['reason']);
        $newLead = $this->leadTransfers->createProjectInterestLead(
            $lead,
            $target,
            $project->id,
            $reason
        );

        // System vigilance is recorded after the business transaction commits so
        // an audit-chain writer lock can never interfere with the lead transaction.
        app(\App\Services\AuditLogService::class)->record(
            'lead_workflow',
            'Additional project workstream created',
            $request,
            [
                'source_lead_id' => $lead->id,
                'root_lead_id' => $rootId,
                'new_lead_id' => $newLead->id,
                'project_id' => $project->id,
                'project_name' => $project->name,
                'responsible_agent_id' => $target->id,
                'responsible_agent_name' => $target->user?->name,
                'reason' => $reason,
            ],
            'Lead',
            $newLead->id
        );

        $message = "✅ {$project->name} added as a separate project workstream for " . ($target->user?->name ?? 'the selected agent') . ". Existing project work remains unchanged.";
        return response()->json([
            'success' => true,
            'message' => $message,
            'new_lead_id' => $newLead->id,
            'source_lead_id' => $lead->id,
            'root_lead_id' => $rootId,
            'redirect_url' => url('/leads/' . $newLead->id),
        ]);
    }

    public function assignProject(Request $request)
    {
        if (! session('user_id')) {
            return response()->json(['success' => false, 'message' => 'Session expired.'], 401);
        }
        if (! $this->access->can('leads.reassign')) {
            return response()->json([
                'success' => false,
                'message' => 'Only admins can change the lead project. Use the Hand Over to Other Project Agent workflow for cross-project handover.',
            ], 403);
        }

        $validated = $request->validate([
            'lead_id'    => 'required|integer|exists:leads,id',
            'project_id' => 'required|integer|exists:projects,id',
            'reason'     => 'required|string|max:1000',
        ]);

        // Re-load the exact submitted lead after validation.
        $lead = Lead::with('project')->findOrFail($validated['lead_id']);
        if (! $this->access->canManageLead($lead)) {
            return response()->json([
                'success' => false,
                'message' => 'This lead is outside your management scope.',
            ], 403);
        }
        $newProject = Project::findOrFail($validated['project_id']);
        $reason = trim($validated['reason']);

        // A delegated Admin may only move a lead into a project that has at
        // least one eligible agent inside the Admin's delegated agent scope.
        // Otherwise project reassignment would become a cross-scope routing
        // bypass even though the source lead itself was visible.
        if ($this->access->hasDelegatedProfile() && ! $this->access->isUnrestrictedAdmin()) {
            $eligible = $newProject->eligibleAgentIdsBySource();
            $eligibleIds = array_values(array_unique(array_merge(
                $eligible['direct'] ?? [],
                $eligible['team'] ?? []
            )));
            if (! array_intersect($eligibleIds, $this->access->visibleAgentIds())) {
                return response()->json([
                    'success' => false,
                    'message' => 'The selected project is outside your delegated routing scope.',
                ], 403);
            }
        }

        $oldProjectId = $lead->project_id;
        $oldProjectName = $lead->project?->name ?? 'Not assigned';

        if ($oldProjectId === (int) $newProject->id) {
            return response()->json([
                'success' => false,
                'message' => 'Please select a different project.',
            ], 422);
        }

        $lead->update(['project_id' => $newProject->id]);

        $actorUserId = (int) session('user_id');
        $actorAgentId = (int) (Agent::where('user_id', $actorUserId)->value('id') ?: ($lead->agent_id ?: 0));
        $actorName = User::where('id', $actorUserId)->value('name') ?? 'Unknown user';
        Activity::create([
            'lead_id'     => $lead->id,
            'agent_id'    => $actorAgentId ?: ($lead->agent_id ?: 0),
            'type'        => 'project_changed',
            'outcome'     => "Project changed from {$oldProjectName} to {$newProject->name}",
            'outcome_key' => null,
            'action_source' => 'manual',
            'notes'       => implode("\n", [
                "By: {$actorName}",
                "From project: {$oldProjectName}",
                "To project: {$newProject->name}",
                "Reason: {$reason}",
                "When: " . now()->format('Y-m-d H:i:s'),
            ]),
            'logged_at'   => now(),
        ]);
        $lead->update(['last_activity_at' => now()]);

        app(\App\Services\AuditLogService::class)->record(
            'lead_workflow',
            'Lead project assignment corrected',
            $request,
            [
                'lead_id' => $lead->id,
                'from_project_id' => $oldProjectId,
                'from_project_name' => $oldProjectName,
                'to_project_id' => $newProject->id,
                'to_project_name' => $newProject->name,
                'reason' => $reason,
                'actor_user_id' => $actorUserId,
                'actor_name' => $actorName,
            ],
            'Lead',
            $lead->id
        );

        return response()->json([
            'success'      => true,
            'message'      => 'Project changed successfully and recorded in the timeline.',
            'project_name' => $newProject->name,
        ]);
    }

    public function updateBooking(Request $request)
    {
        $validated = $request->validate([
            'lead_id'               => 'required|integer|exists:leads,id',
            'property_area_sqft'    => 'nullable|numeric|min:0',
            'rate_per_sqft'         => 'nullable|numeric|min:0',
            'booking_amount'        => 'required|numeric|min:0',
            'booking_unit'          => 'nullable|string|max:100',
            'booking_payment_mode'  => 'nullable|string|max:50',
            'booking_date'          => 'nullable|date',
            'brokerage_percentage'  => 'nullable|numeric|min:0|max:100',
            'brokerage_amount'      => 'nullable|numeric|min:0',
            'brokerage_status'      => 'nullable|in:pending,invoiced,received,disputed',
            'brokerage_expected_at' => 'nullable|date',
            'co_broker_name'        => 'nullable|string|max:100',
            'booking_evidence'       => 'nullable|file|max:25600',
        ]);

        $lead = Lead::findOrFail($validated['lead_id']);

        if (! $this->access->canChangeBooking($lead)) {
            return redirect('/')->with('error', '🚫 You are not allowed to edit this booking.');
        }

        if ($lead->statusKey() !== 'booking') {
            return redirect('/leads/' . $lead->id)
                ->with('error', 'This lead is not at Booking status.');
        }

        $pct = $validated['brokerage_percentage']
            ?? $this->settings->get('default_brokerage_percentage', 2);

        $amount = $validated['brokerage_amount']
            ?? ($pct && $validated['booking_amount']
                ? round($validated['booking_amount'] * $pct / 100, 2)
                : null);

        $lead->update([
            'property_area_sqft'    => $validated['property_area_sqft'] ?? null,
            'rate_per_sqft'         => $validated['rate_per_sqft'] ?? null,
            'booking_amount'        => $validated['booking_amount'],
            'booking_unit'          => $validated['booking_unit'] ?? null,
            'booking_payment_mode'  => $validated['booking_payment_mode'] ?? null,
            'booking_date'          => $validated['booking_date'] ?? now()->toDateString(),
            'brokerage_percentage'  => $pct,
            'brokerage_amount'      => $amount,
            'brokerage_status'      => $validated['brokerage_status'] ?? 'pending',
            'brokerage_expected_at' => $validated['brokerage_expected_at'] ?? now()->addDays(30)->toDateString(),
            'co_broker_name'        => $validated['co_broker_name'] ?? null,
        ]);

        $control = $this->bookingControls->forLead($lead);
        if ($control && $control->status === \App\Models\BookingControl::APPROVED) {
            $this->bookingControls->recordApprovedEdit($lead->fresh());
        } elseif ($control) {
            $this->bookingControls->resubmitAfterEdit($lead->fresh());
        }

        if ($request->hasFile('booking_evidence')) {
            $this->documents->store(
                $request->file('booking_evidence'),
                [
                    'document_category' => 'booking_evidence',
                    'context_type' => 'booking',
                    'context_id' => $lead->id,
                    'workflow_stage' => 'booking',
                    'relationship' => 'evidence',
                    'source_label' => 'booking_details',
                    'visibility' => 'internal',
                ],
                'lead',
                (int) $lead->id,
                (int) session('user_id')
            );
        }
        return redirect('/leads/' . $lead->id)->with('success', '💾 Booking details saved!');

    }
}
