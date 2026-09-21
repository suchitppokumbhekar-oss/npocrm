<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DelegatedAccessProfile extends Model
{
    protected $table = 'delegated_access_profiles';

    protected $fillable = [
        'user_id',
        'name',
        'is_active',
        'created_by_user_id',
    ];

    protected $casts = [
        'is_active'  => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function teams()
    {
        return $this->belongsToMany(
            Team::class,
            'delegated_access_teams',
            'profile_id',
            'team_id'
        );
    }

    public function userRules()
    {
        return $this->hasMany(DelegatedAccessUser::class, 'profile_id');
    }

    public function permissions()
    {
        return $this->hasMany(DelegatedAccessPermission::class, 'profile_id');
    }
}