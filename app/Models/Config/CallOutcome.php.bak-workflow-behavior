<?php

namespace App\Models\Config;

use Illuminate\Database\Eloquent\Model;

class CallOutcome extends Model
{
    protected $table = 'call_outcomes';

    protected $fillable = [
        'key', 'label', 'category', 'is_connected', 'activity_type_filter',
        'next_action_type_id', 'next_action_delay_hours',
        'priority', 'suggested_status_id',
        'context_action_key', 'contact_status_key', 'requires_site_visit_datetime', 'next_action_anchor',
        'is_active', 'sort_order',
    ];

    protected $casts = [
        'next_action_delay_hours' => 'integer',
        'is_active'               => 'boolean',
        'sort_order'              => 'integer',
        'requires_site_visit_datetime' => 'boolean',
        'is_connected'             => 'boolean',
    ];

    public function nextActionType()  { return $this->belongsTo(FollowupActionType::class, 'next_action_type_id'); }
    public function suggestedStatus() { return $this->belongsTo(LeadStatus::class, 'suggested_status_id'); }

    public function scopeActive($query)  { return $query->where('is_active', true); }
    public function scopeOrdered($query) { return $query->orderBy('sort_order')->orderBy('id'); }

    public function scopeForContext($query, ?string $contextActionKey)
    {
        return $query->where(function ($q) use ($contextActionKey) {
            $q->whereNull('context_action_key');
            if ($contextActionKey) {
                $q->orWhere('context_action_key', $contextActionKey);
            }
        });
    }
}