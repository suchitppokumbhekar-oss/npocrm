<?php

namespace App\Models;

use App\Enums\UserRole;
use App\Models\UserWhatsAppAccount;
use Illuminate\Foundation\Auth\User as Authenticatable;

class User extends Authenticatable
{
    protected $table = 'users';

    protected $fillable = [
        'name', 'email', 'password', 'role',
        'is_telecaller', 'is_on_payroll',
    ];

    protected $hidden = ['password'];

    protected $casts = [
        'role'          => UserRole::class,
        'is_telecaller' => 'boolean',
        'is_on_payroll' => 'boolean',
        'created_at'    => 'datetime',
        'updated_at'    => 'datetime',
    ];

    public function agent()
    {
        return $this->hasOne(Agent::class);
    }

    public function superAdmin()
    {
        return $this->hasOne(\App\Models\SuperAdmin::class, 'user_id');
    }

    public function whatsappAccounts()
    {
        return $this->hasMany(UserWhatsAppAccount::class);
    }

    /** Teams this user manages */
    public function managedTeams()
    {
        return $this->hasMany(Team::class, 'manager_user_id');
    }

    public function isAdmin(): bool
    {
        return $this->role === UserRole::ADMIN;
    }

    public function isTeamManager(): bool
    {
        return $this->role === UserRole::TEAM_MANAGER;
    }

    public function isAgent(): bool
    {
        return $this->role === UserRole::AGENT;
    }

    public function canAccessContacts(): bool
    {
        return app(\App\Services\AccessService::class)->canAccessContacts();
    }

    /**
     * Whether this user is required to mark daily attendance.
     * - Admins and team managers are always exempt.
     * - Only agents flagged as "on payroll" must check in.
     */
    public function requiresAttendance(): bool
    {
        if ($this->role !== UserRole::AGENT) {
            return false;
        }
        return (bool) $this->is_on_payroll;
    }
}