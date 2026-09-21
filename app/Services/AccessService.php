<?php

namespace App\Services;

use App\Models\Agent;
use App\Models\BookingControl;
use App\Models\Contact;
use App\Models\Lead;
use App\Models\User;

class AccessService
{
    public function __construct(
        private SettingsService $settings,
        private TeamService $teams,
        private DelegatedAccessService $delegated,
        private SuperAdminService $superAdmins,
    ) {}

    /** Can the current session user see Contacts & Dialer? */
    public function canAccessContacts(): bool
    {
        $role = session('user_role');
        $userId = (int) session('user_id');

        if ($role === 'admin' && $this->superAdmins->isSuperAdmin($userId)) {
            return true;
        }

        if ($role === 'admin') {
            if ($this->isUnrestrictedAdmin($userId)) {
                return true;
            }
            return $this->delegated->can($userId, 'contacts.view');
        }

        if ($role === 'team_manager' && $userId && $this->delegated->hasProfile($userId)) {
            return $this->delegated->can($userId, 'contacts.view');
        }

        if ($role === 'team_manager') {
            return true;
        }

        if ($this->settings->get('contacts_visibility', 'telecallers_only') === 'everyone') {
            return true;
        }

        if (! $userId) {
            return false;
        }

        return (bool) User::where('id', $userId)->value('is_telecaller');
    }

    /** Can the current user import Contacts through the CSV importer? */
    public function canImportContacts(): bool
    {
        $role = session('user_role');
        $userId = (int) session('user_id');

        if ($role === 'admin' && $this->isUnrestrictedAdmin($userId)) {
            return true;
        }

        if (! $userId) {
            return false;
        }

        // Import is an explicit delegated capability. Team Managers without
        // a delegated profile do not receive import rights merely from their role.
        if (in_array($role, ['admin', 'team_manager'], true)) {
            return $this->delegated->can($userId, 'contacts.import');
        }

        return false;
    }

    /**
     * Generic permission check.
     *
     * Admin remains unrestricted. Existing team managers without a delegated
     * profile retain their current role-based behavior. Once a team manager
     * has an active delegated profile, permissions become explicit.
     */
    public function can(string $permission): bool
    {
        $role = session('user_role');
        $userId = (int) session('user_id');

        if (! $userId || ! $role) {
            return false;
        }

        if ($role === 'admin') {
            if ($this->isUnrestrictedAdmin($userId)) {
                return true;
            }
            return $this->delegated->can($userId, $permission);
        }

        if ($role === 'team_manager') {
            if ($this->delegated->hasProfile($userId)) {
                return $this->delegated->can($userId, $permission);
            }

            return true;
        }

        return false;
    }

    /**
 * Central lead visibility rule.
 *
 * Shared active assignments count.
 *
 * For delegated Admins, visibility is based on the delegated
 * agent scope — the Admin does NOT need to have their own Agent record.
 */
public function canViewLead(Lead $lead): bool
{
    $userId = (int) session('user_id');
    $role   = session('user_role');

    if (! $userId || ! $role) {
        return false;
    }

    /*
    |--------------------------------------------------------------------------
    | Unrestricted Admin / Super Admin
    |--------------------------------------------------------------------------
    */
    if ($role === 'admin' && $this->isUnrestrictedAdmin($userId)) {
        return true;
    }

    /*
    |--------------------------------------------------------------------------
    | Delegated Admin
    |--------------------------------------------------------------------------
    | A delegated Admin sees leads belonging to ANY active agent within
    | their delegated scope.
    |
    | Do this BEFORE looking for the Admin's own Agent record.
    */
    if ($role === 'admin' && $this->delegated->hasProfile($userId)) {

        if (! $this->delegated->can($userId, 'leads.view')) {
            return false;
        }

        $allowedAgentIds = $this->delegated->visibleAgentIds($userId);

        if (empty($allowedAgentIds)) {
            return false;
        }

        return $lead->assignments()
            ->active()
            ->whereIn('agent_id', $allowedAgentIds)
            ->exists();
    }

    /*
    |--------------------------------------------------------------------------
    | Normal Agent / Team Manager
    |--------------------------------------------------------------------------
    */
    $agentId = Agent::where('user_id', $userId)->value('id');

    if (! $agentId) {
        return false;
    }

    /*
    |--------------------------------------------------------------------------
    | Personally assigned/shared lead
    |--------------------------------------------------------------------------
    */
    if ($lead->isAssignedTo((int) $agentId)) {
        return true;
    }

    // A transferred lead may no longer have an active lead_agents row for an
    // agent who previously handled it. Preserve read/context visibility for
    // that historical participant so they can reconnect through the controlled
    // Add Project Interest workflow. This does NOT make them the current owner
    // and canWorkLead() remains restricted to active assignments.
    if (session('user_role') === 'agent' && $this->hasHistoricalLeadParticipation($lead, (int) $agentId)) {
        return true;
    }

    /*
    |--------------------------------------------------------------------------
    | Team Manager
    |--------------------------------------------------------------------------
    */
    if ($role === 'team_manager') {

        if (
            $this->delegated->hasProfile($userId)
            && ! $this->delegated->can($userId, 'leads.view')
        ) {
            return false;
        }

        $teamAgentIds = $this->effectiveAgentIds($userId);

        return $lead->assignments()
            ->active()
            ->whereIn('agent_id', $teamAgentIds ?: [-1])
            ->exists();
    }

    return false;
}

    /** Users who may work on a lead: admin, manager in scope, or active assigned agent. */
    public function canWorkLead(Lead $lead): bool
    {
        $role = session('user_role');
        $userId = (int) session('user_id');

        if (! $userId || ! $role) return false;

        if ($role === 'admin') {
            if ($this->isUnrestrictedAdmin($userId)) return true;
            return $this->delegated->can($userId, 'leads.manage') && $this->canViewLead($lead);
        }

        if ($role === 'team_manager') {
            if ($this->delegated->hasProfile($userId)
                && ! $this->delegated->can($userId, 'leads.manage')) {
                return false;
            }
            return $this->canViewLead($lead);
        }

        if ($role !== 'agent') return false;

        $agentId = Agent::where('user_id', $userId)->value('id');
        if (! $agentId) return false;

        // Historical participation is intentionally excluded. Only an active
        // lead_agents relationship grants operational work access.
        return $lead->assignments()
            ->active()
            ->where('agent_id', (int) $agentId)
            ->exists();
    }

    /**
     * Historical participation is deliberately read/context access only.
     * It allows an agent who previously worked on a lead to reconnect after a
     * controlled transfer and add a genuinely separate project workstream,
     * without restoring ownership of the existing workstream.
     */
    public function hasHistoricalLeadParticipation(Lead $lead, ?int $agentId = null): bool
    {
        $agentId = $agentId ?: (int) Agent::where('user_id', session('user_id'))->value('id');

        if (! $agentId || session('user_role') !== 'agent') {
            return false;
        }

        // Preserve inactive assignment history when it still exists.
        if ($lead->assignments()->where('agent_id', $agentId)->exists()) {
            return true;
        }

        // Legacy transfer/correction may have removed the lead_agents row.
        // Canonical activity history still proves that this agent participated
        // in this exact lead previously.
        return \App\Models\Activity::query()
            ->where('lead_id', $lead->id)
            ->where('agent_id', $agentId)
            ->exists();
    }

    /**
     * Agents/managers who are legitimately connected to a lead may create an
     * additional project-specific workstream. An active assignee can work the
     * current lead; a historical participant can reconnect only for this
     * additive project-interest action. Neither path grants administrative
     * lead-management rights.
     */
    public function canAddProjectWorkstream(Lead $lead): bool
    {
        if ($this->canWorkLead($lead)) {
            return true;
        }

        return $this->hasHistoricalLeadParticipation($lead);
    }

    /** Administrative lead management: admin or manager within their team scope. */
    public function canManageLead(Lead $lead): bool
    {
        $role = session('user_role');
        $userId = (int) session('user_id');

        if ($role === 'admin') {
            return $this->isUnrestrictedAdmin($userId)
                || ($this->delegated->can($userId, 'leads.manage') && $this->canViewLead($lead));
        }
        if ($role !== 'team_manager') return false;

        if ($this->delegated->hasProfile($userId)) {
            return $this->delegated->can($userId, 'leads.manage')
                && $this->canViewLead($lead);
        }

        return $this->canViewLead($lead);
    }

    /** Primary assignment/reassignment is admin-only for now. */
    public function canReassignLead(): bool
    {
        $userId = (int) session('user_id');
        if ($this->isUnrestrictedAdmin($userId)) return true;
        return $this->can('leads.reassign');
    }

    /**
     * Booking edits are controlled separately from ordinary lead work.
     * Before approval, the normal lead-work scope applies. Once approved,
     * only an unrestricted Admin may change booking/brokerage details.
     */
    public function canChangeBooking(Lead $lead): bool
    {
        $control = BookingControl::query()->where('lead_id', $lead->id)->first();

        if ($control && $control->status === BookingControl::APPROVED) {
            return $this->isUnrestrictedAdmin();
        }

        return $this->canWorkLead($lead);
    }

    /** Contact visibility scope used by individual contact endpoints. */
    public function canViewContact(Contact $contact): bool
    {
        $role = session('user_role');
        $userId = (int) session('user_id');

        if ($role === 'admin') {
            if ($this->isUnrestrictedAdmin($userId)) return true;
            if (! $this->delegated->can($userId, 'contacts.view')) return false;
        }

        if ($role === 'team_manager' && $this->delegated->hasProfile($userId)) {
            if (! $this->delegated->can($userId, 'contacts.view')) {
                return false;
            }
        }

        $assigned = $contact->assigned_to_agent_id;
        if ($assigned === null) return true;

        $agentId = Agent::where('user_id', $userId)->value('id');
        if (! $agentId) return false;
        if ((int) $assigned === (int) $agentId) return true;

        if ($role === 'team_manager') {
            return in_array((int) $assigned, $this->effectiveAgentIds($userId), true);
        }

        return false;
    }

    public function canAssignContactTo(Contact $contact, ?int $targetAgentId): bool
    {
        $role = session('user_role');
        $userId = (int) session('user_id');

        if ($role === 'admin') {
            if ($this->isUnrestrictedAdmin($userId)) return true;
            if (! $this->delegated->can($userId, 'contacts.view')) return false;
        }

        if ($role === 'team_manager' && $this->delegated->hasProfile($userId)) {
            if (! $this->delegated->can($userId, 'contacts.view')) {
                return false;
            }
        }

        if ($targetAgentId === null) return $this->canViewContact($contact);
        return in_array($targetAgentId, $this->effectiveAgentIds($userId), true);
    }

    public function visibleAgentIds(): array
    {
        $role = session('user_role');
        $userId = (int) session('user_id');

        if ($role === 'admin' && $this->isUnrestrictedAdmin($userId)) {
            return Agent::pluck('id')->map(fn ($id) => (int) $id)->all();
        }

        return $this->effectiveAgentIds($userId);
    }

    /** True only for a Super Admin or an Admin without an active delegated profile. */
    public function isUnrestrictedAdmin(?int $userId = null): bool
    {
        $userId ??= (int) session('user_id');
        if (! $userId || session('user_role') !== 'admin') return false;
        if (app(SuperAdminService::class)->isSuperAdmin($userId)) return true;
        return ! $this->delegated->hasProfile($userId);
    }

    public function hasDelegatedProfile(?int $userId = null): bool
    {
        $userId ??= (int) session('user_id');
        return $userId > 0 && $this->delegated->hasProfile($userId);
    }

    /** Effective active team scope for delegated Admin/Team Manager users. */
    public function visibleTeamIds(?int $userId = null): array
    {
        $userId ??= (int) session('user_id');
        $role = session('user_role');
        if ($role === 'admin' && $this->isUnrestrictedAdmin($userId)) {
            return \App\Models\Team::where('is_active', true)->pluck('id')->map(fn ($id) => (int) $id)->all();
        }
        if ($userId && $this->delegated->hasProfile($userId)) {
            return $this->delegated->allowedTeamIds($userId);
        }
        if ($role === 'team_manager') {
            return \App\Models\Team::where('manager_user_id', $userId)->where('is_active', true)
                ->pluck('id')->map(fn ($id) => (int) $id)->all();
        }
        return [];
    }

    /**
     * Resolve the agent scope while preserving legacy team-manager behavior
     * for managers who do not have a delegated profile.
     */
    private function effectiveAgentIds(int $userId): array
    {
        if (! $userId) {
            return [];
        }

        if ($this->delegated->hasProfile($userId)) {
            return $this->delegated->visibleAgentIds($userId);
        }

        $role = session('user_role');

        if ($role === 'team_manager') {
            return $this->teams->agentIdsForManager($userId);
        }

        $self = Agent::where('user_id', $userId)->value('id');
        return $self ? [(int) $self] : [];
    }
}
