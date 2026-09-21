<?php

namespace App\Services;

use App\Models\Activity;
use App\Models\Followup;
use App\Models\Agent;
use App\Models\Lead;
use Illuminate\Support\Facades\DB;

class LeadStatusService
{
    /** Controlled Lost reasons. Only nurture-eligible reasons may create future reactivation work. */
    public static function lostReasonOptions(): array
    {
        return [
            'nurture' => [
                ['key' => 'budget_not_ready', 'label' => 'Budget not suitable right now'],
                ['key' => 'timing_not_ready', 'label' => 'Timing not right / wants to buy later'],
                ['key' => 'family_decision', 'label' => 'Family decision pending'],
                ['key' => 'waiting_for_possession', 'label' => 'Waiting for possession / future move'],
                ['key' => 'comparing_projects', 'label' => 'Comparing projects / needs more time'],
                ['key' => 'temporarily_postponed', 'label' => 'Purchase temporarily postponed'],
                ['key' => 'customer_requested_callback', 'label' => 'Customer asked us to reconnect later'],
            ],
            'closed' => [
                ['key' => 'wrong_number', 'label' => 'Wrong number'],
                ['key' => 'invalid_number', 'label' => 'Invalid / unreachable number'],
                ['key' => 'fake_spam', 'label' => 'Fake / spam lead'],
                ['key' => 'duplicate', 'label' => 'Duplicate lead'],
                ['key' => 'already_bought', 'label' => 'Already bought property'],
                ['key' => 'bought_elsewhere', 'label' => 'Bought elsewhere'],
                ['key' => 'dnd', 'label' => 'DND / Do not contact'],
                ['key' => 'permanently_not_interested', 'label' => 'Not interested — permanently'],
                ['key' => 'outside_target_market', 'label' => 'Outside target market / service area'],
                ['key' => 'project_unsuitable', 'label' => 'Project permanently unsuitable'],
                ['key' => 'other_permanent', 'label' => 'Other — permanently closed'],
            ],
        ];
    }

    public static function lostReasonLabel(?string $key): ?string
    {
        foreach (self::lostReasonOptions() as $group) {
            foreach ($group as $option) {
                if ($option['key'] === $key) return $option['label'];
            }
        }
        return null;
    }

    public static function isNurtureEligible(?string $key): bool
    {
        if (! $key) return false;
        return collect(self::lostReasonOptions()['nurture'])->contains(fn ($item) => $item['key'] === $key);
    }

    public function __construct(
        private FollowupService $followups,
        private LeadAssignmentService $assignment,
        private SettingsService $settings,
        private NotificationService $notifications,
        private BookingControlService $bookingControls,
    ) {}

    public function change(
        Lead $lead,
        string $newStatusKey,
        ?string $lostReason = null,
        ?string $visitScheduledAt = null,
        ?string $revivalReason = null,
        bool $skipActivityCheck = false,
        ?string $nurtureScheduledAt = null,
        ?string $lostReasonKey = null,
        array $bookingDetails = [],
        array $brokerageDetails = [],
        string $actionSource = 'manual',
        bool $skipAutomation = false,
    ): Lead {
        if ($newStatusKey === 'new' && $this->hasHumanTouch($lead)) {
            throw new \DomainException('A lead with recorded human work cannot be returned to New.');
        }

        if (! $skipActivityCheck && ! $this->hasRecentActivity($lead)) {
            throw new \DomainException('Log a call/WhatsApp note within 15 min before changing status.');
        }

        $currentKey = $lead->statusKey();

        // A revival is an explicit administrative transition out of a final
        // state. It intentionally does not rely on a Final -> Active row in
        // lead_status_transitions; ordinary lifecycle transitions remain
        // governed by that table below.
        $currentIsFinal = $this->settings->statusIsFinal($currentKey);
        $newIsFinal     = $this->settings->statusIsFinal($newStatusKey);
        $isRevival      = $currentIsFinal && ! $newIsFinal && $revivalReason !== null;

        // New is a protected untouched state. The server must reject direct
        // jumps over Attempted/Contacted even if someone bypasses the UI and
        // submits a crafted status request. A revival to New is also rejected
        // when the lead already has recorded human work.
        if ($currentKey === 'new' && ! in_array($newStatusKey, ['attempted', 'external_shared', 'lost'], true)) {
            throw new \DomainException('A New lead must pass through Attempted before continuing the lifecycle.');
        }

        $allowed = $this->settings->allowedTransitionsFrom($currentKey);
        if (! in_array($newStatusKey, $allowed, true) && ! $isRevival) {
            $allowedLabels = array_map(fn ($k) => $this->settings->statusLabel($k), $allowed);
            $list = $allowedLabels ? implode(', ', $allowedLabels) : 'none';
            throw new \DomainException(
                'Invalid transition from ' . $this->settings->statusLabel($currentKey) . ". Allowed: {$list}"
            );
        }

        if ($newStatusKey === 'lost') {
            if (! $lostReasonKey || ! self::lostReasonLabel($lostReasonKey)) {
                throw new \DomainException('Please select a valid reason for marking this lead as Lost.');
            }
            if (self::isNurtureEligible($lostReasonKey) && $nurtureScheduledAt) {
                try {
                    if (\Carbon\Carbon::parse($nurtureScheduledAt)->isPast()) {
                        throw new \DomainException('Nurture date/time must be in the future.');
                    }
                } catch (\InvalidArgumentException $e) {
                    throw new \DomainException('Please provide a valid future nurture date/time.');
                }
            }
        }
        if ($newStatusKey === 'visit_scheduled' && empty($visitScheduledAt)) {
            throw new \DomainException('Please provide the visit date and time.');
        }

        $newLabel     = $this->settings->statusLabel($newStatusKey);
        $currentLabel = $this->settings->statusLabel($currentKey);

        $result = DB::transaction(function () use (
            $lead, $currentKey, $currentLabel, $newStatusKey, $newLabel,
            $lostReason, $lostReasonKey, $visitScheduledAt, $revivalReason, $isRevival,
            $nurtureScheduledAt, $newIsFinal,
            $bookingDetails, $brokerageDetails, $actionSource, $skipAutomation
        ) {

            $update = [
                'status'           => $newStatusKey,
                'previous_status'  => $currentKey,
                'last_activity_at' => now(),
            ];

            if ($isRevival) $update['lost_reason'] = null;
            if ($newStatusKey === 'lost') {
                $update['lost_reason_key'] = $lostReasonKey;
                $update['lost_reason'] = trim((string) $lostReason);
            }

            if ($newStatusKey === 'visit_scheduled' && $visitScheduledAt) {
                $update['visit_scheduled_at'] = \Carbon\Carbon::parse($visitScheduledAt);
            }

            if ($newStatusKey === 'booking') {
                $area = $bookingDetails['property_area_sqft'] ?? null;
                $rate = $bookingDetails['rate_per_sqft'] ?? null;
                $bookingAmount = $bookingDetails['booking_amount'] ?? null;
                if (! $bookingAmount && $area && $rate) {
                    $bookingAmount = round($area * $rate, 2);
                }

                $update['booking_amount']       = $bookingAmount;
                $update['property_area_sqft']   = $area;
                $update['rate_per_sqft']        = $rate;
                $update['booking_unit']         = $bookingDetails['booking_unit'] ?? null;
                $update['booking_payment_mode'] = $bookingDetails['booking_payment_mode'] ?? null;
                $update['booking_date']         = $bookingDetails['booking_date'] ?? now()->toDateString();

                $pct    = $brokerageDetails['brokerage_percentage']
                        ?? $this->settings->get('default_brokerage_percentage', 2);
                $amount = $brokerageDetails['brokerage_amount']
                        ?? ($pct && $bookingAmount ? round($bookingAmount * $pct / 100, 2) : null);

                $update['brokerage_percentage']  = $pct;
                $update['brokerage_amount']      = $amount;
                $update['brokerage_status']      = $brokerageDetails['brokerage_status'] ?? 'pending';
                $update['brokerage_expected_at'] = $brokerageDetails['brokerage_expected_at'] ?? now()->addDays(30)->toDateString();
                $update['co_broker_name']        = $brokerageDetails['co_broker_name'] ?? null;
            }

            $lead->update($update);
            
                        // If this is a revival (leaving a final status), cancel any
            // stage-specific tasks that only made sense at the old status.
            // `autoCreateForStatusChange` below cancels auto-created tasks,
            // but the reactivation_call created via schedule() has
            // auto_created = 0 and would otherwise survive as an orphan.
            if ($isRevival) {
                Followup::where('lead_id', $lead->id)
                    ->where('status', 'pending')
                    ->whereIn('action_type', [
                        'reactivation_call',
                        'thank_you_call',
                        'booking_confirmation',
                        'brokerage_followup',
                    ])
                    ->update(['status' => 'cancelled', 'updated_at' => now()]);
            }

            // Timeline notes
            if ($isRevival) {
                $outcome = "🔄 Lead revived → {$newLabel}";
                $notes   = "Previously {$currentLabel} → revived at {$newLabel}";
            } elseif ($newStatusKey === 'booking') {
                $amount = number_format((float) ($update['booking_amount'] ?? 0), 2);
                $outcome = "🎉 Booking recorded";
                $notes   = "From {$currentLabel} → Booking";
                if (! empty($update['property_area_sqft']) && ! empty($update['rate_per_sqft'])) {
                    $notes .= "\nArea: " . number_format((float) $update['property_area_sqft'], 0) . " sq ft";
                    $notes .= " × ₹" . number_format((float) $update['rate_per_sqft'], 0) . " / sq ft";
                }
                $notes .= "\nValue: ₹{$amount}";
                if (! empty($update['booking_unit']))         $notes .= "\nUnit: {$update['booking_unit']}";
                if (! empty($update['booking_payment_mode'])) $notes .= "\nPayment: {$update['booking_payment_mode']}";
                if (! empty($update['booking_date']))         $notes .= "\nDate: {$update['booking_date']}";
                if (! empty($update['brokerage_amount'])) {
                    $notes .= "\nBrokerage: {$update['brokerage_percentage']}% = ₹" . number_format((float) $update['brokerage_amount'], 2);
                }
                if (! empty($update['co_broker_name'])) $notes .= "\nCo-broker: {$update['co_broker_name']}";
            } else {
                $outcome = "Changed to {$newLabel}";
                $notes   = "From {$currentLabel} → {$newLabel}";
            }

            if ($isRevival && $revivalReason)     $notes .= "\nWhy: " . trim($revivalReason);
            if ($newStatusKey === 'lost') {
                $label = self::lostReasonLabel($lostReasonKey);
                if ($label) $notes .= "\nLost reason: " . $label;
                if ($lostReason) $notes .= "\nNotes: " . trim($lostReason);
                if (self::isNurtureEligible($lostReasonKey)) {
                    $notes .= $nurtureScheduledAt ? "\nNurture: scheduled for " . \Carbon\Carbon::parse($nurtureScheduledAt)->format('d M Y, H:i') : "\nNurture: not scheduled";
                } else {
                    $notes .= "\nNurture: not eligible for this reason";
                }
            }
            if ($newStatusKey === 'visit_scheduled' && $visitScheduledAt) {
                $visit = \Carbon\Carbon::parse($visitScheduledAt);
                $notes .= "\nVisit scheduled: " . $visit->format('d M Y, H:i');
            }

            Activity::create([
                'lead_id'     => $lead->id,
                'agent_id'    => $lead->resolveLoggingAgentId(),
                'type'        => 'status_change',
                'outcome'     => $outcome,
                'outcome_key' => $newStatusKey,
                'action_source' => $actionSource,
                'notes'       => $notes,
                'logged_at'   => now(),
            ]);

            if ($newIsFinal) {
                Followup::where('lead_id', $lead->id)
                    ->where('status', 'pending')
                    ->update(['status' => 'cancelled', 'updated_at' => now()]);
            }

            if (! $skipAutomation) {
                $this->followups->autoCreateForStatusChange($lead, $newStatusKey);
            }

            if ($newStatusKey === 'lost' && self::isNurtureEligible($lostReasonKey) && $nurtureScheduledAt) {
                $this->followups->scheduleNurture($lead, $nurtureScheduledAt);
            }

            // Booking is a controlled final-state record. The lifecycle status
            // remains Booking, while BookingControl tracks approval separately.
            // Unrestricted Admins may finalize their own direct/admin booking;
            // normal agent-driven bookings remain pending until approved.
            if ($newStatusKey === 'booking') {
                $this->bookingControls->ensureForBookedLead($lead->fresh(), $update);
            }

            if ($newIsFinal) {
                $this->assignment->releaseForFinalStatus($lead);
            }

            return $lead->fresh(['agent.user', 'project']);
        });

        // ------- NOTIFICATIONS (outside transaction, best-effort) -------
        try {
            if ($newStatusKey === 'visit_scheduled') {
                $this->notifications->notifyVisitScheduled($result);
            }
            if ($newStatusKey === 'booking') {
                $this->notifications->notifyBookingRecorded($result);
            }
            if ($newStatusKey === 'lost') {
                $this->notifications->notifyLeadLost($result);
            }
            if ($isRevival) {
                $this->notifications->notifyLeadRevived($result);
            }
        } catch (\Throwable $e) {
            // never break status change because notification failed
        }

        return $result;
    }

    /**
     * Admin-only correction of a Lost lead's controlled reason and optional nurture schedule.
     * This does not change the lead lifecycle status; it corrects classification data safely.
     */
    public function updateLostReason(
        Lead $lead,
        string $lostReasonKey,
        ?string $lostReason = null,
        bool $nurtureEnabled = false,
        ?string $nurtureScheduledAt = null,
    ): Lead {
        if (! $lead->isLost()) {
            throw new \DomainException('This lead is not currently Lost.');
        }

        $newLabel = self::lostReasonLabel($lostReasonKey);
        if (! $newLabel) {
            throw new \DomainException('Please select a valid Lost reason.');
        }

        $nurtureEligible = self::isNurtureEligible($lostReasonKey);
        if ($nurtureEnabled && ! $nurtureEligible) {
            throw new \DomainException('This Lost reason is permanently closed and cannot be added to Nurture.');
        }

        if ($nurtureEnabled) {
            if (! $nurtureScheduledAt) {
                throw new \DomainException('Please provide a future nurture date and time.');
            }
            try {
                if (\Carbon\Carbon::parse($nurtureScheduledAt)->isPast()) {
                    throw new \DomainException('Nurture date/time must be in the future.');
                }
            } catch (\InvalidArgumentException $e) {
                throw new \DomainException('Please provide a valid future nurture date/time.');
            }
        }

        $oldKey = $lead->lost_reason_key;
        $oldLabel = self::lostReasonLabel($oldKey) ?? ($oldKey ?: 'Not classified');
        $oldNote = trim((string) $lead->lost_reason);

        return DB::transaction(function () use (
            $lead, $lostReasonKey, $lostReason, $nurtureEnabled, $nurtureScheduledAt,
            $oldKey, $oldLabel, $oldNote, $newLabel, $nurtureEligible
        ) {
            $lead->update([
                'lost_reason_key' => $lostReasonKey,
                'lost_reason'     => trim((string) $lostReason),
                'last_activity_at'=> now(),
            ]);

            $pendingNurture = Followup::where('lead_id', $lead->id)
                ->where('status', 'pending')
                ->where('action_type', 'reactivation_call')
                ->first();

            if (! $nurtureEligible || ! $nurtureEnabled) {
                Followup::where('lead_id', $lead->id)
                    ->where('status', 'pending')
                    ->where('action_type', 'reactivation_call')
                    ->update(['status' => 'cancelled', 'updated_at' => now()]);
            } else {
                $this->followups->scheduleNurture($lead, $nurtureScheduledAt);
            }

            $actorAgentId = Agent::where('user_id', session('user_id'))->value('id')
                ?: $lead->resolveLoggingAgentId();

            $notes = "Old reason: {$oldLabel}"
                . ($oldNote !== '' ? "\nOld notes: {$oldNote}" : '')
                . "\nNew reason: {$newLabel}";

            if ($lostReason) {
                $notes .= "\nNew notes: " . trim($lostReason);
            }

            if ($nurtureEligible && $nurtureEnabled) {
                $notes .= "\nNurture: scheduled for " . \Carbon\Carbon::parse($nurtureScheduledAt)->format('d M Y, H:i');
            } else {
                $notes .= "\nNurture: not scheduled / cancelled";
            }

            Activity::create([
                'lead_id'     => $lead->id,
                'agent_id'    => $actorAgentId,
                'type'        => 'status_change',
                'outcome'     => '✏️ Lost reason updated by Admin',
                'outcome_key' => 'lost_reason_updated',
                'action_source' => 'manual',
                'notes'       => $notes,
                'logged_at'   => now(),
            ]);

            return $lead->fresh(['agent.user', 'project']);
        });
    }

    /** Admin-only bulk classification correction for already-Lost leads. */
    public function updateLostReasonBulk(array $leadIds, string $lostReasonKey): int
    {
        if (! $leadIds) return 0;

        $label = self::lostReasonLabel($lostReasonKey);
        if (! $label) {
            throw new \DomainException('Please select a valid Lost reason.');
        }

        $leads = Lead::whereIn('id', array_map('intval', $leadIds))->get();
        if ($leads->count() !== count(array_unique(array_map('intval', $leadIds)))) {
            throw new \DomainException('One or more selected leads could not be found.');
        }
        if ($leads->contains(fn ($lead) => ! $lead->isLost())) {
            throw new \DomainException('Bulk Lost Reason editing can only be used on Lost leads.');
        }

        return DB::transaction(function () use ($leads, $lostReasonKey, $label) {
            $actorAgentId = Agent::where('user_id', session('user_id'))->value('id');
            $changed = 0;

            foreach ($leads as $lead) {
                $oldKey = $lead->lost_reason_key;
                $oldLabel = self::lostReasonLabel($oldKey) ?? ($oldKey ?: 'Not classified');

                $lead->update([
                    'lost_reason_key' => $lostReasonKey,
                    'last_activity_at'=> now(),
                ]);

                if (! self::isNurtureEligible($lostReasonKey)) {
                    Followup::where('lead_id', $lead->id)
                        ->where('status', 'pending')
                        ->where('action_type', 'reactivation_call')
                        ->update(['status' => 'cancelled', 'updated_at' => now()]);
                }

                Activity::create([
                    'lead_id'     => $lead->id,
                    'agent_id'    => $actorAgentId ?: $lead->resolveLoggingAgentId(),
                    'type'        => 'status_change',
                    'outcome'     => '✏️ Lost reason updated by Admin',
                    'outcome_key' => 'lost_reason_updated',
                    'action_source' => 'manual',
                    'notes'       => "Old reason: {$oldLabel}\nNew reason: {$label}\nBulk admin correction",
                    'logged_at'   => now(),
                ]);
                $changed++;
            }

            return $changed;
        });
    }

    /**
     * A human-touch record means the customer-facing work actually happened.
     * Administrative changes, intake records, task scheduling and system
     * repairs do not count. This is intentionally based on activity semantics
     * rather than simply 'any activity', so New remains a truthful untouched state.
     */
    /**
     * Record the non-customer-contact branch created by external sharing.
     * External sharing is not itself a customer contact, so it must not mark
     * the lead Contacted. It moves only New -> Shared Externally.
     */
    public function markExternallyShared(Lead $lead): bool
    {
        if ($lead->statusKey() !== 'new') {
            return false;
        }

        $allowed = $this->settings->allowedTransitionsFrom('new');
        if (! in_array('external_shared', $allowed, true)) {
            throw new \DomainException('External sharing is not an allowed transition from New.');
        }

        $lead->update([
            'status' => 'external_shared',
            'previous_status' => 'new',
            'last_activity_at' => now(),
        ]);

        return true;
    }

    public function hasHumanTouch(Lead $lead): bool
    {
        $touchTypes = [
            'call', 'whatsapp', 'email', 'meeting', 'site_visit',
            'shared_agent_report', 'site_team_report', 'external_share',
        ];

        return Activity::query()
            ->where('lead_id', $lead->id)
            ->where('action_source', 'manual')
            ->where(function ($q) use ($touchTypes) {
                $q->whereIn('type', $touchTypes)
                  ->orWhere(function ($n) {
                      $n->where('type', 'note')
                        ->where('outcome', 'not like', '📆 Follow-up scheduled:%');
                  });
            })
            ->exists();
    }

    public function hasRecentActivity(Lead $lead, int $minutes = 15): bool
    {
        return Activity::query()
            ->where('lead_id', $lead->id)
            ->where('logged_at', '>=', now()->subMinutes($minutes))
            ->exists();
    }
}