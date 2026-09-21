<?php

namespace App\Services;

use App\Models\Followup;
use App\Models\Lead;
use Carbon\Carbon;

class FollowupService
{
    public function __construct(
        private SettingsService $settings,
        private BusinessHoursService $hours,
    ) {}

    /**
     * Auto-create follow-ups when a lead's status changes.
     */
    public function autoCreateForStatusChange(Lead $lead, string $newStatusKey): void
    {
        Followup::where('lead_id', $lead->id)
            ->where('status', 'pending')
            ->where('auto_created', true)
            ->update(['status' => 'cancelled', 'updated_at' => now()]);

        if (in_array($newStatusKey, ['booking', 'lost'], true)) {
            Followup::where('lead_id', $lead->id)
                ->where('status', 'pending')
                ->whereIn('action_type', ['check_shared_agent', 'check_site_team'])
                ->update(['status' => 'cancelled', 'updated_at' => now()]);
        }

        switch ($newStatusKey) {
            case 'attempted':
                // Attempted is the first-touch stage. If it was reached by a
                // direct controlled status correction rather than the activity
                // processor, make sure the lead still has a next action.
                if (! $lead->pendingFollowups()->exists()) {
                    $this->scheduleForAgent(
                        $lead,
                        $lead->resolveLoggingAgentId(),
                        now()->addMinutes(max(1, (int) $this->settings->get('first_contact_delay_minutes', 15))),
                        'followup_call',
                        'high',
                        true
                    );
                }
                break;

            case 'visit_scheduled':
                $this->createVisitFollowups($lead);
                break;

            case 'visit_done':
                $this->scheduleForAgent($lead, $lead->resolveLoggingAgentId(), now()->addDay()->setTime(10, 0), 'visit_outcome_call', 'high', true);
                break;

            case 'negotiation':
                $this->scheduleForAgent($lead, $lead->resolveLoggingAgentId(), now()->addDays(2), 'negotiation_followup', 'high', true);
                break;

            case 'booking':
                $this->scheduleForAgent($lead, $lead->resolveLoggingAgentId(), now()->addDays(3), 'thank_you_call', 'normal', true);
                break;

            case 'lost':
                // Lost is terminal. Reactivation is explicit nurture only.
                break;
        }
    }

    public function createVisitFollowups(Lead $lead): void
    {
        if (! $lead->visit_scheduled_at) {
            $this->scheduleForAgent($lead, $lead->resolveLoggingAgentId(), now()->addDay()->setTime(10, 0), 'visit_reminder', 'high', true);
            return;
        }

        $visitAt = Carbon::parse($lead->visit_scheduled_at);

        Followup::where('lead_id', $lead->id)
            ->where('status', 'pending')
            ->where('auto_created', true)
            ->update(['status' => 'cancelled', 'updated_at' => now()]);

        $confirmAt  = $visitAt->copy()->subHours(2);
        if ($confirmAt->isFuture()) {
            $this->createAuto($lead, $confirmAt, 'visit_reminder', 'high');
        }

        $feedbackAt = $visitAt->copy()->addHours(3);
        if ($feedbackAt->isFuture()) {
            $this->createAuto($lead, $feedbackAt, 'visit_feedback_call', 'high');
        }

        $outcomeAt = $visitAt->copy()->addDay()->setTime(10, 0);
        if ($outcomeAt->isFuture()) {
            $this->createAuto($lead, $outcomeAt, 'visit_outcome_call', 'normal');
        }
    }

    public function schedule(Lead $lead, $scheduledFor, string $actionTypeKey, string $priority = 'normal'): Followup
    {
        $when = $scheduledFor instanceof Carbon ? $scheduledFor->copy() : Carbon::parse($scheduledFor);

        return Followup::create([
            'lead_id'        => $lead->id,
            'agent_id'       => $lead->resolveLoggingAgentId(),
            'scheduled_for'  => $when,
            'action_type'    => $actionTypeKey,
            'priority'       => $priority,
            'status'         => 'pending',
            'escalated_flag' => false,
            'auto_created'   => false,
        ]);
    }

    public function scheduleForAgent(
        Lead $lead,
        int $agentId,
        $scheduledFor,
        string $actionTypeKey,
        string $priority = 'normal',
        bool $autoCreated = false
    ): Followup {
        $when = $scheduledFor instanceof Carbon ? $scheduledFor->copy() : Carbon::parse($scheduledFor);
        if ($autoCreated) $when = $this->hours->automatic($when);

        return Followup::create([
            'lead_id'        => $lead->id,
            'agent_id'       => $agentId,
            'scheduled_for'  => $when,
            'action_type'    => $actionTypeKey,
            'priority'       => $priority,
            'status'         => 'pending',
            'escalated_flag' => false,
            'auto_created'   => $autoCreated,
        ]);
    }

    private function createAuto(Lead $lead, $when, string $actionTypeKey, string $priority): Followup
    {
        $when = $when instanceof Carbon ? $when->copy() : Carbon::parse($when);
        $when = $this->hours->automatic($when);

        return Followup::create([
            'lead_id'        => $lead->id,
            'agent_id'       => $lead->resolveLoggingAgentId(),
            'scheduled_for'  => $when,
            'action_type'    => $actionTypeKey,
            'priority'       => $priority,
            'status'         => 'pending',
            'escalated_flag' => false,
            'auto_created'   => true,
        ]);
    }

    public function markDone(Followup $followup): void
    {
        $followup->update(['status' => 'done']);
    }
}