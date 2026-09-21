<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OfficeLocation extends Model
{
    protected $table = 'office_locations';

    protected $fillable = [
        'label', 'address', 'latitude', 'longitude', 'radius_meters', 'is_active',
    ];

    protected $casts = [
        'latitude'      => 'float',
        'longitude'     => 'float',
        'radius_meters' => 'integer',
        'is_active'     => 'boolean',
        'created_at'    => 'datetime',
        'updated_at'    => 'datetime',
    ];

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}