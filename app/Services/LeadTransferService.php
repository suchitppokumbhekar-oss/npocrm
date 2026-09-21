<?php

namespace App\Services;

use App\Models\Activity;
use App\Models\Agent;
use App\Models\Followup;
use App\Models\Lead;
use Illuminate\Support\Facades\DB;
use App\Services\SettingsService;

/**
 * Cross-project handover creates a NEW lead for the receiving agent.
 * The original lead remains intact for its original project/owner.
 */
class LeadTransferService
{
    public function __construct(
        private LeadAssignmentService $assignments,
        private FollowupService $followups,
        private LeadDuplicateGuard $duplicateGuard,
    ) {}

    /**
     * Create an additional project-specific workstream for the same customer/enquiry.
     *
     * Unlike a handover, this does not replace or complete the source workstream.
     * The new lead is attached to the root enquiry so multiple project interests can
     * coexist independently with their own owner, follow-ups and timeline.
     */
    public function createProjectInterestLead(Lead $source, Agent $target, int $newProjectId, string $reason): Lead
    {
        return DB::transaction(function () use ($source, $target, $newProjectId, $reason) {
            $actorName = session('user_name', 'System');
            $source->loadMissing('project', 'agent.user');
            $target->loadMissing('user');
            $destinationProject = \App\Models\Project::findOrFail($newProjectId);

            $root = $source;
            $guard = 0;
            while ($root->parent_lead_id && $guard < 20) {
                $root = Lead::find($root->parent_lead_id);
                if (! $root) {
                    $root = $source;
                    break;
                }
                $guard++;
            }

            // Serialize competing project-interest additions for the same root
            // enquiry and enforce the one-active-workstream-per-project rule at
            // the application level as well as in the controller.
            $root = Lead::whereKey($root->id)->lockForUpdate()->firstOrFail();
            $duplicate = $this->duplicateGuard->findExisting(
                (int) $newProjectId,
                $source->customer_id,
                $source->phone,
                $source->email,
            );
            if ($duplicate && (int) $duplicate->id !== (int) $source->id) {
                throw new \RuntimeException($this->duplicateGuard->message($duplicate));
            }

            $duplicate = Lead::query()
                ->where(function ($q) use ($root) {
                    $q->whereKey($root->id)->orWhere('parent_lead_id', $root->id);
                })
                ->where('project_id', $newProjectId)
                ->whereNotIn('status', ['lost'])
                ->exists();
            if ($duplicate) {
                throw new \RuntimeException('This customer already has an active workstream for the selected project.');
            }

            $newLead = Lead::create([
                'customer_name' => $source->customer_name,
                'phone' => $source->phone,
                'email' => $source->email,
                'source' => $source->source,
                'intake_source' => $source->intake_source,
                'intake_ref' => $source->intake_ref,
                'budget' => $source->budget,
                'project_id' => $newProjectId,
                'customer_id' => $source->customer_id,
                'status' => 'new',
                'assigned_at' => now(),
                'parent_lead_id' => $root->id,
                'origin_type' => 'project_interest',
                'origin_note' => 'Additional project interest added from lead #' . $source->id . ' for project ' . $destinationProject->name . ' / ' . ($target->user?->name ?? 'Agent') . '. Reason: ' . $reason,
                'utm_source' => $source->utm_source,
                'utm_medium' => $source->utm_medium,
                'utm_campaign' => $source->utm_campaign,
                'utm_content' => $source->utm_content,
                'utm_term' => $source->utm_term,
                'click_id' => $source->click_id,
                'referrer_url' => $source->referrer_url,
                'raw_payload' => $source->raw_payload,
            ]);

            $this->assignments->assignTo(
                $newLead,
                $target,
                (int) session('user_id'),
                [],
                $reason,
                'internal'
            );

            $this->followups->scheduleForAgent(
                $newLead,
                $target->id,
                now()->addMinutes(max(1, (int) app(SettingsService::class)->get('first_contact_delay_minutes', 15))),
                'followup_call',
                'high',
                true
            );

            $newLead->load('project');

            Activity::create([
                'lead_id' => $newLead->id,
                'agent_id' => $target->id,
                'type' => 'project_interest_added',
                'outcome' => 'Additional project interest added',
                'action_source' => 'manual',
                'outcome_key' => null,
                'notes' => implode("\n", [
                    "Created from lead: #{$source->id}",
                    "Root enquiry: #{$root->id}",
                    'Project: ' . ($newLead->project?->name ?? ('Project #' . $newProjectId)),
                    'Responsible agent: ' . ($target->user?->name ?? ('Agent #' . $target->id)),
                    "Created by: {$actorName}",
                    "Reason: {$reason}",
                    'When: ' . now()->format('Y-m-d H:i:s'),
                ]),
                'logged_at' => now(),
            ]);

            Activity::create([
                'lead_id' => $source->id,
                'agent_id' => $source->agent_id ?: $target->id,
                'type' => 'project_interest_added',
                'outcome' => "Added project interest: {$destinationProject->name}",
                'action_source' => 'manual',
                'outcome_key' => null,
                'notes' => implode("\n", [
                    "New project workstream: #{$newLead->id}",
                    'Project: ' . ($newLead->project?->name ?? ('Project #' . $newProjectId)),
                    'Responsible agent: ' . ($target->user?->name ?? ('Agent #' . $target->id)),
                    "Created by: {$actorName}",
                    "Reason: {$reason}",
                    'When: ' . now()->format('Y-m-d H:i:s'),
                ]),
                'logged_at' => now(),
            ]);

            $source->update(['last_activity_at' => now()]);
            $root->update(['last_activity_at' => now()]);

            // Adding another project workstream does not close the source
            // workstream. Keep the source actionable as well.
            app(SmartFollowupService::class)->ensureLeadHasNextAction($source, 24, 'normal');

            return $newLead;
        });
    }

    public function createCrossProjectLead(Lead $source, Agent $target, int $newProjectId, string $reason, ?Followup $completedFollowup = null): Lead
    {
        return DB::transaction(function () use ($source, $target, $newProjectId, $reason, $completedFollowup) {
            $actorName = session('user_name', 'System');
            $source->loadMissing('project', 'agent.user');
            $target->loadMissing('user');
            $destinationProject = \App\Models\Project::findOrFail($newProjectId);

            $duplicate = $this->duplicateGuard->findExisting(
                (int) $newProjectId,
                $source->customer_id,
                $source->phone,
                $source->email,
            );
            if ($duplicate) {
                throw new \RuntimeException(
                    'Cross-project handover would create a duplicate enquiry: ' . $this->duplicateGuard->message($duplicate)
                );
            }

            $newLead = Lead::create([
                'customer_name' => $source->customer_name,
                'phone' => $source->phone,
                'email' => $source->email,
                'source' => $source->source,
                'intake_source' => $source->intake_source,
                'intake_ref' => $source->intake_ref,
                'budget' => $source->budget,
                'project_id' => $newProjectId,
                'customer_id' => $source->customer_id,
                'status' => 'new',
                'assigned_at' => now(),
                'parent_lead_id' => $source->id,
                'origin_type' => 'cross_project_transfer',
                'origin_note' => 'Handover from lead #' . $source->id . ' (' . ($source->project?->name ?? 'Unknown project') . ') to project ' . $destinationProject->name . ' / ' . ($target->user?->name ?? 'Agent') . '. Reason: ' . $reason,
                'utm_source' => $source->utm_source,
                'utm_medium' => $source->utm_medium,
                'utm_campaign' => $source->utm_campaign,
                'utm_content' => $source->utm_content,
                'utm_term' => $source->utm_term,
                'click_id' => $source->click_id,
                'referrer_url' => $source->referrer_url,
                'raw_payload' => $source->raw_payload,
            ]);

            $this->assignments->assignTo($newLead, $target, (int) session('user_id'), [], $reason, 'internal');

            $this->followups->scheduleForAgent(
                $newLead,
                $target->id,
                now()->addMinutes(max(1, (int) app(SettingsService::class)->get('first_contact_delay_minutes', 15))),
                'followup_call',
                'high',
                true
            );

            $newLead->load('project');

            $agentIdForActivity = $target->id;
            Activity::create([
                'lead_id' => $newLead->id,
                'agent_id' => $agentIdForActivity,
                'type' => 'lead_created_from_transfer',
                'outcome' => 'New lead created from cross-project transfer',
                'action_source' => 'manual',
                'outcome_key' => null,
                'notes' => implode("\n", [
                    "Created from original lead: #{$source->id}",
                    'Original project: ' . ($source->project?->name ?? ('Project #' . $source->project_id)),
                    'New project: ' . ($newLead->project?->name ?? ('Project #' . $newProjectId)),
                    'New owner: ' . ($target->user?->name ?? ('Agent #' . $target->id)),
                    "Created by: {$actorName}",
                    "Reason: {$reason}",
                    'When: ' . now()->format('Y-m-d H:i:s'),
                ]),
                'logged_at' => now(),
            ]);

            $sourceAgentId = $source->agent_id ?: $target->id;
            Activity::create([
                'lead_id' => $source->id,
                'agent_id' => $sourceAgentId,
                'type' => 'cross_project_transfer',
                'outcome' => "Created new lead #{$newLead->id} for another project",
                'action_source' => 'manual',
                'outcome_key' => null,
                'notes' => implode("\n", [
                    "New lead created: #{$newLead->id}",
                    'Destination project: ' . ($newLead->project?->name ?? ('Project #' . $newProjectId)),
                    'Destination owner: ' . ($target->user?->name ?? ('Agent #' . $target->id)),
                    "Created by: {$actorName}",
                    "Reason: {$reason}",
                    'When: ' . now()->format('Y-m-d H:i:s'),
                ]),
                'logged_at' => now(),
            ]);
            $source->update(['last_activity_at' => now()]);

            if ($completedFollowup) {
                $completedFollowup->update(['status' => 'done', 'updated_at' => now()]);
            }

            // The source workstream intentionally remains active after a
            // cross-project handover. Completing the handover task must therefore
            // never leave the original lead without its own next action.
            app(SmartFollowupService::class)->ensureLeadHasNextAction($source, 24, 'normal');

            return $newLead;
        });
    }
}
