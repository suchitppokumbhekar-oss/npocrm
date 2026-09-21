<?php

namespace App\Models\Config;

use Illuminate\Database\Eloquent\Model;

class ActivityType extends Model
{
    protected $table = 'activity_types';

    protected $fillable = [
        'key', 'label', 'icon', 'requires_outcome',
        'is_system', 'is_active', 'sort_order',
    ];

    protected $casts = [
        'requires_outcome' => 'boolean',
        'is_system'        => 'boolean',
        'is_active'        => 'boolean',
        'sort_order'       => 'integer',
    ];

    public function scopeActive($query)     { return $query->where('is_active', true); }
    public function scopeManual($query)     { return $query->where('is_system', false); }
    public function scopeOrdered($query)    { return $query->orderBy('sort_order')->orderBy('id'); }
}