<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TeamMember extends Model
{
    protected $table = 'team_members';

    protected $fillable = ['team_id', 'agent_id', 'is_active'];

    protected $casts = [
        'is_active'  => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function team()  { return $this->belongsTo(Team::class); }
    public function agent() { return $this->belongsTo(Agent::class); }

    public function scopeActive($query) { return $query->where('is_active', true); }
}