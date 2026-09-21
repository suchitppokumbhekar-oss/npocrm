<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        $defaults = [
            'first_contact_delay_minutes' => '15',
            'followup_work_start'         => '10:30',
            'followup_work_end'           => '20:00',
            // Real-estate sales teams may receive and work leads every day;
            // the automatic guard is therefore a daily 10:30–20:00 window.
            'followup_working_days'       => '0,1,2,3,4,5,6',
        ];

        foreach ($defaults as $key => $value) {
            if (! DB::table('settings')->where('key', $key)->exists()) {
                DB::table('settings')->insert([
                    'key'        => $key,
                    'value'      => $value,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        DB::table('settings')->whereIn('key', [
            'followup_work_start',
            'followup_work_end',
            'followup_working_days',
        ])->delete();
    }
};
