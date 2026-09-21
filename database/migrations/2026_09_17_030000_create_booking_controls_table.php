<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('booking_controls', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lead_id')->unique()->constrained('leads')->cascadeOnDelete();
            $table->string('status', 20)->default('pending')->index();
            $table->unsignedBigInteger('requested_by_user_id')->nullable()->index();
            $table->dateTime('requested_at')->nullable();
            $table->unsignedBigInteger('approved_by_user_id')->nullable()->index();
            $table->dateTime('approved_at')->nullable();
            $table->unsignedBigInteger('rejected_by_user_id')->nullable()->index();
            $table->dateTime('rejected_at')->nullable();
            $table->text('decision_note')->nullable();
            $table->json('snapshot')->nullable();
            $table->timestamps();

            $table->foreign('requested_by_user_id')->references('id')->on('users')->nullOnDelete();
            $table->foreign('approved_by_user_id')->references('id')->on('users')->nullOnDelete();
            $table->foreign('rejected_by_user_id')->references('id')->on('users')->nullOnDelete();
        });

        // Preserve existing booked leads as historically approved. We do not
        // invent an approver; the existing lead/activity history remains the
        // authoritative historical record of who performed the booking work.
        $now = now();
        DB::table('leads')->where('status', 'booking')->orderBy('id')->chunkById(500, function ($leads) use ($now): void {
            foreach ($leads as $lead) {
                DB::table('booking_controls')->insertOrIgnore([
                    'lead_id' => $lead->id,
                    'status' => 'approved',
                    'requested_by_user_id' => null,
                    'requested_at' => $lead->booking_date ? $lead->booking_date . ' 00:00:00' : $lead->created_at,
                    'approved_by_user_id' => null,
                    'approved_at' => null,
                    'decision_note' => 'Historical booking backfilled as approved during Phase 4 Booking Control deployment.',
                    'snapshot' => json_encode([
                        'booking_amount' => $lead->booking_amount,
                        'property_area_sqft' => $lead->property_area_sqft,
                        'rate_per_sqft' => $lead->rate_per_sqft,
                        'booking_unit' => $lead->booking_unit,
                        'booking_payment_mode' => $lead->booking_payment_mode,
                        'booking_date' => $lead->booking_date,
                        'brokerage_percentage' => $lead->brokerage_percentage,
                        'brokerage_amount' => $lead->brokerage_amount,
                        'brokerage_status' => $lead->brokerage_status,
                        'brokerage_expected_at' => $lead->brokerage_expected_at,
                        'brokerage_received_at' => $lead->brokerage_received_at,
                        'co_broker_name' => $lead->co_broker_name,
                    ], JSON_UNESCAPED_UNICODE),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_controls');
    }
};
