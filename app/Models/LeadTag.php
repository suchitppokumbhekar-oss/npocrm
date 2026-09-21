<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LeadTag extends Model
{
    protected $table = 'lead_tags';

    protected $fillable = ['key', 'label', 'icon', 'color', 'sort_order', 'is_active'];

    protected $casts = [
        'sort_order' => 'integer',
        'is_active'  => 'boolean',
    ];

    public function scopeActive($q)   { return $q->where('is_active', true); }
    public function scopeOrdered($q)  { return $q->orderBy('sort_order')->orderBy('label'); }
    public function leads()           { return $this->hasMany(Lead::class, 'tag_id'); }
}