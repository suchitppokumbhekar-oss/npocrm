<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Attendance extends Model
{
    protected $table = 'attendances';

    protected $fillable = [
        'agent_id', 'shift_date',
        'checked_in_at', 'checked_in_lat', 'checked_in_lng',
        'checked_in_accuracy_m', 'checked_in_distance_m', 'checked_in_ip',
        'checked_out_at', 'checked_out_lat', 'checked_out_lng',
        'checked_out_accuracy_m', 'checked_out_ip',
        'tasks_completed', 'tasks_overdue_at_exit',
        'override_by_user_id', 'override_reason',
    ];

    protected $casts = [
        'shift_date'            => 'date',
        'checked_in_at'         => 'datetime',
        'checked_out_at'        => 'datetime',
        'checked_in_lat'        => 'float',
        'checked_in_lng'        => 'float',
        'checked_out_lat'       => 'float',
        'checked_out_lng'       => 'float',
        'checked_in_accuracy_m' => 'integer',
        'checked_in_distance_m' => 'integer',
        'checked_out_accuracy_m'=> 'integer',
        'tasks_completed'       => 'integer',
        'tasks_overdue_at_exit' => 'integer',
        'created_at'            => 'datetime',
        'updated_at'            => 'datetime',
    ];

    public function agent()
    {
        return $this->belongsTo(Agent::class);
    }

    public function overriddenBy()
    {
        return $this->belongsTo(User::class, 'override_by_user_id');
    }

    public function isCheckedIn(): bool
    {
        return $this->checked_in_at !== null;
    }

    public function isCheckedOut(): bool
    {
        return $this->checked_out_at !== null;
    }

    public function durationLabel(): string
    {
        if (! $this->checked_in_at) return '—';
        $end = $this->checked_out_at ?? now();
        $minutes = $this->checked_in_at->diffInMinutes($end);
        return sprintf('%dh %02dm', intdiv($minutes, 60), $minutes % 60);
    }
}