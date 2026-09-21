<?php

namespace App\Services;

use App\Models\Activity;
use App\Models\BookingControl;
use App\Models\Lead;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Controlled booking workflow layered on top of the existing Lead::booking
 * lifecycle. Booking remains a final lead status; this service owns approval,
 * rejection, controlled post-booking edits, and the audit/history trail.
 */
class BookingControlService
{
    public function __construct(
        private AccessService $access,
        private AuditLogService $audit,
        private BookingFinancialService $financials,
    ) {}

    public function snapshot(Lead $lead, array $override = []): array
    {
        $fields = [
            'booking_amount', 'property_area_sqft', 'rate_per_sqft',
            'booking_unit', 'booking_payment_mode', 'booking_date',
            'brokerage_percentage', 'brokerage_amount', 'brokerage_status',
            'brokerage_expected_at', 'brokerage_received_at', 'co_broker_name',
        ];

        $snapshot = [];
        foreach ($fields as $field) {
            $value = array_key_exists($field, $override) ? $override[$field] : $lead->{$field};
            if ($value instanceof \Carbon\CarbonInterface) {
                $value = $value->format('Y-m-d');
            }
            $snapshot[$field] = $value;
        }

        return $snapshot;
    }

    /** Create the control record the first time a lead reaches Booking. */
    public function ensureForBookedLead(Lead $lead, array $bookingUpdate = []): BookingControl
    {
        $control = BookingControl::query()->where('lead_id', $lead->id)->first();
        if ($control) {
            return $control;
        }

        $userId = (int) session('user_id') ?: null;
        $now = now();
        $approved = $userId && $this->access->isUnrestrictedAdmin($userId);

        $control = BookingControl::create([
            'lead_id' => $lead->id,
            'status' => $approved ? BookingControl::APPROVED : BookingControl::PENDING,
            'requested_by_user_id' => $userId,
            'requested_at' => $now,
            'approved_by_user_id' => $approved ? $userId : null,
            'approved_at' => $approved ? $now : null,
            'decision_note' => $approved ? 'Approved at booking creation by an unrestricted Admin.' : null,
            'snapshot' => $this->snapshot($lead, $bookingUpdate),
        ]);

        $this->timeline($lead, $approved ? 'Booking approved' : 'Booking submitted for approval',
            $approved
                ? 'Booking was recorded and approved by an unrestricted Admin.'
                : 'Booking was recorded and is awaiting Admin approval.');

        $this->financials->syncLeadBooking($lead->fresh(['project']), $userId, true);
        if ($approved) {
            $financial = \App\Models\BookingFinancial::where('lead_id',$lead->id)->first();
            if ($financial) $this->financials->authorizeBooking($financial, $userId, 'Booking approved at creation.');
        }

        $this->audit->record(
            'booking_control',
            $approved ? 'Booking approved at creation' : 'Booking submitted for approval',
            request(),
            [
                'lead_id' => $lead->id,
                'control_id' => $control->id,
                'status' => $control->status,
                'booking_amount' => $lead->booking_amount,
                'booking_date' => optional($lead->booking_date)->format('Y-m-d'),
            ],
            'BookingControl',
            $control->id,
        );

        return $control;
    }

    public function forLead(Lead $lead): ?BookingControl
    {
        return BookingControl::query()->where('lead_id', $lead->id)->first();
    }

    public function approve(BookingControl $control, ?string $note = null): BookingControl
    {
        $this->requireApprover();
        $control->loadMissing('lead');
        $lead = $control->lead;

        if ($lead->statusKey() !== 'booking') {
            throw new \DomainException('Only a lead currently at Booking can be approved.');
        }
        if ($control->isApproved()) {
            throw new \DomainException('This booking is already approved.');
        }
        if ($control->status === BookingControl::CANCELLED) {
            throw new \DomainException('A cancelled booking control cannot be approved.');
        }

        $userId = (int) session('user_id');
        DB::transaction(function () use ($control, $note, $userId, $lead): void {
            $control->update([
                'status' => BookingControl::APPROVED,
                'approved_by_user_id' => $userId,
                'approved_at' => now(),
                'rejected_by_user_id' => null,
                'rejected_at' => null,
                'decision_note' => $note ? trim($note) : 'Approved by Admin.',
                'snapshot' => $this->snapshot($lead),
            ]);

            $financial = $this->financials->syncLeadBooking($lead->fresh(['project']), $userId, true);
            $this->financials->authorizeBooking($financial, $userId, $note ?: 'Booking Control approved.');

            $this->timeline($lead, 'Booking approved',
                'Booking control approved by ' . (session('user_name') ?: 'Admin') . '.');
        });

        $this->audit->record('booking_control', 'Booking approved', request(), [
            'lead_id' => $lead->id,
            'control_id' => $control->id,
            'decision_note' => $control->decision_note,
        ], 'BookingControl', $control->id);

        return $control->fresh(['lead', 'approvedBy', 'requestedBy']);
    }

    public function reject(BookingControl $control, string $reason): BookingControl
    {
        $this->requireApprover();
        $reason = trim($reason);
        if (mb_strlen($reason) < 3) {
            throw new \DomainException('Please provide a rejection reason.');
        }

        $control->loadMissing('lead');
        $lead = $control->lead;
        if ($lead->statusKey() !== 'booking') {
            throw new \DomainException('Only a lead currently at Booking can be rejected.');
        }
        if ($control->isApproved()) {
            throw new \DomainException('An approved booking cannot be rejected. Use the controlled post-booking edit/cancellation workflow.');
        }

        $userId = (int) session('user_id');
        DB::transaction(function () use ($control, $reason, $userId, $lead): void {
            $control->update([
                'status' => BookingControl::REJECTED,
                'rejected_by_user_id' => $userId,
                'rejected_at' => now(),
                'approved_by_user_id' => null,
                'approved_at' => null,
                'decision_note' => $reason,
                'snapshot' => $this->snapshot($lead),
            ]);

            $this->timeline($lead, 'Booking approval rejected',
                'Booking control rejected by ' . (session('user_name') ?: 'Admin') . '. Reason: ' . $reason);
        });

        $this->audit->record('booking_control', 'Booking approval rejected', request(), [
            'lead_id' => $lead->id,
            'control_id' => $control->id,
            'reason' => $reason,
        ], 'BookingControl', $control->id);

        return $control->fresh(['lead', 'rejectedBy', 'requestedBy']);
    }

    /** Re-submit a rejected booking after its details are corrected. */
    public function resubmitAfterEdit(Lead $lead): ?BookingControl
    {
        $control = $this->forLead($lead);
        if (! $control || $control->isApproved()) return $control;

        $userId = (int) session('user_id') ?: null;
        $control->update([
            'status' => BookingControl::PENDING,
            'requested_by_user_id' => $userId,
            'requested_at' => now(),
            'approved_by_user_id' => null,
            'approved_at' => null,
            'rejected_by_user_id' => null,
            'rejected_at' => null,
            'decision_note' => null,
            'snapshot' => $this->snapshot($lead),
        ]);

        $this->financials->syncLeadBooking($lead->fresh(['project']), $userId, true);

        $this->timeline($lead, 'Booking resubmitted for approval', 'Booking details were updated and the booking was resubmitted for Admin approval.');
        $this->audit->record('booking_control', 'Booking resubmitted for approval', request(), [
            'lead_id' => $lead->id,
            'control_id' => $control->id,
        ], 'BookingControl', $control->id);

        return $control->fresh();
    }

    public function canEditBooking(Lead $lead): bool
    {
        $control = $this->forLead($lead);
        if (! $control) return $this->access->canWorkLead($lead);
        if ($control->isApproved()) return $this->access->isUnrestrictedAdmin();
        return $this->access->canWorkLead($lead);
    }

    public function recordApprovedEdit(Lead $lead): void
    {
        $control = $this->forLead($lead);
        if (! $control || ! $control->isApproved()) return;

        $control->update(['snapshot' => $this->snapshot($lead)]);
        $financial = $this->financials->syncLeadBooking($lead->fresh(['project']), (int)session('user_id'), true);
        $this->financials->authorizeBooking($financial, (int)session('user_id'), 'Approved booking financial edit.');
        $this->timeline($lead, 'Booking details updated',
            'Booking/brokerage details were edited by an unrestricted Admin after approval.');
        $this->audit->record('booking_control', 'Approved booking details edited', request(), [
            'lead_id' => $lead->id,
            'control_id' => $control->id,
            'booking_amount' => $lead->booking_amount,
            'booking_date' => optional($lead->booking_date)->format('Y-m-d'),
        ], 'BookingControl', $control->id);
    }

    private function requireApprover(): void
    {
        if (! $this->access->isUnrestrictedAdmin()) {
            abort(403, 'Only an unrestricted Admin can approve or reject a booking.');
        }
    }

    private function timeline(Lead $lead, string $outcome, string $notes): void
    {
        Activity::create([
            'lead_id' => $lead->id,
            'agent_id' => $lead->resolveLoggingAgentId(),
            'type' => 'note',
            'outcome' => $outcome,
            'outcome_key' => null,
            'action_source' => 'manual',
            'notes' => $notes,
            'logged_at' => now(),
        ]);
        $lead->update(['last_activity_at' => now()]);
    }
}
