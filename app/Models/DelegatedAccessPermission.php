<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DelegatedAccessPermission extends Model
{
    protected $table = 'delegated_access_permissions';

    /**
     * This table has created_at but intentionally has no updated_at.
     */
    public const UPDATED_AT = null;

    protected $fillable = [
        'profile_id',
        'permission_key',
    ];

    public function profile()
    {
        return $this->belongsTo(DelegatedAccessProfile::class, 'profile_id');
    }
}
