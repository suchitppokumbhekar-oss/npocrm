<?php

namespace App\Models\Config;

use Illuminate\Database\Eloquent\Model;

class LeadStatus extends Model
{
    protected $table = 'lead_statuses';

    protected $fillable = ['key', 'label', 'color', 'is_final', 'terminal_type', 'is_active', 'sort_order'];

    protected $casts = [
        'is_final'   => 'boolean',
        'is_active'  => 'boolean',
        'sort_order' => 'integer',
    ];

    public function fromTransitions()
    {
        return $this->hasMany(LeadStatusTransition::class, 'from_status_id');
    }

    public function toTransitions()
    {
        return $this->hasMany(LeadStatusTransition::class, 'to_status_id');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order')->orderBy('id');
    }

    public function allowedTransitions()
    {
        return $this->fromTransitions()
            ->with('toStatus')
            ->get()
            ->pluck('toStatus')
            ->filter()
            ->values();
    }
}