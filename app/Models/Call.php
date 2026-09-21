<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Call extends Model
{
    protected $table = 'calls';

    protected $fillable = [
        'contact_id', 'lead_id', 'agent_id',
        'outcome_key', 'connected', 'duration_seconds', 'called_at', 'notes',
        'source', 'provider_call_id', 'recording_url', 'raw_payload',
    ];

    protected $casts = [
        'connected'        => 'boolean',
        'duration_seconds' => 'integer',
        'called_at'        => 'datetime',
        'created_at'       => 'datetime',
        'updated_at'       => 'datetime',
    ];

    public function contact() { return $this->belongsTo(Contact::class); }
    public function lead()    { return $this->belongsTo(Lead::class); }
    public function agent()   { return $this->belongsTo(Agent::class); }

    public function scopeConnected($q)   { return $q->where('connected', 1); }
    public function scopeInRange($q, $from, $to) { return $q->whereBetween('called_at', [$from, $to]); }
    public function scopeForAgent($q, int $agentId) { return $q->where('agent_id', $agentId); }

    public function getDurationLabelAttribute(): string
    {
        $s = (int) $this->duration_seconds;
        if ($s < 60) return $s . 's';
        $m = intdiv($s, 60);
        $r = $s % 60;
        return $r > 0 ? "{$m}m {$r}s" : "{$m}m";
    }
}