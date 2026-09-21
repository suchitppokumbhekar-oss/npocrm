<?php

namespace App\Services;

use App\Models\Agent;
use App\Models\AppNotification;
use App\Models\Contact;
use App\Models\Followup;
use App\Models\Lead;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Authoritative Contact -> Lead handoff.
 *
 * Business rule:
 *   Contact outcome = Interested => Lead exists immediately.
 *   Normal agent caller => caller becomes Lead owner.
 *   Telecaller => project-aware routing selects the sales owner; telecaller
 *                 remains a temporary active collaborator until visit_scheduled.
 *
 * Contact remains as the source/history record, but no longer owns caller work.
 */
class ContactLeadHandoffService
{
    private const TELECALLER_NOTE = 'Contact-to-Lead handoff: telecaller support until site visit scheduled.';

    public function __construct(
        private ContactService $contacts,
        private LeadAssignmentService $assignments,
        private FollowupService $followups,
    ) {}

    public function handoffInterested(Contact $contact, Agent $caller, int $actorUserId, int $callId): Lead
    {
        return DB::transaction(function () use ($contact, $caller, $actorUserId, $callId): Lead {
            $lockedContact = Contact::query()->lockForUpdate()->findOrFail($contact->id);

            if ($lockedContact->status === 'dnc') {
                throw new \DomainException('DND contacts cannot be converted into leads.');
            }

            $isTelecaller = (bool) $caller->user?->is_telecaller;
            $preferredOwnerId = $isTelecaller ? null : (int) $caller->id;

            $lead = $this->contacts->promoteToLead(
                $lockedContact,
                $actorUserId,
                (int) ($lockedContact->project_id ?? 0),
                $preferredOwnerId,
                ! $isTelecaller
            );

            // The qualifying call is now Lead history as well as Contact history.
            \App\Models\Call::whereKey($callId)->update(['lead_id' => $lead->id]);

            $lead->refresh();

            // Existing same-project Lead is authoritative. Never silently
            // reassign its primary owner just because another Contact reached
            // Interested. A collaborator may be added only where the caller
            // is actually eligible for the Lead's project.
            if ($isTelecaller) {
                $this->prepareTelecallerCollaboration($lead, $caller, $actorUserId);
            }

            $this->recordHandoffActivity($lead, $lockedContact, $caller, $isTelecaller, $callId);
            $this->enrichOwnerNotification($lead, $caller, $isTelecaller);

            return $lead->fresh(['agent.user', 'project']);
        });
    }

    private function prepareTelecallerCollaboration(Lead $lead, Agent $caller, int $actorUserId): void
    {
        if (! $lead->agent_id) {
            // No valid sales owner means the existing routing safety net is the
            // authority. Do not create an inaccessible telecaller task.
            return;
        }

        $project = $lead->project_id ? \App\Models\Project::find($lead->project_id) : null;
        if (! $project || ! in_array((int) $caller->id, array_map('intval', $project->eligibleAgentIds()), true)) {
            Log::warning('ContactLeadHandoff: telecaller is no longer eligible for Lead project', [
                'contact_id' => $lead->id,
                'lead_id' => $lead->id,
                'caller_agent_id' => $caller->id,
                'project_id' => $lead->project_id,
            ]);
            return;
        }

        $this->assignments->addCollaborator(
            $lead,
            $caller,
            $actorUserId,
            self::TELECALLER_NOTE
        );

        // The production followups table has no notes column. Do not use
        // notes as a duplicate marker or attempt to write it. A pending
        // followup_call for this Lead/telecaller is already enough to preserve
        // the one-authoritative-task rule without introducing a schema change.
        $existingTask = Followup::where('lead_id', $lead->id)
            ->where('agent_id', $caller->id)
            ->where('status', 'pending')
            ->where('action_type', 'followup_call')
            ->first();

        if (! $existingTask) {
            $this->followups->scheduleForAgent(
                $lead,
                (int) $caller->id,
                now(),
                'followup_call',
                'high',
                false
            );
        }
    }

    /**
     * Release the telecaller collaboration when the Lead reaches Visit Scheduled.
     * The primary sales owner remains untouched.
     */
    public function releaseTelecallerAtSiteVisit(Lead $lead): void
    {
        DB::transaction(function () use ($lead): void {
            $rows = \App\Models\LeadAgent::with('agent.user')
                ->where('lead_id', $lead->id)
                ->where('is_primary', false)
                ->where('is_active', true)
                ->get();

            foreach ($rows as $row) {
                $agent = $row->agent;
                if (! $agent || ! $agent->user?->is_telecaller) continue;
                if (! str_contains((string) $row->note, 'Contact-to-Lead handoff')) continue;

                $this->assignments->removeCollaborator($lead, $agent);

                Followup::where('lead_id', $lead->id)
                    ->where('agent_id', $agent->id)
                    ->where('status', 'pending')
                    ->update(['status' => 'cancelled', 'updated_at' => now()]);

                \App\Models\Activity::create([
                    'lead_id' => $lead->id,
                    'agent_id' => $agent->id,
                    'type' => 'note',
                    'outcome' => 'Telecaller handoff completed',
                    'outcome_key' => null,
                    'action_source' => 'automated',
                    'notes' => 'Site visit scheduled. Telecaller Contact-to-Lead support ended; Lead continues with the primary sales agent.',
                    'logged_at' => now(),
                ]);
            }
        });
    }

    private function recordHandoffActivity(Lead $lead, Contact $contact, Agent $caller, bool $isTelecaller, int $callId): void
    {
        $ownerName = $lead->agent?->user?->name ?? 'Awaiting assignment';
        $callerName = $caller->user?->name ?? ('Agent #' . $caller->id);

        \App\Models\Activity::create([
            'lead_id' => $lead->id,
            'agent_id' => $caller->id,
            'type' => 'note',
            'outcome' => 'Contact qualified → Lead created',
            'outcome_key' => 'interested',
            'action_source' => 'automated',
            'notes' => implode("\n", [
                "Source Contact: #{$contact->id}",
                "Qualified by: {$callerName}",
                "Lead owner: {$ownerName}",
                'Handoff: ' . ($isTelecaller
                    ? 'Telecaller continues supporting site-visit arrangement until the visit is scheduled.'
                    : 'Caller remains the Lead owner and continues the sales workflow.'),
                "Qualifying call: #{$callId}",
            ]),
            'logged_at' => now(),
        ]);
    }

    private function enrichOwnerNotification(Lead $lead, Agent $caller, bool $isTelecaller): void
    {
        $owner = $lead->agent;
        if (! $owner?->user_id) return;

        $notification = AppNotification::query()
            ->where('user_id', $owner->user_id)
            ->where('type', 'lead_assigned')
            ->where('related_type', 'lead')
            ->where('related_id', $lead->id)
            ->orderByDesc('id')
            ->first();

        if (! $notification) return;

        $callerName = $caller->user?->name ?? ('Agent #' . $caller->id);
        $body = $lead->customer_name . ' · ' . $lead->phone . ' · Interested';
        $body .= $isTelecaller
            ? " · {$callerName} is arranging the site visit. You may need to attend."
            : ' · Lead created from your Contact. Continue toward the site visit / next sales step.';

        $notification->update(['body' => $body, 'updated_at' => now()]);
    }
}
