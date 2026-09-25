<?php

namespace App\Services;

use App\Models\Activity;
use App\Models\Followup;
use App\Models\Lead;
use Illuminate\Support\Facades\DB;

/**
 * Single source of truth for processing an interaction with a lead.
 * Called by both:
 *   - ActivityController::store()              (📞 Log Activity button)
 *   - FollowupController::completeWithActivity() (✅ Done button)
 *
 * Everything downstream — activity creation, task completion, sibling
 * task cleanup, status auto-advance, next-action scheduling, brokerage
 * field updates, notifications — happens here exactly once.
 */
class LeadActivityProcessor
{
    public function __construct(
        private SettingsService $settings,
        private SmartFollowupService $smartFollowups,
        private LeadStatusService $statusService,
        private NotificationService $notifications,
    ) {}

    /**
     * Process an interaction.
     *
     * @param  Lead          $lead      The lead being worked on.
     * @param  Followup|null $followup  The specific task being completed (✅ Done),
     *                                  or null (📞 Log Activity).
     * @param  array         $payload   Validated request data.
     *
     * @return array{activity: Activity, scheduled: ?Followup, advanced: bool, advanced_to: ?string}
     *
     * @throws \DomainException  On validation/business rule violations.
     */
    public function process(Lead $lead, ?Followup $followup, array $payload): array
    {
        $type = $payload['type'];

        /* ---- 1. Resolve activity type + outcome ---- */
        $activityType = $this->settings->activityTypeByKey($type);
        if (! $activityType) {
            throw new \DomainException('Unknown activity type.');
        }

        $outcome = null;
        if ($activityType->requires_outcome) {
            if (empty($payload['outcome_key'])) {
                throw new \DomainException('Please select an outcome.');
            }
            $outcome = $this->settings->callOutcomeByKey($payload['outcome_key']);
            if (! $outcome) {
                throw new \DomainException('Unknown outcome.');
            }
        }

        /* ---- 2. First-touch + auto-advance decision ---- */
        $currentKey = $lead->statusKey();
        $firstTouchStage = in_array($currentKey, ['new', 'external_shared'], true);
        $shouldAdvance = false;
        $advanceToKey  = null;
        $firstTouchNeedsContacted = false;
        $visitSchedulingNeedsContacted = false;
        $visitContactMethod = null;

        if ($outcome && $outcome->suggestedStatus) {
            $suggestedKey = $outcome->suggestedStatus->key;
            $validationFrom = $firstTouchStage ? 'attempted' : $currentKey;
            if ($firstTouchStage && ! in_array($suggestedKey, ['attempted', 'lost', 'contacted'], true)) {
                $firstTouchNeedsContacted = true;
            }

            $isUncontactedVisitScheduling = in_array($currentKey, ['new', 'external_shared', 'attempted'], true)
                && $suggestedKey === 'visit_scheduled';

            if ($isUncontactedVisitScheduling) {
                $visitContactMethod = $payload['visit_contact_method'] ?? null;
                if (! in_array($visitContactMethod, ['call', 'whatsapp'], true)) {
                    throw new \DomainException('Please select how this site visit was scheduled: Call or WhatsApp.');
                }

                $type = $visitContactMethod;
                $visitSchedulingNeedsContacted = true;
            }

            // A first human action always leaves New/Shared Externally. If the
            // outcome proves customer contact or advances farther, the lead
            // records Attempted first and then continues through the normal
            // configured transition path.
            if ($suggestedKey !== $currentKey) {
                $allowed = $this->settings->allowedTransitionsFrom($validationFrom);
                $validPath = in_array($suggestedKey, $allowed, true);
                if ($firstTouchNeedsContacted || $visitSchedulingNeedsContacted) {
                    $validPath = in_array('contacted', $allowed, true)
                        && in_array($suggestedKey, $this->settings->allowedTransitionsFrom('contacted'), true);
                }

                if (! $validPath) {
                    throw new \DomainException(
                        'The selected outcome is configured to move this lead to '
                        . $this->settings->statusLabel($suggestedKey)
                        . ', but that transition is not allowed from the current lifecycle stage.'
                    );
                }

                $shouldAdvance = true;
                $advanceToKey  = $suggestedKey;
            }
        }

        /* ---- 3. Guard: visit_scheduled needs datetime ---- */
        if ($shouldAdvance && $advanceToKey === 'visit_scheduled'
            && empty($payload['visit_scheduled_at'])) {
            throw new \DomainException('Please provide the visit date and time.');
        }

        /* ---- 4. Guard: Lost needs a controlled reason ---- */
        if ($shouldAdvance && $advanceToKey === 'lost') {
            $trimmed = trim((string) ($payload['lost_reason'] ?? ''));
            if ($trimmed === '') {
                $payload['lost_reason'] = 'Marked Lost via outcome: '
                                        . ($outcome?->label ?? 'unknown');
            }

            // Since FIX24, LeadStatusService correctly requires a controlled
            // lost_reason_key. Manual Lost uses that field directly, but
            // outcome-driven Lost transitions also need to provide it.
            // For the standard permanent Lost outcomes, the outcome key is
            // deliberately aligned with the controlled reason key. Without
            // this mapping, the status service rejects the transition, the
            // exception is swallowed by the processor below, and the activity
            // is logged while the lead incorrectly remains active.
            $controlledLostKeys = [
                'wrong_number',
                'invalid_number',
                'fake_spam',
                'duplicate',
                'already_bought',
                'bought_elsewhere',
                'dnd',
                'permanently_not_interested',
                'outside_target_market',
                'project_unsuitable',
                'other_permanent',
            ];

            if (! empty($payload['lost_reason_key'])
                && \App\Services\LeadStatusService::lostReasonLabel($payload['lost_reason_key'])) {
                // Keep an explicitly supplied controlled reason.
            } elseif ($outcome && in_array($outcome->key, $controlledLostKeys, true)) {
                $payload['lost_reason_key'] = $outcome->key;
            }
        }

        /* ---- 5. Build booking / brokerage payload ---- */
        $bookingDetails   = [];
        $brokerageDetails = [];

        if ($shouldAdvance && $advanceToKey === 'booking') {
            if (empty($payload['booking_amount'])
                && (empty($payload['property_area_sqft']) || empty($payload['rate_per_sqft']))) {
                throw new \DomainException(
                    'Please enter area and rate, or provide the booking amount.'
                );
            }

            $bookingDetails = [
                'property_area_sqft'   => $payload['property_area_sqft']   ?? null,
                'rate_per_sqft'        => $payload['rate_per_sqft']        ?? null,
                'booking_amount'       => $payload['booking_amount']       ?? null,
                'booking_unit'         => $payload['booking_unit']         ?? null,
                'booking_payment_mode' => $payload['booking_payment_mode'] ?? null,
                'booking_date'         => $payload['booking_date']         ?? now()->toDateString(),
            ];
            $brokerageDetails = [
                'brokerage_percentage'  => $payload['brokerage_percentage']  ?? null,
                'brokerage_amount'      => $payload['brokerage_amount']      ?? null,
                'brokerage_expected_at' => $payload['brokerage_expected_at'] ?? null,
                'co_broker_name'        => $payload['co_broker_name']        ?? null,
            ];
        }

        /* ---- 6. Precompute outside transaction ---- */
        $whatsappKind = $payload['whatsapp_sent_kind'] ?? null;
        $alsoWhatsapp = ! empty($payload['also_whatsapp']) || in_array($whatsappKind, ['intro', 'details'], true);

        $customNextAt = ! empty($payload['custom_next_at'])
            ? \Carbon\Carbon::parse($payload['custom_next_at'])
            : null;

        /* ---- 7. Atomic write ---- */
        $result = DB::transaction(function () use (
            $lead, $followup, $payload, $outcome, $type,
            $shouldAdvance, $advanceToKey, $firstTouchNeedsContacted, $visitSchedulingNeedsContacted,
            $bookingDetails, $brokerageDetails,
            $alsoWhatsapp, $whatsappKind, $customNextAt
        ) {

            // 7a. Log the primary activity
            $activity = Activity::create([
                'lead_id'     => $lead->id,
                'agent_id'    => $lead->resolveLoggingAgentId(),
                'type'        => $type,
                'outcome'     => $outcome?->label ?? 'Logged',
                'outcome_key' => $outcome?->key,
                'action_source' => $payload['action_source'] ?? 'manual',
                'notes'       => $payload['notes'] ?? null,
                'logged_at'   => now(),
            ]);

            $lead->update(['last_activity_at' => now()]);

            // 7b. First human work is never allowed to leave the lead in New.
            // External sharing has its own branch; the first customer-facing
            // action from either New or Shared Externally enters Attempted.
            $firstTouchStage = in_array($lead->statusKey(), ['new', 'external_shared'], true);
            if ($firstTouchStage) {
                $this->statusService->change(
                    $lead,
                    'attempted',
                    null,
                    null,
                    null,
                    true,   // the activity just created is the qualifying touch
                    null,
                    null,
                    [],
                    [],
                    'automated',
                    true    // outcome scheduling happens once below
                );

                // A successful first interaction must pass through Contacted
                // before any later pipeline stage. This prevents a first-touch
                // outcome from bypassing the lifecycle order.
                if ($firstTouchNeedsContacted) {
                    $this->statusService->change(
                        $lead,
                        'contacted',
                        null,
                        null,
                        null,
                        true,
                        null,
                        null,
                        [],
                        [],
                        'automated',
                        true
                    );
                }
            }

            if (! $firstTouchStage && $visitSchedulingNeedsContacted) {
                $lead = $this->statusService->change(
                    $lead, 'contacted', null, null, null, true,
                    null, null, [], [], 'automated', true
                );
            }

            // 7c. If completing a specific task, mark it done
            if ($followup) {
                $followup->update(['status' => 'done', 'updated_at' => now()]);
            }

            // 7d. Complete all same-family pending tasks
            $siblingTypes = $this->siblingActionTypes($type);

            if (! empty($siblingTypes)) {
                $query = Followup::where('lead_id', $lead->id)
                    ->where('status', 'pending')
                    ->whereIn('action_type', $siblingTypes);

                // Exclude the followup we just closed — avoid double-updating it
                if ($followup) {
                    $query->where('id', '!=', $followup->id);
                }

                $query->update([
                    'status'     => 'done',
                    'updated_at' => now(),
                ]);
            }

            // 7e. Optional WhatsApp auto-send
            $waActivity = null;
            if ($alsoWhatsapp && $outcome && $type === 'call') {
                $waOutcomeKey = $whatsappKind === 'intro' ? 'wa_delivered_awaiting' : 'wa_sent_details';
                $waOutcome = $this->settings->callOutcomeByKey($waOutcomeKey);
                if ($waOutcome) {
                    $waActivity = Activity::create([
                        'lead_id'     => $lead->id,
                        'agent_id'    => $lead->resolveLoggingAgentId(),
                        'type'        => 'whatsapp',
                        'outcome'     => $waOutcome->label,
                        'outcome_key' => $waOutcome->key,
                        'action_source' => 'automated',
                        'notes'       => '📤 WhatsApp message sent (auto-logged)',
                        'logged_at'   => now(),
                    ]);
                }
            }

            // 7f. Status advance — transition failures abort the transaction;
            // we never leave an activity logged while the status remains behind.
            $scheduled = null;
            $advanced  = false;

            if ($shouldAdvance && $advanceToKey) {
                $this->statusService->change(
                    $lead,
                    $advanceToKey,
                    $payload['lost_reason'] ?? null,
                    $payload['visit_scheduled_at'] ?? null,
                    null,          // revivalReason
                    false,         // skipActivityCheck
                    null,          // nurtureScheduledAt
                    $payload['lost_reason_key'] ?? null, // lostReasonKey
                    $bookingDetails,
                    $brokerageDetails,
                    'automated',   // actionSource
                );
                $advanced = true;

                if ($advanceToKey === 'visit_scheduled') {
                    // Site visit scheduling is the telecaller's handoff boundary.
                    // The sales agent remains the Lead owner and receives the
                    // normal visit-scheduled notification/follow-ups.
                    app(\App\Services\ContactLeadHandoffService::class)
                        ->releaseTelecallerAtSiteVisit($lead->fresh());
                }
            }

            // 7g. Next-action scheduling
            //     Structural statuses get followups from autoCreateForStatusChange().
            //     Everything else falls through to the outcome's own next action.
            $structuralStatuses = [
                'visit_scheduled', 'visit_done', 'negotiation', 'booking', 'lost',
            ];
            $advancedToStructural = $advanced
                && in_array($advanceToKey, $structuralStatuses, true);

            if (! $advancedToStructural) {
                $schedulingOutcome = $outcome;
                $schedulingActivity = $activity;

                // One next task: failed call keeps retry; connected call + details sent follows the WhatsApp details.
                if ($waActivity && $outcome && $outcome->is_connected && $whatsappKind === 'details') {
                    $waOutcome = $this->settings->callOutcomeByKey('wa_sent_details');
                    if ($waOutcome) {
                        $schedulingOutcome = $waOutcome;
                        $schedulingActivity = $waActivity;
                    }
                }

                // Brokerage-specific field updates
                if ($outcome) {
                    if ($outcome->key === 'brok_received_full') {
                        $lead->update([
                            'brokerage_status'      => 'received',
                            'brokerage_received_at' => now()->toDateString(),
                        ]);
                    } elseif ($outcome->key === 'brok_received_partial') {
                        $lead->update(['brokerage_status' => 'invoiced']);
                    } elseif ($outcome->key === 'brok_disputed') {
                        $lead->update(['brokerage_status' => 'disputed']);
                    }
                }

                // The outcome's own next action
                if ($schedulingOutcome) {
                    $scheduled = $this->smartFollowups->scheduleForOutcome(
                        $lead, $schedulingActivity, $schedulingOutcome
                    );

                    if ($scheduled && $customNextAt) {
                        $scheduled->update(['scheduled_for' => $customNextAt]);
                        $scheduled->refresh();
                    }
                }
            }

            return [
                'activity'    => $activity,
                'wa_activity' => $waActivity,
                'scheduled'   => $scheduled,
                'advanced'    => $advanced,
                'advanced_to' => $advanceToKey,
            ];
        });

        /* ---- 8. Notifications (best-effort, outside transaction) ---- */
        try {
            if ($outcome && $outcome->context_action_key === 'brokerage_followup') {
                $fresh = $lead->fresh();

                if ($outcome->key === 'brok_received_full') {
                    $this->notifications->notifyBrokerageReceived($fresh, 'full');
                } elseif ($outcome->key === 'brok_received_partial') {
                    $this->notifications->notifyBrokerageReceived($fresh, 'partial');
                } elseif ($outcome->key === 'brok_disputed') {
                    $this->notifications->notifyBrokerageDisputed($fresh);
                }
            }
        } catch (\Throwable $e) {
            // never break the main flow
        }

        return $result;
    }

    /**
     * Action types that belong to the same "family" as the given
     * activity type. When the activity is logged, any pending task of
     * the same family is marked done — the work they described has
     * been performed.
     *
     * @return string[]
     */
    private function siblingActionTypes(string $activityType): array
    {
        return match ($activityType) {
            'call', 'followup_call', 'retry_call' => [
                'followup_call', 'retry_call', 'call',
            ],
            'whatsapp', 'whatsapp_followup' => [
                'whatsapp_followup',
            ],
            'email', 'send_details', 'send_brochure' => [
                'send_details', 'send_brochure',
            ],
            'site_visit', 'visit_reminder', 'confirm_site_visit' => [
                'visit_reminder', 'confirm_site_visit',
            ],
            'meeting', 'visit_feedback_call', 'visit_outcome_call', 'post_visit_call' => [
                'visit_feedback_call', 'visit_outcome_call', 'post_visit_call',
            ],
            default => [],
        };
    }

    /**
     * Build the flash message shared by both controllers.
     */
    public function buildFlashMessage(array $result, string $base = '📝 Activity logged!'): string
    {
        $msg = $base;

        if (! empty($result['wa_activity'])) {
            $msg .= ' 📤 WhatsApp sent.';
        }

        if (! empty($result['advanced']) && ! empty($result['advanced_to'])) {
            $msg .= ' → Status: ' . $this->settings->statusLabel($result['advanced_to']);
        } elseif (! empty($result['scheduled'])) {
            $next = $this->settings->actionTypeByKey($result['scheduled']->action_type);
            $msg .= ' → Next: ' . ($next?->label ?? $result['scheduled']->action_type)
                  . ' on ' . $result['scheduled']->scheduled_for->format('d M, H:i');
        }

        return $msg;
    }
}