<?php

namespace App\Services;

use App\Models\Agent;
use App\Models\Call;
use App\Models\Contact;
use App\Models\Lead;
use App\Models\Config\CallOutcome;
use App\Services\ContactFollowupService;
use App\Services\ContactLeadHandoffService;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class CallService
{
    public function __construct(
        private ContactFollowupService $contactFollowups,
        private SettingsService $settings,
        private ContactLeadHandoffService $contactLeadHandoff,
    ) {}

    /** Log a call against a contact or a lead. */
    public function log(
        int $agentId,
        ?int $contactId,
        ?int $leadId,
        ?string $outcomeKey,
        int $durationSeconds,
        ?string $notes = null,
        string $source = 'manual',
        ?string $siteVisitScheduledAt = null
    ): Call {
        $connected = $this->outcomeIsConnected($outcomeKey);

        return DB::transaction(function () use (
            $agentId, $contactId, $leadId, $outcomeKey,
            $durationSeconds, $notes, $source, $connected, $siteVisitScheduledAt
        ) {
            $call = Call::create([
                'contact_id'       => $contactId,
                'lead_id'          => $leadId,
                'agent_id'         => $agentId,
                'outcome_key'      => $outcomeKey,
                'connected'        => $connected,
                'duration_seconds' => max(0, $durationSeconds),
                'called_at'        => now(),
                'notes'            => $notes,
                'source'           => $source,
            ]);

            // Bump contact counters
            if ($contactId) {
                $contact = Contact::find($contactId);
                if ($contact) {
                    // Resolve the action and outcome against the Contact stage that
                    // existed when the caller selected the result. The selected
                    // outcome is allowed to change the Contact stage; therefore we
                    // must not re-validate the same outcome after applying that
                    // stage transition. Otherwise valid outcomes such as
                    // `asked_details` can become invalid immediately after the
                    // status is changed to `interested`, causing a false 500.
                    $activeWork = $contact->followups()
                        ->where('status', 'pending')
                        ->orderBy('scheduled_for')
                        ->first();
                    $actionKey = $activeWork?->action_type ?: 'call';
                    // Validate against the Contact/action state that is actually
                    // being completed. Do not rebuild the catalogue from a later
                    // stage or from the global outcome list. The dialer uses this
                    // same action-aware catalogue.
                    $outcome = $outcomeKey
                        ? $this->settings->callOutcomesForContactWork($contact, $actionKey)->firstWhere('key', $outcomeKey)
                        : null;
                    if ($outcomeKey && ! $outcome) {
                        throw new \DomainException('The selected outcome is not valid for the current Contact work action. Please return to the Contact and use the current action.');
                    }

                    // Any outcome that creates a visit-anchored next action needs
                    // the customer's actual appointment datetime. The UI exposes the
                    // same requirement using next_action_anchor, so the server and UI
                    // cannot drift apart.
                    $visitAnchor = (string) ($outcome?->next_action_anchor ?? 'now');
                    $requiresVisit = (bool) ($outcome?->requires_site_visit_datetime ?? false)
                        || $outcomeKey === 'site_visit_scheduled'
                        || in_array($visitAnchor, ['visit_before', 'visit_after'], true);
                    if ($requiresVisit) {
                        // Reuse the already-recorded customer appointment by default.
                        // The caller only needs to provide a value when none exists,
                        // or explicitly changes it. Never make a user re-enter the
                        // same business fact merely because the workflow advanced.
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
                        $updatesVisitAt = $visitAt;
                    } elseif ($outcomeKey === 'site_visit_cancelled') {
                        $updatesVisitAt = null;
                    }

                    $updates = [
                        'attempts'         => $contact->attempts + 1,
                        'last_called_at'   => now(),
                        'last_outcome_key' => $outcomeKey,
                    ];
                    if (isset($updatesVisitAt) || $outcomeKey === 'site_visit_cancelled') {
                        $updates['site_visit_scheduled_at'] = $updatesVisitAt ?? null;
                    }
                    if ($connected) {
                        $updates['connected_count'] = $contact->connected_count + 1;
                    }
                    // Auto-status bumps
                    if ($outcomeKey === 'not_interested' || $outcomeKey === 'wrong_number') {
                        $updates['status'] = $outcomeKey === 'wrong_number' ? 'invalid' : 'not_interested';
                    } elseif ($outcomeKey === 'dnc') {
                        $updates['status'] = 'dnc';
                    } elseif ($outcomeKey === 'interested') {
                        $updates['status'] = 'interested';
                    } elseif ($contact->status === 'new') {
                        $updates['status'] = 'called';
                    }
                    $contact->update($updates);

                    // Interested is the authoritative Contact -> Lead threshold.
                    // Create the Lead immediately so sales ownership/collaboration
                    // begins on the same successful customer interaction.
                    if ($outcomeKey === 'interested' && $connected) {
                        $caller = Agent::with('user')->find($agentId);
                        if ($caller) {
                            $lead = $this->contactLeadHandoff->handoffInterested(
                                $contact->fresh(),
                                $caller,
                                (int) (session('user_id') ?: $caller->user_id),
                                (int) $call->id
                            );
                            $contact = $contact->fresh();
                        }
                    }

                    // A Contact owns the retry only until it becomes a Lead.
                    // The configured Call Outcome controls the next retry delay,
                    // action type and priority; terminal Contact states get no
                    // new retry task. Use the outcome already validated above;
                    // its validity belongs to the pre-transition Contact stage.
                    $this->contactFollowups->scheduleForCall($contact->fresh(), $agentId, $call, $outcome);

                    // The first call starts the current pitch assignment. The
                    // assignment itself remains historical and is never replaced.
                    \App\Models\ContactProjectAssignment::where('contact_id', $contact->id)
                        ->where('status', 'active')
                        ->whereNull('started_at')
                        ->update(['started_at' => now(), 'updated_at' => now()]);
                }
            }

            // Touch lead last_activity_at
            if ($leadId) {
                $lead = Lead::find($leadId);
                if ($lead) $lead->update(['last_activity_at' => now()]);
            }

            return $call;
        });
    }

    /** Is the given outcome key a "connected" outcome? */
    public function outcomeIsConnected(?string $key): bool
    {
        if (! $key) return false;
        $outcome = CallOutcome::where('key', $key)->first();
        return $outcome ? (bool) $outcome->is_connected : false;
    }

    /* ============================================================
       AGGREGATES for the telecalling report
       ============================================================ */
    public function agentCallStats(array $agentIds, $from, $to): array
    {
        if (empty($agentIds)) return [];

        $agents = Agent::with('user')->whereIn('id', $agentIds)->orderBy('id')->get();

        $rows = DB::table('calls')
            ->whereIn('agent_id', $agentIds)
            ->whereBetween('called_at', [$from, $to])
            ->selectRaw("
                agent_id,
                COUNT(*) AS total_calls,
                SUM(CASE WHEN connected = 1 THEN 1 ELSE 0 END) AS connected_calls,
                SUM(CASE WHEN connected = 0 THEN 1 ELSE 0 END) AS not_connected_calls,
                COALESCE(SUM(CASE WHEN connected = 1 THEN duration_seconds ELSE 0 END), 0) AS talk_seconds,
                COUNT(DISTINCT COALESCE(contact_id, 0) + COALESCE(lead_id, 0) * 1000000) AS unique_people
            ")
            ->groupBy('agent_id')
            ->get()
            ->keyBy('agent_id');

        $out = [];
        foreach ($agents as $agent) {
            $r = $rows[$agent->id] ?? null;
            $total     = (int) ($r->total_calls ?? 0);
            $connected = (int) ($r->connected_calls ?? 0);
            $talkSec   = (int) ($r->talk_seconds ?? 0);

            $out[] = [
                'agent_id'         => $agent->id,
                'name'             => $agent->user?->name ?? ('Agent #' . $agent->id),
                'team'             => $agent->teams->first()->name ?? '—',
                'total_calls'      => $total,
                'connected_calls'  => $connected,
                'not_connected'    => (int) ($r->not_connected_calls ?? 0),
                'connect_rate'     => $total > 0 ? round($connected / $total * 100, 1) : 0,
                'talk_seconds'     => $talkSec,
                'talk_minutes'     => round($talkSec / 60, 1),
                'avg_talk_seconds' => $connected > 0 ? round($talkSec / $connected) : 0,
                'unique_people'    => (int) ($r->unique_people ?? 0),
            ];
        }

        usort($out, fn ($a, $b) => $b['total_calls'] <=> $a['total_calls']);
        return $out;
    }

    /** Outcome breakdown for a set of agents in a period. */
    public function outcomeBreakdown(array $agentIds, $from, $to): array
    {
        if (empty($agentIds)) return [];

        $rows = DB::table('calls')
            ->whereIn('agent_id', $agentIds)
            ->whereBetween('called_at', [$from, $to])
            ->whereNotNull('outcome_key')
            ->selectRaw('outcome_key, COUNT(*) AS n')
            ->groupBy('outcome_key')
            ->orderByDesc('n')
            ->get();

        $labels = CallOutcome::pluck('label', 'key')->all();

        return $rows->map(fn ($r) => [
            'key'   => $r->outcome_key,
            'label' => $labels[$r->outcome_key] ?? $r->outcome_key,
            'count' => (int) $r->n,
        ])->all();
    }
}