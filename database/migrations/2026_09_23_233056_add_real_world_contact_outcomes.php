<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $actionIds = DB::table('followup_action_types')->pluck('id', 'key');
        $statusIds = DB::table('lead_statuses')->pluck('id', 'key');

        $outcomes = [
            [
                'key' => 'ringing_no_answer',
                'label' => '📞 Ringing — No Answer Yet',
                'category' => 'neutral',
                'is_connected' => 0,
                'next_action_type_id' => $actionIds['retry_call'] ?? null,
                'next_action_delay_hours' => 2,
                'priority' => 'normal',
                'suggested_status_id' => null,
                'context_action_key' => null,
                'contact_status_key' => null,
                'requires_site_visit_datetime' => 0,
                'next_action_anchor' => 'now',
                'activity_type_filter' => 'call',
                'prompts_whatsapp_send' => 0,
                'is_active' => 1,
                'sort_order' => 6,
            ],
            [
                'key' => 'low_budget',
                'label' => '💰 Low Budget',
                'category' => 'neutral',
                'is_connected' => 1,
                'next_action_type_id' => $actionIds['send_budget_options'] ?? null,
                'next_action_delay_hours' => 48,
                'priority' => 'normal',
                'suggested_status_id' => null,
                'context_action_key' => null,
                'contact_status_key' => null,
                'requires_site_visit_datetime' => 0,
                'next_action_anchor' => 'now',
                'activity_type_filter' => 'call,whatsapp',
                'prompts_whatsapp_send' => 0,
                'is_active' => 1,
                'sort_order' => 12,
            ],
            [
                'key' => 'visit_on_the_way',
                'label' => '🚗 On the Way',
                'category' => 'positive',
                'is_connected' => 1,
                'next_action_type_id' => $actionIds['visit_feedback_call'] ?? null,
                'next_action_delay_hours' => 1,
                'priority' => 'high',
                'suggested_status_id' => null,
                'context_action_key' => 'visit_reminder',
                'contact_status_key' => null,
                'requires_site_visit_datetime' => 0,
                'next_action_anchor' => 'visit_after',
                'activity_type_filter' => 'call,whatsapp',
                'prompts_whatsapp_send' => 0,
                'is_active' => 1,
                'sort_order' => 11,
            ],
            [
                'key' => 'visit_done',
                'label' => '🏠 Visit Done',
                'category' => 'positive',
                'is_connected' => 1,
                'next_action_type_id' => $actionIds['visit_outcome_call'] ?? null,
                'next_action_delay_hours' => 24,
                'priority' => 'high',
                'suggested_status_id' => $statusIds['visit_done'] ?? null,
                'context_action_key' => 'visit_feedback_call',
                'contact_status_key' => null,
                'requires_site_visit_datetime' => 0,
                'next_action_anchor' => 'now',
                'activity_type_filter' => 'site_visit',
                'prompts_whatsapp_send' => 0,
                'is_active' => 1,
                'sort_order' => 31,
            ],
        ];

        foreach ($outcomes as $outcome) {
            DB::table('call_outcomes')->updateOrInsert(
                ['key' => $outcome['key']],
                $outcome
            );
        }
    }

    public function down(): void
    {
        DB::table('call_outcomes')->whereIn('key', [
            'ringing_no_answer',
            'low_budget',
            'visit_on_the_way',
            'visit_done',
        ])->delete();
    }
};
