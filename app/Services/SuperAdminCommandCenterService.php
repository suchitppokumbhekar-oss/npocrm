<?php

namespace App\Services;

use App\Models\Activity;
use App\Models\Attendance;
use App\Models\Followup;
use App\Models\Lead;
use Carbon\Carbon;

class SuperAdminCommandCenterService
{
    private const SYSTEM_ACTIVITY_TYPES = [
        'lead_shared', 'lead_reassigned', 'status_change',
        'shared_agent_report', 'site_team_report',
    ];

    public function __construct(
        private SettingsService $settings,
        private BusinessHoursService $businessHours,
    ) {}

    public function snapshot(): array
    {
        $this->guard();

        $unassigned = $this->unassignedLeadAttention();
        $untouched = $this->untouchedLeadAttention();
        $followups = $this->followupAttention();
        $workforce = $this->workforceAttention();

        return [
            'generated_at' => now(),
            'thresholds' => [
                'unassigned_minutes' => $this->intSetting('unassigned_alert_min_age_minutes', 5),
                'first_contact_minutes' => $this->intSetting('first_contact_delay_minutes', 15),
                'followup_escalation_hours' => $this->intSetting('followup_escalation_hours', 4),
                'work_start' => $this->businessHours->startTime(),
                'work_end' => $this->businessHours->endTime(),
                'working_days' => $this->businessHours->workingDays(),
            ],
            'counts' => [
                'critical' => collect([$unassigned, $untouched, $followups, $workforce])->flatten(1)->where('severity', 'critical')->count(),
                'attention' => collect([$unassigned, $untouched, $followups, $workforce])->flatten(1)->where('severity', 'attention')->count(),
                'unassigned' => count($unassigned),
                'untouched' => count($untouched),
                'followups' => count($followups),
                'workforce' => count($workforce),
            ],
            'lead_attention' => [
                'unassigned' => array_slice($unassigned, 0, 50),
                'untouched' => array_slice($untouched, 0, 50),
            ],
            'followup_attention' => array_slice($followups, 0, 100),
            'workforce_attention' => array_slice($workforce, 0, 50),
        ];
    }

    private function unassignedLeadAttention(): array
    {
        $threshold = $this->intSetting('unassigned_alert_min_age_minutes', 5);

        return Lead::with('project')
            ->whereNull('agent_id')
            ->whereNotIn('status', $this->finalStatusKeys())
            ->orderBy('created_at')
            ->get()
            ->map(function (Lead $lead) use ($threshold) {
                $elapsed = $this->workingMinutesSince($lead->created_at);
                if ($elapsed < $threshold) return null;

                return [
                    'kind' => 'lead_unassigned',
                    'severity' => $elapsed >= max($threshold * 3, 15) ? 'critical' : 'attention',
                    'lead_id' => (int) $lead->id,
                    'lead_name' => $lead->customer_name ?: 'Lead #' . $lead->id,
                    'project' => $lead->project?->name,
                    'responsible_agent_id' => null,
                    'responsible_name' => null,
                    'since' => $lead->created_at,
                    'age_working_minutes' => $elapsed,
                    'reason' => 'Lead has no primary agent after the configured assignment window.',
                    'url' => '/leads/' . $lead->id,
                ];
            })->filter()->values()->all();
    }

    private function untouchedLeadAttention(): array
    {
        $threshold = $this->intSetting('first_contact_delay_minutes', 15);

        return Lead::with(['project', 'agent.user'])
            ->whereNotNull('agent_id')
            ->whereNotIn('status', $this->finalStatusKeys())
            ->whereIn('status', ['new', 'external_shared'])
            ->orderBy('assigned_at')
            ->get()
            ->map(function (Lead $lead) use ($threshold) {
                $start = $lead->assigned_at ?: $lead->created_at;
                if (! $start) return null;
                if ($this->hasMeaningfulHumanActivity((int) $lead->id, $start)) return null;

                $elapsed = $this->workingMinutesSince($start);
                if ($elapsed < $threshold) return null;

                return [
                    'kind' => 'lead_first_contact_breached',
                    'severity' => $elapsed >= max($threshold * 2, 30) ? 'critical' : 'attention',
                    'lead_id' => (int) $lead->id,
                    'lead_name' => $lead->customer_name ?: 'Lead #' . $lead->id,
                    'project' => $lead->project?->name,
                    'responsible_agent_id' => $lead->agent_id ? (int) $lead->agent_id : null,
                    'responsible_name' => $lead->agent?->user?->name,
                    'responsible_phone' => $lead->agent?->phone,
                    'since' => $start,
                    'age_working_minutes' => $elapsed,
                    'reason' => 'Assigned lead has no meaningful human activity within the configured first-contact window.',
                    'url' => '/leads/' . $lead->id,
                ];
            })->filter()->values()->all();
    }

    private function followupAttention(): array
    {
        $escalationMinutes = $this->intSetting('followup_escalation_hours', 4) * 60;

        return Followup::with(['lead.project', 'agent.user'])
            ->where('status', 'pending')
            ->where('scheduled_for', '<', now())
            ->orderBy('scheduled_for')
            ->get()
            ->map(function (Followup $task) use ($escalationMinutes) {
                $elapsed = $this->workingMinutesSince($task->scheduled_for);
                return [
                    'kind' => 'followup_overdue',
                    'severity' => ($task->escalated_flag || $elapsed >= $escalationMinutes) ? 'critical' : 'attention',
                    'followup_id' => (int) $task->id,
                    'lead_id' => (int) $task->lead_id,
                    'lead_name' => $task->lead?->customer_name ?: 'Lead #' . $task->lead_id,
                    'project' => $task->lead?->project?->name,
                    'responsible_agent_id' => $task->agent_id ? (int) $task->agent_id : null,
                    'responsible_name' => $task->agent?->user?->name,
                    'responsible_phone' => $task->agent?->phone,
                    'since' => $task->scheduled_for,
                    'age_working_minutes' => $elapsed,
                    'action_type' => $task->action_type,
                    'reason' => $task->escalated_flag
                        ? 'Pending follow-up is already escalated.'
                        : 'Pending follow-up is overdue.',
                    'url' => '/leads/' . $task->lead_id,
                ];
            })->values()->all();
    }

    private function workforceAttention(): array
    {
        if (! $this->isInsideWorkingWindow(now())) return [];

        $idleAttention = 60;
        $idleCritical = 180;

        return Attendance::with('agent.user')
            ->whereDate('shift_date', today())
            ->whereNotNull('checked_in_at')
            ->whereNull('checked_out_at')
            ->whereHas('agent.user', fn ($q) => $q->where('is_on_payroll', true))
            ->get()
            ->map(function (Attendance $attendance) use ($idleAttention, $idleCritical) {
                $agent = $attendance->agent;
                if (! $agent) return null;

                $last = Activity::where('agent_id', $agent->id)
                    ->whereNotIn('type', self::SYSTEM_ACTIVITY_TYPES)
                    ->where(function ($q) {
                        $q->whereNull('action_source')
                          ->orWhereNotIn('action_source', ['system', 'automation', 'scheduled_job']);
                    })
                    ->max('logged_at');

                $basis = $last ? Carbon::parse($last) : $attendance->checked_in_at;
                $idle = $this->workingMinutesSince($basis);
                if ($idle < $idleAttention) return null;

                return [
                    'kind' => 'workforce_inactive',
                    'severity' => $idle >= $idleCritical ? 'critical' : 'attention',
                    'responsible_agent_id' => (int) $agent->id,
                    'responsible_name' => $agent->user?->name ?: 'Agent #' . $agent->id,
                    'responsible_phone' => $agent->phone,
                    'checked_in_at' => $attendance->checked_in_at,
                    'last_meaningful_activity_at' => $last ? Carbon::parse($last) : null,
                    'age_working_minutes' => $idle,
                    'reason' => $last
                        ? 'Checked-in payroll employee has no meaningful CRM activity recently.'
                        : 'Checked-in payroll employee has no meaningful CRM activity recorded since check-in.',
                ];
            })->filter()->values()->all();
    }

    private function hasMeaningfulHumanActivity(int $leadId, Carbon $since): bool
    {
        return Activity::where('lead_id', $leadId)
            ->where('logged_at', '>=', $since)
            ->whereNotIn('type', self::SYSTEM_ACTIVITY_TYPES)
            ->where(function ($q) {
                $q->whereNull('action_source')
                  ->orWhereNotIn('action_source', ['system', 'automation', 'scheduled_job']);
            })
            ->exists();
    }

    private function workingMinutesSince(Carbon $from, ?Carbon $to = null): int
    {
        $to ??= now();
        $from = $from->copy();
        $to = $to->copy();
        if ($from->gte($to)) return 0;

        [$startHour, $startMinute] = array_map('intval', explode(':', $this->businessHours->startTime()));
        [$endHour, $endMinute] = array_map('intval', explode(':', $this->businessHours->endTime()));
        $days = $this->businessHours->workingDays();
        $cursor = $from->copy()->startOfDay();
        $lastDay = $to->copy()->startOfDay();
        $minutes = 0;

        while ($cursor->lte($lastDay)) {
            if (in_array($cursor->dayOfWeek, $days, true)) {
                $windowStart = $cursor->copy()->setTime($startHour, $startMinute);
                $windowEnd = $cursor->copy()->setTime($endHour, $endMinute);
                $start = $from->gt($windowStart) ? $from->copy() : $windowStart;
                $end = $to->lt($windowEnd) ? $to->copy() : $windowEnd;
                if ($end->gt($start)) $minutes += (int) $start->diffInMinutes($end);
            }
            $cursor->addDay();
        }

        return $minutes;
    }

    private function isInsideWorkingWindow(Carbon $at): bool
    {
        if (! in_array($at->dayOfWeek, $this->businessHours->workingDays(), true)) return false;
        $time = $at->format('H:i');
        return $time >= $this->businessHours->startTime() && $time < $this->businessHours->endTime();
    }

    private function finalStatusKeys(): array
    {
        return $this->settings->statuses(false)->filter(fn ($s) => (bool) $s->is_final)->pluck('key')->values()->all();
    }

    private function intSetting(string $key, int $default): int
    {
        return max(1, (int) $this->settings->get($key, $default));
    }

    private function guard(): void
    {
        app(SuperAdminService::class)->requireSuperAdmin();
    }
}
