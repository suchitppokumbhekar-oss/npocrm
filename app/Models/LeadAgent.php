<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LeadAgent extends Model
{
    protected $table = 'lead_agents';

    protected $fillable = [
        'lead_id', 'agent_id', 'is_primary', 'is_active', 'added_by_user_id', 'note',
    ];

    protected $casts = [
        'is_primary' => 'boolean',
        'is_active'  => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function lead()          { return $this->belongsTo(Lead::class); }
    public function agent()         { return $this->belongsTo(Agent::class); }
    public function addedBy()       { return $this->belongsTo(User::class, 'added_by_user_id'); }

    public function scopeActive($q)   { return $q->where('is_active', true); }
    public function scopePrimary($q)  { return $q->where('is_primary', true); }
}