<?php

namespace App\Services;

use App\Models\Agent;
use App\Models\DelegatedAccessProfile;
use App\Models\DelegatedAccessUser;
use App\Models\TeamMember;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Phase A2 authorization foundation for delegated/scoped team managers.
 *
 * A delegated profile does not change users.role. It adds a narrower scope
 * and explicit permissions on top of the existing team_manager role.
 *
 * Effective agent visibility is:
 *   active members of delegated teams
 *   + explicitly included users
 *   + the delegated manager's own agent
 *   - explicitly excluded users
 *
 * An active delegated profile with no permissions grants no delegated
 * administrative capability. Existing team managers without a profile keep
 * their current role-based behavior through AccessService.
 */
class DelegatedAccessService
{
    /**
     * Permission keys available to the delegated-access layer.
     * The Phase A2 package only provides enforcement; no permissions are
     * inserted into the database yet.
     */
    public const PERMISSIONS = [
        'leads.view',
        'leads.manage',
        'leads.assign',
        'leads.reassign',
        'leads.status_change',
        'activities.view',
        'activities.create',
        'followups.view',
        'followups.manage',
        'calls.view',
        'calls.create',
        'whatsapp.view',
        'contacts.view',
        'contacts.import',
        'customers.view',
        'customers.manage',
        'reports.team.view',
        'reports.export',
        'agents.view',
        'agents.manage',
        'team_managers.view',
        'team_managers.manage',
        'users.view',
        'users.manage',
        'teams.view',
        'teams.manage',
        'settings.view',
        'settings.manage',
        'incentives.view',
        'incentives.manage',
        'documents.view',
        'documents.upload',
        'documents.approve_share',
        'documents.replace_own',
        'documents.remove_own',
        'documents.download',
        'project_media.manage',
    ];

    public function profileForUser(int $userId): ?DelegatedAccessProfile
    {
        return DelegatedAccessProfile::query()
            ->where('user_id', $userId)
            ->where('is_active', true)
            ->with(['teams', 'userRules', 'permissions'])
            ->first();
    }

    public function hasProfile(int $userId): bool
    {
        return DelegatedAccessProfile::query()
            ->where('user_id', $userId)
            ->where('is_active', true)
            ->exists();
    }

    public function can(int $userId, string $permission): bool
    {
        $profile = $this->profileForUser($userId);

        if (! $profile) {
            return false;
        }

        return $profile->permissions->contains(
            fn ($item) => $item->permission_key === $permission
        );
    }

    public function allowedTeamIds(int $userId): array
    {
        $profile = $this->profileForUser($userId);
        if (! $profile) {
            return [];
        }

        return $profile->teams
            ->where('is_active', true)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }

    /**
     * Resolve the effective agent scope for a delegated manager.
     */
    public function visibleAgentIds(int $userId): array
    {
        $profile = $this->profileForUser($userId);
        if (! $profile) {
            return [];
        }

        $teamIds = $profile->teams
            ->where('is_active', true)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $agentIds = [];

        if ($teamIds !== []) {
            $agentIds = TeamMember::query()
                ->whereIn('team_id', $teamIds)
                ->where('is_active', true)
                ->pluck('agent_id')
                ->map(fn ($id) => (int) $id)
                ->unique()
                ->values()
                ->all();
        }

        $includeUserIds = $profile->userRules
            ->where('access_type', 'include')
            ->pluck('user_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        if ($includeUserIds !== []) {
            $includedAgentIds = Agent::query()
                ->whereIn('user_id', $includeUserIds)
                ->where('status', 'active')
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();

            $agentIds = array_values(array_unique(array_merge($agentIds, $includedAgentIds)));
        }

        // The delegated manager must always retain access to their own work.
        $selfAgentId = Agent::query()->where('user_id', $userId)->value('id');
        if ($selfAgentId) {
            $agentIds[] = (int) $selfAgentId;
        }

        $excludeUserIds = $profile->userRules
            ->where('access_type', 'exclude')
            ->pluck('user_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        if ($excludeUserIds !== []) {
            $excludedAgentIds = Agent::query()
                ->whereIn('user_id', $excludeUserIds)
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();

            $agentIds = array_values(array_diff($agentIds, $excludedAgentIds));
        }

        return array_values(array_unique(array_map('intval', $agentIds)));
    }

    public function visibleUsers(int $userId): Collection
    {
        $agentIds = $this->visibleAgentIds($userId);
        if ($agentIds === []) {
            return collect();
        }

        return User::query()
            ->whereIn('id', Agent::query()->whereIn('id', $agentIds)->pluck('user_id'))
            ->orderBy('name')
            ->get();
    }
}
