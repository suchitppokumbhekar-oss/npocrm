<?php

namespace App\Services;

use App\Models\Call;
use App\Models\Config\CallOutcome;
use App\Models\Contact;
use App\Models\ContactFollowup;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class ContactFollowupService
{
    public function __construct(
        private SettingsService $settings,
        private BusinessHoursService $hours,
    ) {}

    /**
     * Reconcile the Contact's current work item with the latest recorded
     * outcome. Historical calls and completed Contact actions remain untouched;
     * only the current pending work projection is repaired.
     *
     * This is intentionally idempotent: if the existing pending action already
     * matches the latest outcome-driven action and owner, nothing is changed.
     */
    public function reconcileContactWork(Contact $contact): ?ContactFollowup
    {
        return DB::transaction(function () use ($contact) {
            $locked = Contact::query()->lockForUpdate()->find($contact->id);
            if (! $locked) return null;

            $pending = ContactFollowup::query()
                ->where('contact_id', $locked->id)
                ->where('status', 'pending')
                ->orderBy('scheduled_for')
                ->orderBy('id')
                ->get();

            if ($locked->isPromoted() || ! $locked->isCallable()) {
                if ($pending->isNotEmpty()) {
                    ContactFollowup::whereIn('id', $pending->pluck('id')->all())
                        ->update(['status' => 'cancelled', 'updated_at' => now()]);
                }
                return null;
            }

            // Determine the latest business outcome from the two Contact-work
            // event streams. This also repairs old records where last_outcome_key
            // was not updated by an earlier implementation.
            $latestCall = Call::query()
                ->where('contact_id', $locked->id)
                ->whereNotNull('outcome_key')
                ->orderByDesc('called_at')
                ->orderByDesc('id')
                ->first();

            $latestAction = ContactFollowup::query()
                ->where('contact_id', $locked->id)
                ->where('status', 'done')
                ->whereNotNull('completion_outcome_key')
                ->orderByDesc('completed_at')
                ->orderByDesc('id')
                ->first();

            $callAt = $latestCall?->called_at;
            $actionAt = $latestAction?->completed_at;
            $latestOutcomeKey = null;

            if ($latestAction && (! $latestCall || ($actionAt && $callAt && $actionAt->gte($callAt)))) {
                $latestOutcomeKey = (string) $latestAction->completion_outcome_key;
            } elseif ($latestCall) {
                $latestOutcomeKey = (string) $latestCall->outcome_key;
            } else {
                $latestOutcomeKey = (string) ($locked->last_outcome_key ?? '');
            }

            if ($latestOutcomeKey !== '') {
                // Repair the Contact's current state projection as well as the
                // outcome key. This is the same mapping used when a new action
                // is completed, so old/test-era records can converge safely.
                $this->applyContactOutcome($locked, $latestOutcomeKey);
                $locked->refresh();
            }

            if ($latestOutcomeKey === '') {
                // There is no outcome from which to derive work. Do not invent
                // a task merely because a Contact exists.
                return $pending->first();
            }

            $outcome = $this->settings->callOutcomeByKey($latestOutcomeKey);
            if (! $outcome) {
                // Configuration may have been retired after the historical
                // outcome was recorded. Preserve history and any existing work
                // rather than guessing a new business action.
                return $pending->first();
            }

            $expectedActionKey = $outcome->nextActionType?->key ?: 'followup_call';
            $delayHours = max(0, (int) $outcome->next_action_delay_hours);
            try {
                $expectedWhen = $this->scheduleNextActionAt($locked, $outcome, $delayHours);
            } catch (\DomainException $e) {
                // Example: a historical site-visit outcome exists but its actual
                // appointment datetime was never captured. Never crash the
                // Contact page or invent a date; leave existing work visible so
                // the caller can correct the missing appointment information.
                return $pending->first();
            }
            $assignedAgentId = (int) ($locked->assigned_to_agent_id ?: ($pending->first()?->agent_id ?? 0));

            // Terminal outcomes may intentionally leave no immediate work.
            if (in_array($locked->status, ['invalid', 'dnc', 'converted'], true)) {
                if ($pending->isNotEmpty()) {
                    ContactFollowup::whereIn('id', $pending->pluck('id')->all())
                        ->update(['status' => 'cancelled', 'updated_at' => now()]);
                }
                return null;
            }

            $matching = $pending->first(function ($item) use ($expectedActionKey, $assignedAgentId) {
                return $item->action_type === $expectedActionKey
                    && (int) $item->agent_id === $assignedAgentId;
            });

            if ($matching) {
                // One authoritative pending item only. Cancel stale duplicates,
                // but do not alter the valid item's original audit timestamps.
                $duplicateIds = $pending->reject(fn ($item) => $item->id === $matching->id)->pluck('id')->all();
                if ($duplicateIds) {
                    ContactFollowup::whereIn('id', $duplicateIds)
                        ->update(['status' => 'cancelled', 'updated_at' => now()]);
                }
                return $matching;
            }

            // If there is no owner yet, keep history intact but do not create an
            // orphaned work item that nobody can receive.
            if ($assignedAgentId <= 0) {
                return $pending->first();
            }

            if ($pending->isNotEmpty()) {
                ContactFollowup::whereIn('id', $pending->pluck('id')->all())
                    ->update(['status' => 'cancelled', 'updated_at' => now()]);
            }

            return ContactFollowup::create([
                'contact_id' => $locked->id,
                'agent_id' => $assignedAgentId,
                'scheduled_for' => $expectedWhen,
                'action_type' => $expectedActionKey,
                'priority' => $outcome->priority ?: 'normal',
                'status' => 'pending',
                'auto_created' => true,
                'source_call_id' => $latestCall?->id,
                'notes' => 'Reconciled from latest outcome: ' . $outcome->label,
            ]);
        });
    }

    /** Cancel pending contact work because the contact has been converted or otherwise closed. */
    public function cancelPending(Contact $contact): int
    {
        return ContactFollowup::where('contact_id', $contact->id)
            ->where('status', 'pending')
            ->update(['status' => 'cancelled', 'updated_at' => now()]);
    }

    /**
     * Replace the previous automatic retry with the next retry implied by the
     * call outcome. A Contact follow-up is intentionally separate from Lead
     * follow-ups: once promoted, Contact work is cancelled and the Lead owns the
     * continuing work through the normal /tasks system.
     */
    public function scheduleForCall(Contact $contact, int $agentId, Call $call, ?CallOutcome $outcome): ?ContactFollowup
    {
        return DB::transaction(function () use ($contact, $agentId, $call, $outcome) {
            $locked = Contact::query()->lockForUpdate()->find($contact->id);
            if (! $locked || $locked->isPromoted() || ! $locked->isCallable()) {
                $this->cancelPending($contact);
                return null;
            }

            ContactFollowup::where('contact_id', $locked->id)
                ->where('status', 'pending')
                ->update(['status' => 'cancelled', 'updated_at' => now()]);

            $actionKey = $outcome?->nextActionType?->key ?: 'followup_call';
            $delayHours = $outcome
                ? max(0, (int) $outcome->next_action_delay_hours)
                : 24;

            // These are terminal Contact outcomes. The caller should not be
            // forced to retry a number that is known to be invalid or DND, or
            // a contact explicitly marked not interested.
            if (in_array($locked->status, ['invalid', 'dnc', 'converted'], true)) {
                return null;
            }

            // Not-interested is quiet operationally, but the configured outcome
            // may deliberately create a long-horizon reactivation action.
            // It stays out of the immediate queue until that action is due.

            $locked->refresh();
            $when = $this->scheduleNextActionAt($locked, $outcome, $delayHours);

            return ContactFollowup::create([
                'contact_id' => $locked->id,
                'agent_id' => $agentId,
                'scheduled_for' => $when,
                'action_type' => $actionKey,
                'priority' => $outcome?->priority ?: 'normal',
                'status' => 'pending',
                'auto_created' => true,
                'source_call_id' => $call->id,
                'notes' => $outcome ? ('After outcome: ' . $outcome->label) : 'Automatic call retry',
            ]);
        });
    }

    public function dueForAgent(int $agentId, int $limit = 100)
    {
        return ContactFollowup::with(['contact.project', 'contact.agent.user'])
            ->where('agent_id', $agentId)
            ->where('status', 'pending')
            ->where('scheduled_for', '<=', now()->endOfDay())
            ->whereHas('contact', function ($q) {
                $q->whereNotIn('status', ['dnc', 'invalid', 'converted'])
                  ->whereNull('promoted_to_lead_id');
            })
            ->orderBy('scheduled_for')
            ->limit($limit)
            ->get();
    }

    /**
     * Complete a non-call Contact action and immediately establish the next
     * configured action. This is the Contact equivalent of the Lead task
     * completion flow: the caller records what they actually did, records the
     * result, and the CRM owns the next-action scheduling.
     */
    public function completeAction(ContactFollowup $followup, int $agentId, ?string $outcomeKey, ?string $notes = null, ?string $siteVisitScheduledAt = null): array
    {
        return DB::transaction(function () use ($followup, $agentId, $outcomeKey, $notes, $siteVisitScheduledAt) {
            $lockedFollowup = ContactFollowup::query()->lockForUpdate()->find($followup->id);
            if (! $lockedFollowup || $lockedFollowup->status !== 'pending') {
                throw new \DomainException('This Contact action is already completed or no longer active.');
            }

            $contact = Contact::query()->lockForUpdate()->find($lockedFollowup->contact_id);
            if (! $contact || $contact->isPromoted() || ! $contact->isCallable()) {
                $lockedFollowup->update(['status' => 'cancelled', 'updated_at' => now()]);
                throw new \DomainException('This Contact is no longer an active caller work item.');
            }

            $outcome = $outcomeKey
                ? $this->settings->callOutcomesForContactWork($contact, $lockedFollowup->action_type)->firstWhere('key', $outcomeKey)
                : null;

            if ($outcomeKey && ! $outcome) {
                throw new \DomainException('The selected outcome is not valid for this action and Contact stage.');
            }

            $visitAnchor = (string) ($outcome?->next_action_anchor ?? 'now');
            $requiresVisit = (bool) ($outcome?->requires_site_visit_datetime ?? false)
                || $outcomeKey === 'site_visit_scheduled'
                || in_array($visitAnchor, ['visit_before', 'visit_after'], true);
            if ($requiresVisit) {
                // Existing customer facts are authoritative and reusable. A later
                // action must not force the caller to type the same appointment
                // again. A submitted value is treated as an intentional update;
                // otherwise the existing appointment is reused automatically.
                $visitInput = $siteVisitScheduledAt ?: $contact->site_visit_scheduled_at;
                if (! $visitInput) {
                    throw new \DomainException('Site visit date and time are required for this outcome.');
                }
                $visitAt = $visitInput instanceof Carbon
                    ? $visitInput->copy()
                    : Carbon::parse($visitInput);
                if ($visitAt->lte(now())) {
                    throw new \DomainException('Site visit date and time must be in the future.');
                }
                if (! $contact->site_visit_scheduled_at || Carbon::parse($contact->site_visit_scheduled_at)->ne($visitAt)) {
                    $contact->update(['site_visit_scheduled_at' => $visitAt, 'updated_at' => now()]);
                }
            }
            if ($outcomeKey === 'site_visit_cancelled') {
                $contact->update(['site_visit_scheduled_at' => null, 'updated_at' => now()]);
            }

            $lockedFollowup->update([
                'status' => 'done',
                'completed_at' => now(),
                'completed_by_agent_id' => $agentId,
                'completion_outcome_key' => $outcomeKey,
                'completion_notes' => $notes,
                'updated_at' => now(),
            ]);

            $this->applyContactOutcome($contact, $outcomeKey);

            // Rebuild the next action from the outcome. This cancels only any
            // stale duplicate pending item for the same Contact; the completed
            // action remains an audit record.
            ContactFollowup::where('contact_id', $contact->id)
                ->where('status', 'pending')
                ->update(['status' => 'cancelled', 'updated_at' => now()]);

            if (in_array($contact->status, ['invalid', 'dnc', 'converted'], true)) {
                return ['next' => null, 'outcome' => $outcome];
            }

            $actionKey = $outcome?->nextActionType?->key ?: 'followup_call';
            $delayHours = $outcome
                ? max(0, (int) $outcome->next_action_delay_hours)
                : 24;
            $when = $this->scheduleNextActionAt($contact, $outcome, $delayHours);

            $next = ContactFollowup::create([
                'contact_id' => $contact->id,
                'agent_id' => $agentId,
                'scheduled_for' => $when,
                'action_type' => $actionKey,
                'priority' => $outcome?->priority ?: 'normal',
                'status' => 'pending',
                'auto_created' => true,
                'notes' => $outcome ? ('After action outcome: ' . $outcome->label) : 'Automatic next action',
            ]);

            return ['next' => $next, 'outcome' => $outcome];
        });
    }

    private function scheduleNextActionAt(Contact $contact, ?CallOutcome $outcome, int $delayHours): Carbon
    {
        $anchor = (string) ($outcome?->next_action_anchor ?? 'now');
        $delayMinutes = max(0, $delayHours) * 60;
        $base = now();

        if ($anchor === 'visit_before') {
            if (! $contact->site_visit_scheduled_at) {
                throw new \DomainException('A site visit date and time must be recorded before scheduling the visit-related next action.');
            }
            $base = $contact->site_visit_scheduled_at->copy()->subMinutes($delayMinutes);
        } elseif ($anchor === 'visit_after') {
            if (! $contact->site_visit_scheduled_at) {
                throw new \DomainException('A site visit date and time must be recorded before scheduling the visit-related next action.');
            }
            $base = $contact->site_visit_scheduled_at->copy()->addMinutes($delayMinutes);
        } else {
            $base = now()->addMinutes($delayMinutes);
        }

        return $this->hours->automatic($base->lessThan(now()) ? now() : $base);
    }

    /** Keep Contact status aligned with the existing configured outcome vocabulary. */
    private function applyContactOutcome(Contact $contact, ?string $outcomeKey): void
    {
        if (! $outcomeKey) return;

        $status = match ($outcomeKey) {
            'not_interested', 'visit_not_interested' => 'not_interested',
            'wrong_number' => 'invalid',
            'dnc' => 'dnc',
            'interested', 'wa_replied_positive', 'asked_details', 'site_visit_scheduled' => 'interested',
            default => null,
        };

        $attributes = [
            'last_outcome_key' => $outcomeKey,
            'updated_at' => now(),
        ];
        if ($status) {
            $attributes['status'] = $status;
        }
        $contact->update($attributes);
    }
}
