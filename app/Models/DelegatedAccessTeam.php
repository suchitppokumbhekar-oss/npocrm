<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DelegatedAccessTeam extends Model
{
    protected $table = 'delegated_access_teams';

    /**
     * This table has created_at but intentionally has no updated_at.
     */
    public const UPDATED_AT = null;

    protected $fillable = [
        'profile_id',
        'team_id',
    ];

    public function profile()
    {
        return $this->belongsTo(DelegatedAccessProfile::class, 'profile_id');
    }

    public function team()
    {
        return $this->belongsTo(Team::class, 'team_id');
    }
}
