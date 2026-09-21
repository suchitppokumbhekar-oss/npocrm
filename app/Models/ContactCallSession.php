<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ContactCallSession extends Model
{
    protected $table = 'contact_call_sessions';

    protected $fillable = [
        'contact_id', 'agent_id', 'started_at', 'completed_at', 'call_id',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function contact() { return $this->belongsTo(Contact::class); }
    public function agent() { return $this->belongsTo(Agent::class); }
    public function call() { return $this->belongsTo(Call::class); }

    public function isOpen(): bool
    {
        return $this->completed_at === null;
    }
}
