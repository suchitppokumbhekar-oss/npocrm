<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('contact_followups')) {
            Schema::create('contact_followups', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('contact_id');
                $table->unsignedBigInteger('agent_id')->nullable();
                $table->dateTime('scheduled_for')->nullable();
                $table->string('action_type', 80)->default('followup_call');
                $table->string('priority', 20)->default('normal');
                $table->string('status', 20)->default('pending');
                $table->boolean('auto_created')->default(true);
                $table->unsignedBigInteger('source_call_id')->nullable();
                $table->string('notes', 2000)->nullable();
                $table->timestamps();

                $table->index(['agent_id', 'status', 'scheduled_for'], 'contact_followups_work_idx');
                $table->index(['contact_id', 'status'], 'contact_followups_contact_idx');
                $table->foreign('contact_id')->references('id')->on('contacts')->cascadeOnDelete();
            });
        }

        if (! Schema::hasTable('contact_call_sessions')) {
            Schema::create('contact_call_sessions', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('contact_id');
                $table->unsignedBigInteger('agent_id');
                $table->dateTime('started_at');
                $table->dateTime('completed_at')->nullable();
                $table->unsignedBigInteger('call_id')->nullable();
                $table->timestamps();

                $table->index(['agent_id', 'completed_at', 'started_at'], 'contact_call_sessions_work_idx');
                $table->index(['contact_id', 'completed_at'], 'contact_call_sessions_contact_idx');
                $table->foreign('contact_id')->references('id')->on('contacts')->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_call_sessions');
        Schema::dropIfExists('contact_followups');
    }
};
