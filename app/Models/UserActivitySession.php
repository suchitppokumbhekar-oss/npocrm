<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserActivitySession extends Model
{
    protected $table = 'user_activity_sessions';

    protected $fillable = [
        'user_id', 'session_date', 'started_at', 'last_activity_at',
        'total_active_seconds', 'page_views', 'last_url',
    ];

    protected $casts = [
        'session_date'         => 'date',
        'started_at'           => 'datetime',
        'last_activity_at'     => 'datetime',
        'total_active_seconds' => 'integer',
        'page_views'           => 'integer',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function getDurationLabelAttribute(): string
    {
        $s = $this->total_active_seconds;
        if ($s < 60) return $s . 's';
        if ($s < 3600) return intdiv($s, 60) . 'm';
        return sprintf('%dh %02dm', intdiv($s, 3600), intdiv($s % 3600, 60));
    }
}