<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('contacts', 'site_visit_scheduled_at')) {
            Schema::table('contacts', function (Blueprint $table) {
                $table->dateTime('site_visit_scheduled_at')->nullable()->after('last_outcome_key');
                $table->index('site_visit_scheduled_at', 'contacts_site_visit_scheduled_idx');
            });
        }

        if (! Schema::hasColumn('call_outcomes', 'contact_status_key')) {
            Schema::table('call_outcomes', function (Blueprint $table) {
                $table->string('contact_status_key', 50)->nullable()->after('context_action_key');
            });
        }
        if (! Schema::hasColumn('call_outcomes', 'requires_site_visit_datetime')) {
            Schema::table('call_outcomes', function (Blueprint $table) {
                $table->boolean('requires_site_visit_datetime')->default(false)->after('contact_status_key');
            });
        }
        if (! Schema::hasColumn('call_outcomes', 'next_action_anchor')) {
            Schema::table('call_outcomes', function (Blueprint $table) {
                $table->string('next_action_anchor', 30)->default('now')->after('requires_site_visit_datetime');
            });
        }

        // Existing call outcome already means "a site visit was scheduled".
        // Contact work now treats the customer's actual appointment time as
        // authoritative, while Lead workflows remain unaffected by these
        // Contact-only scheduling fields.
        DB::table('call_outcomes')->where('key', 'site_visit_scheduled')->update([
            'requires_site_visit_datetime' => 1,
            'next_action_anchor' => 'visit_before',
            'updated_at' => now(),
        ]);

        // Keep existing WhatsApp and visit-feedback catalogues scoped to their
        // actual action when the rows are present. Do not overwrite a row that
        // an admin has already deliberately scoped.
        DB::table('call_outcomes')
            ->whereIn('key', ['wa_sent_details','wa_delivered_awaiting','wa_read_no_reply','wa_replied_positive','wa_not_interested','wa_blocked'])
            ->whereNull('context_action_key')
            ->update(['context_action_key' => 'whatsapp_followup', 'activity_type_filter' => 'whatsapp', 'updated_at' => now()]);

        DB::table('call_outcomes')
            ->whereIn('key', ['visit_booked_spot','visit_interested','visit_needs_family','visit_wants_negotiate','visit_wants_other_project','visit_not_interested','visit_no_show','site_visit_with_family','site_visit_arrived_late','site_visit_cancelled','wants_second_visit'])
            ->whereNull('context_action_key')
            ->update(['context_action_key' => 'visit_feedback_call', 'activity_type_filter' => 'call', 'updated_at' => now()]);

        // Authoritative outcomes for the pre-visit confirmation action. These
        // are deliberately distinct from the original "schedule a visit"
        // outcome, so a confirmation call cannot accidentally show scheduling
        // or lead-stage outcomes.
        $confirmActionId = DB::table('followup_action_types')->where('key', 'confirm_site_visit')->value('id');
        if ($confirmActionId) {
            $now = now();
            $rows = [
                ['key'=>'visit_confirmed','label'=>'Customer confirmed the site visit','category'=>'positive','is_connected'=>1,'next_action_type_id'=>DB::table('followup_action_types')->where('key','visit_reminder')->value('id'),'next_action_delay_hours'=>24,'priority'=>'high','suggested_status_id'=>null,'context_action_key'=>'confirm_site_visit','contact_status_key'=>null,'requires_site_visit_datetime'=>false,'next_action_anchor'=>'visit_before','is_active'=>1,'sort_order'=>10],
                ['key'=>'visit_reschedule_requested','label'=>'Customer asked to reschedule','category'=>'neutral','is_connected'=>1,'next_action_type_id'=>DB::table('followup_action_types')->where('key','confirm_site_visit')->value('id'),'next_action_delay_hours'=>24,'priority'=>'high','suggested_status_id'=>null,'context_action_key'=>'confirm_site_visit','contact_status_key'=>null,'requires_site_visit_datetime'=>true,'next_action_anchor'=>'now','is_active'=>1,'sort_order'=>20],
                ['key'=>'visit_cancelled','label'=>'Customer cancelled the site visit','category'=>'negative','is_connected'=>1,'next_action_type_id'=>DB::table('followup_action_types')->where('key','reactivation_call')->value('id'),'next_action_delay_hours'=>2160,'priority'=>'normal','suggested_status_id'=>null,'context_action_key'=>'confirm_site_visit','contact_status_key'=>null,'requires_site_visit_datetime'=>false,'next_action_anchor'=>'now','is_active'=>1,'sort_order'=>30],
                ['key'=>'visit_not_reachable','label'=>'Could not reach customer for confirmation','category'=>'neutral','is_connected'=>0,'next_action_type_id'=>DB::table('followup_action_types')->where('key','confirm_site_visit')->value('id'),'next_action_delay_hours'=>4,'priority'=>'high','suggested_status_id'=>null,'context_action_key'=>'confirm_site_visit','contact_status_key'=>null,'requires_site_visit_datetime'=>false,'next_action_anchor'=>'now','is_active'=>1,'sort_order'=>40],
            ];
            foreach ($rows as $row) {
                $row['created_at'] = $now;
                $row['updated_at'] = $now;
                DB::table('call_outcomes')->updateOrInsert(['key' => $row['key']], $row);
            }
        }

        DB::table('call_outcomes')->whereIn('key', ['booking_confirmed','booking_postponed','booking_payment_issue','booking_backed_out','booking_needs_more_time'])
            ->whereNull('context_action_key')->update(['context_action_key'=>'booking_confirmation','activity_type_filter'=>'call','updated_at'=>now()]);
        DB::table('call_outcomes')->whereIn('key', ['negotiating','booking_confirmed','booking_postponed','loan_denied','loan_in_process'])
            ->whereNull('context_action_key')->update(['context_action_key'=>'negotiation_followup','activity_type_filter'=>'call','updated_at'=>now()]);
        DB::table('call_outcomes')->whereIn('key', ['thanks_happy','thanks_referral_won'])
            ->whereNull('context_action_key')->update(['context_action_key'=>'thank_you_call','activity_type_filter'=>'call','updated_at'=>now()]);

        $reminderRows = [
            ['key'=>'reminder_confirmed','label'=>'Customer confirmed they are coming','category'=>'positive','is_connected'=>1,'next_action_type_id'=>DB::table('followup_action_types')->where('key','visit_feedback_call')->value('id'),'next_action_delay_hours'=>1,'priority'=>'high','suggested_status_id'=>null,'context_action_key'=>'visit_reminder','contact_status_key'=>null,'requires_site_visit_datetime'=>0,'next_action_anchor'=>'visit_after','is_active'=>1,'sort_order'=>10,'activity_type_filter'=>'call'],
            ['key'=>'reminder_reschedule_requested','label'=>'Customer asked to change the visit time','category'=>'neutral','is_connected'=>1,'next_action_type_id'=>DB::table('followup_action_types')->where('key','confirm_site_visit')->value('id'),'next_action_delay_hours'=>24,'priority'=>'high','suggested_status_id'=>null,'context_action_key'=>'visit_reminder','contact_status_key'=>null,'requires_site_visit_datetime'=>1,'next_action_anchor'=>'now','is_active'=>1,'sort_order'=>20,'activity_type_filter'=>'call'],
            ['key'=>'reminder_cancelled','label'=>'Customer cancelled the visit','category'=>'negative','is_connected'=>1,'next_action_type_id'=>DB::table('followup_action_types')->where('key','reactivation_call')->value('id'),'next_action_delay_hours'=>2160,'priority'=>'normal','suggested_status_id'=>null,'context_action_key'=>'visit_reminder','contact_status_key'=>null,'requires_site_visit_datetime'=>0,'next_action_anchor'=>'now','is_active'=>1,'sort_order'=>30,'activity_type_filter'=>'call'],
            ['key'=>'reminder_no_contact','label'=>'Could not reach customer for the reminder','category'=>'neutral','is_connected'=>0,'next_action_type_id'=>DB::table('followup_action_types')->where('key','visit_reminder')->value('id'),'next_action_delay_hours'=>2,'priority'=>'high','suggested_status_id'=>null,'context_action_key'=>'visit_reminder','contact_status_key'=>null,'requires_site_visit_datetime'=>0,'next_action_anchor'=>'now','is_active'=>1,'sort_order'=>40,'activity_type_filter'=>'call'],
        ];
        foreach ($reminderRows as $row) {
            $row['created_at']=now(); $row['updated_at']=now();
            DB::table('call_outcomes')->updateOrInsert(['key'=>$row['key']], $row);
        }

        // Action-specific communication outcomes. Existing rows are reused;
        // only missing rows are added. These next actions are intentionally
        // explicit rather than falling back to a generic follow-up call.
        $actionRows = [
            ['key'=>'details_sent','label'=>'Requested details were sent','category'=>'positive','is_connected'=>0,'next'=>'whatsapp_followup','delay'=>4,'priority'=>'normal','context'=>'send_details','channel'=>'whatsapp'],
            ['key'=>'details_not_sent','label'=>'Details could not be sent','category'=>'negative','is_connected'=>0,'next'=>'send_details','delay'=>2,'priority'=>'high','context'=>'send_details','channel'=>'whatsapp'],
            ['key'=>'brochure_sent','label'=>'Brochure was sent','category'=>'positive','is_connected'=>0,'next'=>'whatsapp_followup','delay'=>4,'priority'=>'normal','context'=>'send_brochure','channel'=>'whatsapp'],
            ['key'=>'brochure_not_sent','label'=>'Brochure could not be sent','category'=>'negative','is_connected'=>0,'next'=>'send_brochure','delay'=>2,'priority'=>'high','context'=>'send_brochure','channel'=>'whatsapp'],
            ['key'=>'budget_options_sent','label'=>'Budget options were sent','category'=>'positive','is_connected'=>0,'next'=>'whatsapp_followup','delay'=>4,'priority'=>'normal','context'=>'send_budget_options','channel'=>'whatsapp'],
            ['key'=>'location_options_sent','label'=>'Location options were sent','category'=>'positive','is_connected'=>0,'next'=>'whatsapp_followup','delay'=>4,'priority'=>'normal','context'=>'send_location_opts','channel'=>'whatsapp'],
            ['key'=>'customer_requested_call','label'=>'Customer asked to discuss by call','category'=>'neutral','is_connected'=>0,'next'=>'followup_call','delay'=>1,'priority'=>'high','context'=>'send_details','channel'=>'whatsapp'],
        ];
        $now = now();
        foreach ($actionRows as $r) {
            if (DB::table('call_outcomes')->where('key', $r['key'])->exists()) continue;
            $nextId = DB::table('followup_action_types')->where('key', $r['next'])->value('id');
            DB::table('call_outcomes')->insert([
                'key'=>$r['key'],'label'=>$r['label'],'category'=>$r['category'],'is_connected'=>$r['is_connected'],
                'next_action_type_id'=>$nextId,'next_action_delay_hours'=>$r['delay'],'priority'=>$r['priority'],
                'suggested_status_id'=>null,'context_action_key'=>$r['context'],'contact_status_key'=>null,
                'requires_site_visit_datetime'=>0,'next_action_anchor'=>'now','is_active'=>1,
                'sort_order'=>90,'activity_type_filter'=>$r['channel'],'created_at'=>$now,'updated_at'=>$now,
            ]);
        }
    }

    public function down(): void
    {
        // Intentionally do not delete outcome rows or rewrite existing outcome
        // configuration. Remove only columns owned by this release.
        if (Schema::hasColumn('call_outcomes', 'next_action_anchor')) {
            Schema::table('call_outcomes', fn (Blueprint $table) => $table->dropColumn('next_action_anchor'));
        }
        if (Schema::hasColumn('call_outcomes', 'requires_site_visit_datetime')) {
            Schema::table('call_outcomes', fn (Blueprint $table) => $table->dropColumn('requires_site_visit_datetime'));
        }
        if (Schema::hasColumn('call_outcomes', 'contact_status_key')) {
            Schema::table('call_outcomes', fn (Blueprint $table) => $table->dropColumn('contact_status_key'));
        }
        if (Schema::hasColumn('contacts', 'site_visit_scheduled_at')) {
            Schema::table('contacts', function (Blueprint $table) {
                $table->dropIndex('contacts_site_visit_scheduled_idx');
                $table->dropColumn('site_visit_scheduled_at');
            });
        }
    }
};
