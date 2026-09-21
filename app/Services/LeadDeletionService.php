<?php

namespace App\Services;

use App\Models\Activity;
use App\Models\Call;
use App\Models\Contact;
use App\Models\Followup;
use App\Models\Lead;
use App\Models\LeadAgent;
use Illuminate\Support\Facades\DB;

class LeadDeletionService
{
    /**
     * Summarise what a deletion would remove. Safe — read-only.
     */
    public function preview(int $leadId): array
    {
        $lead = Lead::with(['agent.user', 'project', 'activeAgents.user'])->find($leadId);

        if (! $lead) {
            return ['found' => false];
        }

        return [
            'found'         => true,
            'lead'          => $lead,
            'counts'        => [
                'activities'  => Activity::where('lead_id', $leadId)->count(),
                'followups'   => Followup::where('lead_id', $leadId)->count(),
                'lead_agents' => LeadAgent::where('lead_id', $leadId)->count(),
                'calls'       => Call::where('lead_id', $leadId)->count(),
                'notifications' => DB::table('notifications')
                    ->where('related_type', 'lead')
                    ->where('related_id', $leadId)
                    ->count(),
                'contacts_promoted' => Contact::where('promoted_to_lead_id', $leadId)->count(),
            ],
            'last_activities' => Activity::where('lead_id', $leadId)
                ->orderByDesc('logged_at')
                ->limit(5)
                ->get(['id', 'type', 'outcome', 'logged_at']),
        ];
    }

    /**
     * Permanently delete the lead + all related records.
     * Runs in a transaction — either everything goes, or nothing does.
     *
     * Returns ['deleted' => array of table => row count]
     */
    public function delete(int $leadId): array
    {
        return DB::transaction(function () use ($leadId) {
            $counts = [
                'activities'        => Activity::where('lead_id', $leadId)->delete(),
                'followups'         => Followup::where('lead_id', $leadId)->delete(),
                'lead_agents'       => LeadAgent::where('lead_id', $leadId)->delete(),
                'calls'             => Call::where('lead_id', $leadId)->delete(),
                'notifications'     => DB::table('notifications')
                                            ->where('related_type', 'lead')
                                            ->where('related_id', $leadId)
                                            ->delete(),
            ];

            // Detach promoted contacts (keep the contact, just unlink)
            $counts['contacts_detached'] = Contact::where('promoted_to_lead_id', $leadId)
                ->update([
                    'promoted_to_lead_id' => null,
                    'promoted_at'         => null,
                    'status'              => 'called',
                ]);

            $counts['lead'] = Lead::where('id', $leadId)->delete();

            return $counts;
        });
    }
}