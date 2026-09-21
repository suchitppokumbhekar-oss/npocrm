<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Agent extends Model
{
    protected $table = 'agents';

    protected $fillable = [
        'user_id', 'phone', 'whatsapp_personal_number', 'whatsapp_business_number', 'max_daily_leads',
        'current_load', 'status',
        'employee_id', 'salary', 'joining_date', 'confirmation_date', 'exit_date',
    ];

    protected $casts = [
        'current_load'    => 'integer',
        'max_daily_leads' => 'integer',
        'salary'          => 'decimal:2',
        'joining_date'    => 'date',
        'confirmation_date' => 'date',
        'exit_date'       => 'date',
        'created_at'      => 'datetime',
        'updated_at'      => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function salaryHistory()
    {
        return $this->hasMany(\App\Models\SalaryHistory::class, 'agent_id')->orderBy('effective_from');
    }

    public function leads()
    {
        return $this->hasMany(Lead::class);
    }

    public function followups()
    {
        return $this->hasMany(Followup::class);
    }

    public function activities()
    {
        return $this->hasMany(Activity::class);
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeLeastLoaded($query)
    {
        return $query->orderBy('current_load', 'asc');
    }

    public function hasCapacity(): bool
    {
        return $this->current_load < $this->max_daily_leads;
    }
    
        public function teams()
    {
        return $this->belongsToMany(
            Team::class,
            'team_members',
            'agent_id',
            'team_id'
        )->withPivot('is_active')->withTimestamps();
    }

    public function activeTeams()
    {
        return $this->teams()->wherePivot('is_active', true);
    }
    
        public function sharedLeads()
    {
        return $this->belongsToMany(Lead::class, 'lead_agents')
            ->withPivot(['is_primary', 'is_active'])
            ->withTimestamps();
    }

    public function allAssignedLeads()
    {
        return Lead::whereIn('id', function ($q) {
            $q->select('lead_id')
              ->from('lead_agents')
              ->where('agent_id', $this->id)
              ->where('is_active', true);
        });
    }
    
        /** True if this agent's user is an admin */
    public function isAdmin(): bool
    {
        return $this->user && $this->user->role === 'admin';
    }

    /** Active agent IDs across a specific set of teams */
    public static function scopeInTeams($query, array $teamIds)
    {
        return $query->whereHas('teams', function ($q) use ($teamIds) {
            $q->whereIn('teams.id', $teamIds)->wherePivot('is_active', true);
        });
    }
    
}