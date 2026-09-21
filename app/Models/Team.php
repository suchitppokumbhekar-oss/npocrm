<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Team extends Model
{
    protected $table = 'teams';

    protected $fillable = [
        'name', 'description', 'manager_user_id', 'is_active',
    ];

    protected $casts = [
        'is_active'  => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function manager()
    {
        return $this->belongsTo(User::class, 'manager_user_id');
    }

    public function members()
    {
        return $this->hasMany(TeamMember::class);
    }

    public function agents()
    {
        return $this->belongsToMany(
            Agent::class,
            'team_members',
            'team_id',
            'agent_id'
        )->withPivot('is_active')->withTimestamps();
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function agentIds(): array
    {
        return $this->members()
            ->where('is_active', true)
            ->pluck('agent_id')
            ->all();
    }
}