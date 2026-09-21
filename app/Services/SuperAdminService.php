<?php

namespace App\Services;

use App\Models\SuperAdmin;
use App\Models\User;

/**
 * Phase A3: explicit Super Admin authority.
 *
 * Super Admin is intentionally stored separately from users.role so the
 * existing admin/team_manager/agent enum remains stable while authorization
 * is migrated safely. A user must still be an admin to be a Super Admin.
 */
class SuperAdminService
{
    public function isSuperAdmin(?int $userId = null): bool
    {
        $userId ??= (int) session('user_id');

        if (! $userId) {
            return false;
        }

        return SuperAdmin::query()
            ->where('user_id', $userId)
            ->where('is_active', true)
            ->whereHas('user', function ($q) {
                $q->where('role', 'admin');
            })
            ->exists();
    }

    public function requireSuperAdmin(): void
    {
        if (! $this->isSuperAdmin()) {
            abort(403, 'Super Admin access required.');
        }
    }

    public function grant(User $user, int $createdByUserId): SuperAdmin
    {
        $this->requireActor($createdByUserId);

        if ($user->role instanceof \App\Enums\UserRole) {
            $role = $user->role->value;
        } else {
            $role = (string) $user->role;
        }

        if ($role !== 'admin') {
            throw new \DomainException('Only an admin user can be made a Super Admin.');
        }

        return SuperAdmin::updateOrCreate(
            ['user_id' => $user->id],
            [
                'created_by_user_id' => $createdByUserId,
                'is_active' => true,
            ]
        );
    }

    public function revoke(User $user): void
    {
        $this->requireSuperAdmin();

        SuperAdmin::where('user_id', $user->id)
            ->update(['is_active' => false]);
    }

    private function requireActor(int $actorUserId): void
    {
        if (! $this->isSuperAdmin($actorUserId)) {
            abort(403, 'Only a Super Admin can grant Super Admin authority.');
        }
    }
}
