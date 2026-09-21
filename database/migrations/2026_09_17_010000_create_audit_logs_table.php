<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('audit_logs')) {
            return;
        }

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('actor_user_id')->nullable()->index();
            $table->string('actor_name', 150)->nullable();
            $table->string('actor_role', 50)->nullable()->index();
            $table->string('event_category', 50)->index();
            $table->string('action', 120)->index();
            $table->string('target_type', 100)->nullable()->index();
            $table->unsignedBigInteger('target_id')->nullable()->index();
            $table->string('route_name', 150)->nullable();
            $table->string('method', 10);
            $table->string('path', 500);
            $table->unsignedSmallInteger('status_code')->nullable()->index();
            $table->string('ip_address', 45)->nullable()->index();
            $table->string('user_agent', 500)->nullable();
            $table->json('details')->nullable();
            $table->timestamp('created_at')->useCurrent()->index();

            $table->index(['event_category', 'created_at']);
            $table->index(['actor_user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
