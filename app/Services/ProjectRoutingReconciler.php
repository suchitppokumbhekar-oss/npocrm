<?php

namespace App\Services;

use App\Models\Lead;
use App\Models\LeadAgent;
use App\Models\Project;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ProjectRoutingReconciler
{
    /**
     * Return the agents who were eligible under the project's current routing.
     * This is intentionally evaluated before/after routing writes so the lead
     * ownership table can be kept in step with the routing policy.
     */
    public function eligibleAgentIds(Project $project): array
    {
        $project->forgetEligibleAgentsCache();

        return array_values(array_unique(array_map(
            'intval',
            $project->eligibleAgentIds()
        )));
    }

    /**
     * Remove internal lead assignments that are no longer allowed by the
     * project's routing. External/site-team shares are deliberately preserved.
     *
     * Active non-terminal leads are immediately re-routed when a replacement
     * is available. If no replacement is available, the lead is left safely
     * unassigned for the existing orphan-recovery/alert flow.
     */
    public function reconcile(Project $project, array $eligibleAgentIds): array
    {
        // A direct project-agent route is an exclusive ownership tier. When one
        // or more direct agents are configured, team members are not eligible
        // owners for this project. This keeps older leads from remaining with a
        // team member after a project is explicitly assigned to a direct agent.
        $routingBySource = $project->fresh()->eligibleAgentIdsBySource();
        $effectiveEligibleAgentIds = !empty($routingBySource['direct'])
            ? $routingBySource['direct']
            : ($routingBySource['team'] ?? []);

        $eligibleAgentIds = array_values(array_unique(array_map(
            'intval',
            $effectiveEligibleAgentIds
        )));
        $stale = LeadAgent::query()
            ->join('leads', 'leads.id', '=', 'lead_agents.lead_id')
            ->where('leads.project_id', $project->id)
            ->where('lead_agents.is_active', 1)
            ->where('lead_agents.share_type', 'internal')
            ->whereNotIn('lead_agents.agent_id', $eligibleAgentIds ?: [-1])
            ->get([
                'lead_agents.id',
                'lead_agents.lead_id',
                'lead_agents.agent_id',
                'lead_agents.is_primary',
            ]);

        if ($stale->isEmpty()) {
            return [
                'removed_assignments' => 0,
                'affected_leads'      => 0,
                'rerouted_leads'      => 0,
                'unassigned_leads'    => 0,
            ];
        }

        $leadIds = $stale->pluck('lead_id')->unique()->values()->all();
        $staleAgentIdsByLead = [];
        $primaryRows = [];

        foreach ($stale as $row) {
            $leadId = (int) $row->lead_id;
            $agentId = (int) $row->agent_id;
            $staleAgentIdsByLead[$leadId][] = $agentId;

            if ((int) $row->is_primary === 1) {
                $primaryRows[] = $row;
            }
        }

        $rerouted = 0;
        $unassigned = 0;

        DB::transaction(function () use ($stale, $primaryRows, $leadIds, &$rerouted, &$unassigned, $project) {
            $now = now();

            // First make the removed agents disappear from the active lead list.
            // This preserves the ownership row as historical evidence instead of deleting it.
            LeadAgent::whereIn('id', $stale->pluck('id')->all())
                ->update([
                    'is_active' => 0,
                    'is_primary' => 0,
                    'updated_at' => $now,
                ]);

            // Release ownership/load only for leads that are still active work.
            // Booking/lost leads keep their historical primary owner; routing changes
            // must not rewrite terminal ownership. They are still removed from the
            // removed agent's active lead list by the update above.
            foreach ($primaryRows as $row) {
                $lead = Lead::select('id', 'agent_id', 'status')
                    ->where('id', (int) $row->lead_id)
                    ->first();

                if (! $lead || (int) $lead->agent_id !== (int) $row->agent_id) {
                    continue;
                }

                if (in_array($lead->status, ['booking', 'lost'], true)) {
                    continue;
                }

                $oldAgentId = (int) $row->agent_id;

                DB::table('agents')
                    ->where('id', $oldAgentId)
                    ->where('current_load', '>', 0)
                    ->decrement('current_load');

                $lead->update([
                    'agent_id' => null,
                    'assigned_at' => null,
                    'updated_at' => $now,
                ]);
            }
        });

        // Re-route active leads through the same canonical assignment engine.
        // Respect the project's routing tiers: direct project agents have priority
        // over team members. For project 1157 this means Simran (direct routing)
        // receives the recovered leads rather than an arbitrary team member.
        // Doing this outside the transaction keeps routing writes atomic while
        // allowing the assignment service to manage its own load/notification
        // transaction per lead.
        $assignment = app(LeadAssignmentService::class);
        // Use the same exclusive routing tier used above for stale-owner removal.
        $assignmentCandidates = $eligibleAgentIds;

        foreach ($leadIds as $leadId) {
            $lead = Lead::with('agent')->find($leadId);
            if (! $lead) {
                continue;
            }

            // Closed leads keep their historical ownership; routing changes
            // should not rewrite booking/lost history.
            if (in_array($lead->status, ['booking', 'lost'], true)) {
                continue;
            }

            $oldAgentIds = $staleAgentIdsByLead[$leadId] ?? [];

            if (! $lead->agent_id) {
                try {
                    $newAgent = $assignment->assign($lead->fresh(), $assignmentCandidates);
                } catch (\Throwable $e) {
                    Log::warning('Project routing lead re-assignment failed', [
                        'project_id' => $project->id,
                        'lead_id' => $leadId,
                        'error' => $e->getMessage(),
                    ]);
                    $newAgent = null;
                }

                if ($newAgent) {
                    $rerouted++;
                    // Move every pending work item belonging to a removed owner,
                    // including overdue items. This prevents stale tasks from
                    // keeping the lead visible in the removed agent's Current Work.
                    $this->movePendingWork($leadId, $newAgent->id, $oldAgentIds);
                } else {
                    $unassigned++;
                    $this->cancelRemovedAgentCheckbacks($leadId, $oldAgentIds);
                }
            } else {
                // The lead may already have a valid new owner (for example when
                // another internal owner was already assigned). Even then, any
                // pending work left under a removed owner must follow the lead or
                // be cancelled, otherwise it remains visible as overdue work for
                // an agent who is no longer routed to this project.
                $currentOwnerId = (int) $lead->agent_id;
                if (in_array($currentOwnerId, $assignmentCandidates, true)) {
                    $this->movePendingWork($leadId, $currentOwnerId, $oldAgentIds);
                } else {
                    $this->cancelRemovedAgentCheckbacks($leadId, $oldAgentIds);
                }
            }
        }

        return [
            'removed_assignments' => $stale->count(),
            'affected_leads'      => count($leadIds),
            'rerouted_leads'      => $rerouted,
            'unassigned_leads'    => $unassigned,
        ];
    }

    private function movePendingWork(int $leadId, int $newAgentId, array $oldAgentIds): void
    {
        $oldAgentIds = array_values(array_unique(array_map('intval', $oldAgentIds)));
        if (empty($oldAgentIds)) return;

        DB::table('followups')
            ->where('lead_id', $leadId)
            ->where('status', 'pending')
            ->whereIn('agent_id', $oldAgentIds)
            ->whereNotIn('action_type', ['check_shared_agent', 'check_site_team'])
            ->update([
                'agent_id' => $newAgentId,
                'updated_at' => now(),
            ]);

        $this->cancelRemovedAgentCheckbacks($leadId, $oldAgentIds);
    }

    private function cancelRemovedAgentCheckbacks(int $leadId, array $oldAgentIds): void
    {
        $oldAgentIds = array_values(array_unique(array_map('intval', $oldAgentIds)));
        if (empty($oldAgentIds)) return;

        DB::table('followups')
            ->where('lead_id', $leadId)
            ->where('status', 'pending')
            ->whereIn('agent_id', $oldAgentIds)
            ->whereIn('action_type', ['check_shared_agent'])
            ->update([
                'status' => 'cancelled',
                'updated_at' => now(),
            ]);
    }
}
