<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Activity extends Model
{
    protected $table = 'activities';

    protected $fillable = [
        'lead_id', 'agent_id', 'type', 'outcome', 'outcome_key', 'notes', 'duration', 'logged_at', 'action_source',
    ];

    protected $casts = [
        'duration'   => 'integer',
        'logged_at'  => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function lead()
    {
        return $this->belongsTo(Lead::class);
    }

    public function agent()
    {
        return $this->belongsTo(Agent::class);
    }

    public function scopeRecent($query, int $minutes = 15)
    {
        return $query->where('logged_at', '>=', now()->subMinutes($minutes));
    }
    
        /**
     * Human-readable label for the chip on the leads list.
     * Priority: meaningful outcome → first line of notes → type name.
     * Truncates to ~60 chars so it stays compact.
     */
    public function displayLabel(int $maxChars = 60): string
    {
        // 1. Prefer a meaningful outcome (skip the generic "Logged" placeholder)
        if ($this->outcome && $this->outcome !== 'Logged') {
            return $this->outcome;
        }

        // 2. Fall back to the first line of notes
        if ($this->notes && trim($this->notes) !== '') {
            $text      = trim($this->notes);
            $firstLine = strtok($text, "\n") ?: $text;

            return mb_strlen($firstLine) > $maxChars
                ? mb_substr($firstLine, 0, $maxChars) . '…'
                : $firstLine;
        }

        // 3. Last resort — the type, prettified
        return ucfirst(str_replace('_', ' ', (string) $this->type));
    }

    /**
     * Icon for the chip, matched to the activity type.
     */
    public function icon(): string
    {
        return match ((string) $this->type) {
            'call'                => '📞',
            'whatsapp'            => '💬',
            'email'               => '✉️',
            'meeting'             => '🤝',
            'note'                => '📝',
            'site_visit'          => '🏠',
            'lead_shared'         => '👥',
            'lead_reassigned'     => '🔄',
            'status_change'       => '🔄',
            'shared_agent_report' => '👥',
            'site_team_report'    => '🏢',
            default               => '•',
        };
    }
}