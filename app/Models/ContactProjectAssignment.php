<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ContactProjectAssignment extends Model
{
    protected $table = 'contact_project_assignments';

    protected $fillable = [
        'contact_id', 'project_id', 'assigned_to_agent_id', 'assigned_by_user_id',
        'status', 'assigned_at', 'started_at', 'completed_at',
    ];

    protected $casts = [
        'assigned_at'  => 'datetime',
        'started_at'   => 'datetime',
        'completed_at' => 'datetime',
        'created_at'   => 'datetime',
        'updated_at'   => 'datetime',
    ];

    public function contact() { return $this->belongsTo(Contact::class); }
    public function project() { return $this->belongsTo(Project::class); }
    public function agent() { return $this->belongsTo(Agent::class, 'assigned_to_agent_id'); }
    public function assignedBy() { return $this->belongsTo(User::class, 'assigned_by_user_id'); }
}
