<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Contact extends Model
{
    protected $table = 'contacts';

    protected $fillable = [
        'name', 'phone', 'email', 'source', 'project_id', 'status',
        'assigned_to_agent_id', 'attempts', 'connected_count',
        'last_called_at', 'last_outcome_key', 'site_visit_scheduled_at', 'notes',
        'promoted_to_lead_id', 'promoted_at',
        'imported_from_batch_id', 'created_by_user_id', 'raw_payload',
    ];

    protected $casts = [
        'attempts'         => 'integer',
        'connected_count'  => 'integer',
        'last_called_at'   => 'datetime',
        'site_visit_scheduled_at' => 'datetime',
        'promoted_at'      => 'datetime',
        'created_at'       => 'datetime',
        'updated_at'       => 'datetime',
    ];

    public function project()     { return $this->belongsTo(Project::class); }
    public function projectAssignments() { return $this->hasMany(ContactProjectAssignment::class)->orderByDesc('id'); }
    public function activeProjectAssignment() { return $this->hasOne(ContactProjectAssignment::class)->where('status', 'active')->latestOfMany(); }
    public function agent()       { return $this->belongsTo(Agent::class, 'assigned_to_agent_id'); }
    public function calls()       { return $this->hasMany(Call::class); }
    public function followups()   { return $this->hasMany(ContactFollowup::class); }
    public function callSessions(){ return $this->hasMany(ContactCallSession::class); }
    public function lead()        { return $this->belongsTo(Lead::class, 'promoted_to_lead_id'); }
    public function importBatch() { return $this->belongsTo(ContactImportBatch::class, 'imported_from_batch_id'); }
    public function createdBy()   { return $this->belongsTo(User::class, 'created_by_user_id'); }

    public function scopePending($q)   { return $q->where('status', 'new'); }
    public function scopeReachable($q) { return $q->whereNotIn('status', ['dnc', 'invalid', 'converted']); }
    public function scopeAssignedTo($q, int $agentId) { return $q->where('assigned_to_agent_id', $agentId); }

    public function isPromoted(): bool
    {
        return $this->promoted_to_lead_id !== null;
    }

    public function isCallable(): bool
    {
        return ! in_array($this->status, ['dnc', 'invalid', 'converted'], true);
    }
}