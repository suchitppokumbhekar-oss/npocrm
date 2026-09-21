<?php

namespace App\Models;

use App\Services\BusinessHoursService;
use Illuminate\Database\Eloquent\Model;
use Carbon\Carbon;

class Followup extends Model
{
    protected $table = 'followups';

    protected $fillable = [
        'lead_id', 'agent_id', 'scheduled_for', 'action_type',
        'priority', 'status', 'escalated_flag',
        'auto_created', 'source_activity_id',
    ];

    protected $casts = [
        'scheduled_for'  => 'datetime',
        'escalated_flag' => 'boolean',
        'auto_created'   => 'boolean',
        'created_at'     => 'datetime',
        'updated_at'     => 'datetime',
    ];

    protected static function booted(): void
    {
        // FINAL SAFETY NET: every automatically-created follow-up, regardless
        // of which service/controller/job created it, passes through the same
        // 10:30 AM–8:00 PM automatic contact window. Manual scheduling
        // (auto_created=false) remains an explicit override.
        static::creating(function (self $followup): void {
            if (! $followup->auto_created || ! $followup->scheduled_for) {
                return;
            }

            $when = $followup->scheduled_for instanceof Carbon
                ? $followup->scheduled_for
                : Carbon::parse($followup->scheduled_for);

            $followup->scheduled_for = app(BusinessHoursService::class)->automatic($when);
        });

        // Deliver the immediate work-handoff notification only after the
        // surrounding transaction commits. This prevents a rolled-back task
        // from leaving a phantom notification on the agent's phone.
        static::created(function (self $followup): void {
            if ($followup->status !== 'pending' || ! $followup->agent_id) {
                return;
            }

            $send = function () use ($followup): void {
                try {
                    app(\App\Services\NotificationService::class)
                        ->notifyFollowupAssigned($followup);
                } catch (\Throwable $e) {
                    report($e);
                }
            };

            \Illuminate\Support\Facades\DB::afterCommit($send);
        });
    }

    public function lead()           { return $this->belongsTo(Lead::class); }
    public function agent()          { return $this->belongsTo(Agent::class); }
    public function sourceActivity() { return $this->belongsTo(Activity::class, 'source_activity_id'); }

    public function scopePending($query)     { return $query->where('status', 'pending'); }
    public function scopeAutoCreated($query) { return $query->where('auto_created', true); }
    public function scopeOverdue($query)     { return $query->where('status', 'pending')->where('scheduled_for', '<', now()); }

    public function isOverdue(): bool
    {
        return $this->status === 'pending'
            && $this->scheduled_for
            && $this->scheduled_for->isPast();
    }

    public function isEscalated(): bool
    {
        return (bool) $this->escalated_flag;
    }
}
