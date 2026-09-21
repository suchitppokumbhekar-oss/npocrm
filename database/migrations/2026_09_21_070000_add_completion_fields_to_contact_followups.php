<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('contact_followups', function (Blueprint $table) {
            $table->dateTime('completed_at')->nullable()->after('notes');
            $table->unsignedBigInteger('completed_by_agent_id')->nullable()->after('completed_at');
            $table->string('completion_outcome_key', 80)->nullable()->after('completed_by_agent_id');
            $table->string('completion_notes', 2000)->nullable()->after('completion_outcome_key');
            $table->index(['completed_by_agent_id', 'completed_at'], 'contact_followups_completed_idx');
        });
    }

    public function down(): void
    {
        Schema::table('contact_followups', function (Blueprint $table) {
            $table->dropIndex('contact_followups_completed_idx');
            $table->dropColumn(['completed_at', 'completed_by_agent_id', 'completion_outcome_key', 'completion_notes']);
        });
    }
};
