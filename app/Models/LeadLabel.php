<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LeadLabel extends Model
{
    protected $table = 'lead_labels';

    protected $fillable = [
        'key', 'label', 'group_key', 'group_label', 'icon', 'sort_order', 'is_active',
    ];

    protected $casts = [
        'sort_order' => 'integer',
        'is_active'  => 'boolean',
    ];

    public function scopeActive($q)  { return $q->where('is_active', true); }
    public function scopeOrdered($q) {
        return $q->orderBy('group_key')->orderBy('sort_order')->orderBy('label');
    }

    public function leads()
    {
        return $this->belongsToMany(Lead::class, 'lead_label_pivot', 'label_id', 'lead_id')
            ->withTimestamps();
    }
}