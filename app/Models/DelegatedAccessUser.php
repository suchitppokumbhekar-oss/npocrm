<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DelegatedAccessUser extends Model
{
    protected $table = 'delegated_access_users';

    /**
     * This table has created_at but intentionally has no updated_at.
     */
    public const UPDATED_AT = null;

    protected $fillable = [
        'profile_id',
        'user_id',
        'access_type',
    ];

    public function profile()
    {
        return $this->belongsTo(DelegatedAccessProfile::class, 'profile_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
