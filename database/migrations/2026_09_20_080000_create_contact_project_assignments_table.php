<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('contact_project_assignments')) return;

        Schema::create('contact_project_assignments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('contact_id');
            $table->unsignedBigInteger('project_id');
            $table->unsignedBigInteger('assigned_to_agent_id')->nullable();
            $table->unsignedBigInteger('assigned_by_user_id');
            $table->string('status', 20)->default('active');
            $table->timestamp('assigned_at')->useCurrent();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['contact_id', 'status']);
            $table->index(['project_id', 'status']);
            $table->index(['assigned_to_agent_id', 'status']);
            $table->index('assigned_by_user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_project_assignments');
    }
};
