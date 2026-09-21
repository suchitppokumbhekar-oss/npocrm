<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

class ContactFollowup extends Model
{
    protected $table = 'contact_followups';

    protected $fillable = [
        'contact_id', 'agent_id', 'scheduled_for', 'action_type',
        'priority', 'status', 'auto_created', 'source_call_id', 'notes',
        'completed_at', 'completed_by_agent_id', 'completion_outcome_key', 'completion_notes',
    ];

    protected $casts = [
        'scheduled_for' => 'datetime',
        'auto_created' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function contact() { return $this->belongsTo(Contact::class); }
    public function agent() { return $this->belongsTo(Agent::class); }
    public function completedByAgent() { return $this->belongsTo(Agent::class, 'completed_by_agent_id'); }
    public function sourceCall() { return $this->belongsTo(Call::class, 'source_call_id'); }

    public function scopePending($query) { return $query->where('status', 'pending'); }
    public function scopeDue($query) { return $query->where('status', 'pending')->where('scheduled_for', '<=', now()); }

    public function isOverdue(): bool
    {
        return $this->status === 'pending' && $this->scheduled_for && $this->scheduled_for->isPast();
    }
}
