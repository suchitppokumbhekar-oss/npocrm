<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_share_packages', function (Blueprint $table) {
            $table->id();
            $table->string('token', 64)->unique();
            $table->unsignedBigInteger('lead_id')->index();
            $table->unsignedBigInteger('project_id')->index();
            $table->unsignedBigInteger('created_by_user_id')->index();
            $table->string('channel', 40)->default('whatsapp')->index();
            $table->string('recipient_phone', 30)->nullable();
            $table->text('message')->nullable();
            $table->timestamp('expires_at')->index();
            $table->timestamp('revoked_at')->nullable()->index();
            $table->unsignedBigInteger('revoked_by_user_id')->nullable()->index();
            $table->timestamp('last_viewed_at')->nullable()->index();
            $table->unsignedInteger('view_count')->default(0);
            $table->timestamp('created_at')->useCurrent()->index();
        });

        Schema::create('project_share_package_files', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('project_share_package_id')->index();
            $table->foreign('project_share_package_id', 'pspf_package_fk')->references('id')->on('project_share_packages')->cascadeOnDelete();
            $table->unsignedBigInteger('managed_file_id')->index();
            $table->unsignedInteger('version_number')->default(1);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamp('created_at')->useCurrent();

            $table->unique(
                ['project_share_package_id', 'managed_file_id'],
                'project_share_package_file_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_share_package_files');
        Schema::dropIfExists('project_share_packages');
    }
};
