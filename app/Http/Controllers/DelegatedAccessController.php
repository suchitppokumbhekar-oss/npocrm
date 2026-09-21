<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Models\DelegatedAccessPermission;
use App\Models\DelegatedAccessProfile;
use App\Models\DelegatedAccessTeam;
use App\Models\DelegatedAccessUser;
use App\Models\SuperAdmin;
use App\Models\Team;
use App\Models\User;
use App\Services\DelegatedAccessService;
use App\Services\SuperAdminService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DelegatedAccessController extends Controller
{
    public function __construct(private SuperAdminService $superAdmins) {}

    public function index(?int $id = null)
    {
        $this->superAdmins->requireSuperAdmin();

        $profiles = DelegatedAccessProfile::query()
            ->with(['user', 'teams', 'userRules', 'permissions'])
            ->orderByDesc('is_active')->orderBy('name')->get();

        $teams = Team::query()->active()->orderBy('name')->get();

        $users = User::query()
            ->whereIn('role', [UserRole::ADMIN->value, UserRole::TEAM_MANAGER->value])
            ->whereDoesntHave('superAdmin', fn ($q) => $q->where('is_active', true))
            ->orderBy('name')->get();

        $selectableUsers = User::query()
            ->whereIn('role', [UserRole::ADMIN->value, UserRole::TEAM_MANAGER->value, UserRole::AGENT->value])
            ->whereDoesntHave('superAdmin', fn ($q) => $q->where('is_active', true))
            ->orderBy('name')->get();

        $editing = $id ? $profiles->firstWhere('id', $id) : null;

        if ($id && ! $editing) {
            abort(404);
        }

        return view('settings.delegated-access', [
            'profiles' => $profiles,
            'teams' => $teams,
            'users' => $users,
            'selectableUsers' => $selectableUsers,
            'permissions' => DelegatedAccessService::PERMISSIONS,
            'editing' => $editing,
        ]);
    }

    public function save(Request $request, ?int $id = null)
    {
        $this->superAdmins->requireSuperAdmin();

        $validated = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'name' => ['required', 'string', 'max:150'],
            'team_ids' => ['nullable', 'array'],
            'team_ids.*' => ['integer', 'exists:teams,id'],
            'include_user_ids' => ['nullable', 'array'],
            'include_user_ids.*' => ['integer', 'exists:users,id'],
            'exclude_user_ids' => ['nullable', 'array'],
            'exclude_user_ids.*' => ['integer', 'exists:users,id'],
            'permissions' => ['nullable', 'array'],
            'permissions.*' => ['string', 'max:100'],
        ]);

        $target = User::findOrFail((int) $validated['user_id']);
        $targetRole = $target->role instanceof UserRole ? $target->role->value : (string) $target->role;

        if (! in_array($targetRole, [UserRole::ADMIN->value, UserRole::TEAM_MANAGER->value], true)) {
            return back()->withInput()->with('error', 'Only Admin or Team Manager accounts can receive a delegated profile.');
        }
        if ($this->superAdmins->isSuperAdmin($target->id)) {
            return back()->withInput()->with('error', 'A Super Admin cannot be placed under delegated access.');
        }

        $profile = $id
    ? DelegatedAccessProfile::findOrFail($id)
    : DelegatedAccessProfile::where('user_id', $target->id)->first();

if ($profile && (int) $profile->user_id !== $target->id) {
    return back()->withInput()->with('error', 'The selected delegated profile belongs to a different user.');
}

        $teamIds = collect($validated['team_ids'] ?? [])->map(fn ($v) => (int) $v)->unique()->values()->all();
        $includeIds = collect($validated['include_user_ids'] ?? [])->map(fn ($v) => (int) $v)->unique()->values()->all();
        $excludeIds = collect($validated['exclude_user_ids'] ?? [])->map(fn ($v) => (int) $v)->unique()->values()->all();
        $permissionKeys = collect($validated['permissions'] ?? [])->map('strval')->unique()->values()->all();

        if (array_diff($permissionKeys, DelegatedAccessService::PERMISSIONS)) {
            return back()->withInput()->with('error', 'One or more selected permissions are not valid.');
        }
        if (array_intersect($includeIds, $excludeIds)) {
            return back()->withInput()->with('error', 'A user cannot be both included and excluded.');
        }

        $activeSuperAdminIds = SuperAdmin::query()->where('is_active', true)->pluck('user_id')->map(fn ($v) => (int) $v)->all();
        if (array_intersect($includeIds, $activeSuperAdminIds) || array_intersect($excludeIds, $activeSuperAdminIds)) {
            return back()->withInput()->with('error', 'Super Admin accounts cannot be included in or excluded by a delegated profile.');
        }

        $actorId = (int) session('user_id');

        DB::transaction(function () use ($profile, $target, $validated, $actorId, $teamIds, $includeIds, $excludeIds, $permissionKeys) {
            $profile ??= DelegatedAccessProfile::create([
                'user_id' => $target->id,
                'name' => $validated['name'],
                'is_active' => true,
                'created_by_user_id' => $actorId,
            ]);

            $profile->update(['name' => $validated['name'], 'is_active' => true]);

            DelegatedAccessTeam::where('profile_id', $profile->id)->delete();
            foreach ($teamIds as $teamId) {
                DelegatedAccessTeam::create(['profile_id' => $profile->id, 'team_id' => $teamId]);
            }

            DelegatedAccessUser::where('profile_id', $profile->id)->delete();
            foreach ($includeIds as $userId) {
                DelegatedAccessUser::create(['profile_id' => $profile->id, 'user_id' => $userId, 'access_type' => 'include']);
            }
            foreach ($excludeIds as $userId) {
                DelegatedAccessUser::create(['profile_id' => $profile->id, 'user_id' => $userId, 'access_type' => 'exclude']);
            }

            DelegatedAccessPermission::where('profile_id', $profile->id)->delete();
            foreach ($permissionKeys as $permissionKey) {
                DelegatedAccessPermission::create(['profile_id' => $profile->id, 'permission_key' => $permissionKey]);
            }
        });

        return redirect()->route('settings.delegated-access')->with('success', "Delegated access for {$target->name} has been saved and activated.");
    }

    public function toggle(int $id)
    {
        $this->superAdmins->requireSuperAdmin();
        $profile = DelegatedAccessProfile::with('user')->findOrFail($id);
        $profile->update(['is_active' => ! $profile->is_active]);
        return back()->with('success', 'Delegated access for '.$profile->user->name.' has been '.($profile->is_active ? 'activated' : 'deactivated').'.');
    }
}
