<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('push_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->text('endpoint');
            $table->text('p256dh');
            $table->text('auth');
            $table->string('content_encoding', 32)->nullable();
            $table->unsignedBigInteger('last_notification_id')->default(0);
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('last_error_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'last_notification_id']);
            $table->index('last_used_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('push_subscriptions');
    }
};
