<?php

namespace App\Services;

use App\Models\Activity;
use App\Models\Config\CallOutcome;
use App\Models\Followup;
use App\Models\Lead;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class SmartFollowupService
{
    public function __construct(
        private SettingsService $settings,
        private BusinessHoursService $hours,
    ) {}

    public function scheduleForOutcome(Lead $lead, Activity $activity, CallOutcome $outcome): ?Followup
    {
        Followup::where('lead_id', $lead->id)
            ->where('status', 'pending')
            ->where('auto_created', true)
            ->update(['status' => 'cancelled', 'updated_at' => now()]);

        if ($this->settings->statusIsFinal($lead->statusKey()) && $outcome->category !== 'negative') {
            return null;
        }

        // Outcome configuration is the primary scheduling rule. If an active
        // lead outcome has no next action configured, never leave the lead
        // without a next action: fall back to a normal follow-up call after
        // one business-day-scale delay. Final statuses are already excluded
        // above, so this safeguard does not create work after closure.
        $actionType = $outcome->nextActionType;
        if (! $actionType) {
            $actionType = $this->settings->actionTypeByKey('followup_call')
                ?? $this->settings->actionTypes()->first();
        }

        if (! $actionType) {
            return null;
        }

        $delayHours = $outcome->nextActionType
            ? max(1, (int) $outcome->next_action_delay_hours)
            : 24;

        $raw = now()->addHours($delayHours);
        $scheduledFor = $this->hours->automatic($raw);

        return Followup::create([
            'lead_id'            => $lead->id,
            'agent_id'           => $lead->resolveLoggingAgentId(),
            'scheduled_for'      => $scheduledFor,
            'action_type'        => $actionType->key,
            'priority'           => $outcome->priority,
            'status'             => 'pending',
            'escalated_flag'     => false,
            'auto_created'       => true,
            'source_activity_id' => $activity->id,
        ]);
    }

    /**
     * Hard invariant: every non-final lead must have one explicit pending next
     * action. This is idempotent and re-checks the condition under a row lock so
     * concurrent transfer/completion/repair flows cannot create duplicate tasks.
     */
    public function ensureLeadHasNextAction(Lead $lead, int $delayHours = 24, string $priority = 'normal'): ?Followup
    {
        return DB::transaction(function () use ($lead, $delayHours, $priority) {
            $locked = Lead::query()->lockForUpdate()->find($lead->id);
            if (! $locked || $locked->isFinal() || ! $locked->agent_id) {
                return null;
            }

            if ($locked->pendingFollowups()->exists()) {
                return null;
            }

            $defaultActionKey = $this->settings->actionTypes()->firstWhere('key', 'followup_call')
                ? 'followup_call'
                : ($this->settings->actionTypes()->first()?->key ?? 'call');

            if (! $defaultActionKey) {
                return null;
            }

            $when = $this->hours->automatic(now()->addHours(max(1, $delayHours)));

            return Followup::create([
                'lead_id'        => $locked->id,
                'agent_id'       => $locked->resolveLoggingAgentId(),
                'scheduled_for'  => $when,
                'action_type'    => $defaultActionKey,
                'priority'       => $priority,
                'status'         => 'pending',
                'escalated_flag' => false,
                'auto_created'   => true,
            ]);
        });
    }

    public function ensureAllLeadsHaveNextAction(): int
    {
        $finalStatuses = $this->settings->statuses()
            ->where('is_final', true)
            ->pluck('key')
            ->all();

        $leadsWithoutNextAction = Lead::query()
            ->when(! empty($finalStatuses), fn ($q) => $q->whereNotIn('status', $finalStatuses))
            ->whereNotNull('agent_id')
            ->whereDoesntHave('followups', fn ($q) => $q->where('status', 'pending'))
            ->get();

        $fixed = 0;
        foreach ($leadsWithoutNextAction as $lead) {
            if ($this->ensureLeadHasNextAction($lead)) {
                $fixed++;
            }
        }

        return $fixed;
    }
}