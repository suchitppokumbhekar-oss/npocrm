<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        // Canonical first-touch stages. Existing databases are updated by key,
        // never by assuming fixed primary-key IDs.
        $attempted = DB::table('lead_statuses')->where('key', 'attempted')->first();
        if (! $attempted) {
            DB::table('lead_statuses')->insert([
                'key' => 'attempted',
                'label' => 'Attempted',
                'color' => 'blue',
                'is_final' => 0,
                'terminal_type' => null,
                'is_active' => 1,
                'sort_order' => 2,
                'created_at' => $now,
                'updated_at' => $now,
                'requires_datetime' => 0,
                'requires_booking_details' => 0,
            ]);
        }

        $external = DB::table('lead_statuses')->where('key', 'external_shared')->first();
        if (! $external) {
            DB::table('lead_statuses')->insert([
                'key' => 'external_shared',
                'label' => 'Shared Externally',
                'color' => 'purple',
                'is_final' => 0,
                'terminal_type' => null,
                'is_active' => 1,
                // Kept outside the main pipeline ordering; this is a branch
                // between New and customer contact, not a replacement for it.
                'sort_order' => 90,
                'created_at' => $now,
                'updated_at' => $now,
                'requires_datetime' => 0,
                'requires_booking_details' => 0,
            ]);
        }

        $newId = DB::table('lead_statuses')->where('key', 'new')->value('id');
        $attemptedId = DB::table('lead_statuses')->where('key', 'attempted')->value('id');
        $externalId = DB::table('lead_statuses')->where('key', 'external_shared')->value('id');
        $contactedId = DB::table('lead_statuses')->where('key', 'contacted')->value('id');
        $lostId = DB::table('lead_statuses')->where('key', 'lost')->value('id');

        // New is now a genuinely untouched state. The only normal working
        // branches out of New are first attempt, external sharing, or a
        // terminal invalid/lost decision.
        if ($newId && $attemptedId) {
            DB::table('lead_status_transitions')->where('from_status_id', $newId)
                ->whereIn('to_status_id', array_filter([
                    DB::table('lead_statuses')->where('key', 'contacted')->value('id'),
                    DB::table('lead_statuses')->where('key', 'visit_scheduled')->value('id'),
                    DB::table('lead_statuses')->where('key', 'visit_done')->value('id'),
                    DB::table('lead_statuses')->where('key', 'negotiation')->value('id'),
                    DB::table('lead_statuses')->where('key', 'booking')->value('id'),
                ]))->delete();

            self::ensureTransition($newId, $attemptedId);
        }

        if ($newId && $externalId) {
            self::ensureTransition($newId, $externalId);
        }

        if ($attemptedId && $contactedId) {
            self::ensureTransition($attemptedId, $contactedId);
        }
        if ($attemptedId && $lostId) {
            self::ensureTransition($attemptedId, $lostId);
        }
        if ($externalId && $attemptedId) {
            self::ensureTransition($externalId, $attemptedId);
        }
        if ($externalId && $lostId) {
            self::ensureTransition($externalId, $lostId);
        }

        // Keep the normal pipeline order clean. External Shared is rendered
        // separately as a branch in the UI.
        DB::table('lead_statuses')->where('key', 'new')->update(['sort_order' => 1, 'updated_at' => $now]);
        DB::table('lead_statuses')->where('key', 'attempted')->update(['sort_order' => 2, 'updated_at' => $now]);
        DB::table('lead_statuses')->where('key', 'contacted')->update(['sort_order' => 3, 'updated_at' => $now]);
        DB::table('lead_statuses')->where('key', 'visit_scheduled')->update(['sort_order' => 4, 'updated_at' => $now]);
        DB::table('lead_statuses')->where('key', 'visit_done')->update(['sort_order' => 5, 'updated_at' => $now]);
        DB::table('lead_statuses')->where('key', 'negotiation')->update(['sort_order' => 6, 'updated_at' => $now]);
        DB::table('lead_statuses')->where('key', 'booking')->update(['sort_order' => 7, 'updated_at' => $now]);
        DB::table('lead_statuses')->where('key', 'lost')->update(['sort_order' => 8, 'updated_at' => $now]);

        // Backfill only leads that are currently New. Historical activity
        // records remain untouched, but their existence must be reflected in
        // the lifecycle so the new invariant does not create false New leads.
        $touchTypes = ['call', 'whatsapp', 'email', 'meeting', 'site_visit', 'shared_agent_report', 'site_team_report'];
        $newLeads = DB::table('leads')->where('status', 'new')->pluck('id');
        foreach ($newLeads as $leadId) {
            $touchExists = DB::table('activities')
                ->where('lead_id', $leadId)
                ->whereIn('type', $touchTypes)
                ->where('action_source', '!=', 'automated')
                ->exists();

            $noteTouchExists = DB::table('activities')
                ->where('lead_id', $leadId)
                ->where('type', 'note')
                ->where('action_source', '!=', 'automated')
                ->where('outcome', 'not like', '📆 Follow-up scheduled:%')
                ->where('outcome', 'not like', 'Awaiting project routing%')
                ->exists();

            $externalShareExists = DB::table('activities')
                ->where('lead_id', $leadId)
                ->where('type', 'external_share')
                ->where('action_source', '!=', 'automated')
                ->exists();

            if ($touchExists || $noteTouchExists) {
                $contacted = DB::table('activities as a')
                    ->leftJoin('call_outcomes as co', 'co.key', '=', 'a.outcome_key')
                    ->where('a.lead_id', $leadId)
                    ->whereIn('a.type', $touchTypes)
                    ->where('a.action_source', '!=', 'automated')
                    ->where(function ($q) {
                        $q->whereIn('a.type', ['meeting', 'site_visit'])
                          ->orWhere('co.is_connected', 1);
                    })
                    ->exists();

                DB::table('leads')->where('id', $leadId)->update([
                    'status' => $contacted ? 'contacted' : 'attempted',
                    'previous_status' => 'new',
                    'updated_at' => $now,
                ]);
            } elseif ($externalShareExists) {
                DB::table('leads')->where('id', $leadId)->update([
                    'status' => 'external_shared',
                    'previous_status' => 'new',
                    'updated_at' => $now,
                ]);
            }
        }

        Cache::forget('cfg.statuses.data.1');
        Cache::forget('cfg.statuses.data.0');
    }

    private static function ensureTransition(int $from, int $to): void
    {
        $exists = DB::table('lead_status_transitions')
            ->where('from_status_id', $from)
            ->where('to_status_id', $to)
            ->exists();

        if (! $exists) {
            DB::table('lead_status_transitions')->insert([
                'from_status_id' => $from,
                'to_status_id' => $to,
            ]);
        }
    }

    public function down(): void
    {
        $attemptedId = DB::table('lead_statuses')->where('key', 'attempted')->value('id');
        $externalId = DB::table('lead_statuses')->where('key', 'external_shared')->value('id');

        if ($attemptedId || $externalId) {
            DB::table('lead_status_transitions')
                ->where(function ($q) use ($attemptedId, $externalId) {
                    if ($attemptedId) $q->where('from_status_id', $attemptedId)->orWhere('to_status_id', $attemptedId);
                    if ($externalId) $q->orWhere('from_status_id', $externalId)->orWhere('to_status_id', $externalId);
                })->delete();
        }

        if ($attemptedId) DB::table('lead_statuses')->where('id', $attemptedId)->delete();
        if ($externalId) DB::table('lead_statuses')->where('id', $externalId)->delete();

        Cache::forget('cfg.statuses.data.1');
        Cache::forget('cfg.statuses.data.0');
    }
};
