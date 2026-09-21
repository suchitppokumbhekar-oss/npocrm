<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('managed_files')) {
            return;
        }

        Schema::create('managed_files', function (Blueprint $table) {
            $table->id();
            $table->string('original_name', 255);
            $table->string('storage_path', 500);
            $table->string('disk', 50)->default('local');
            $table->string('mime_type', 150)->nullable();
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->char('sha256', 64)->nullable()->index();
            $table->string('file_kind', 30)->index();
            $table->string('source_label', 100)->nullable()->index();
            $table->unsignedBigInteger('uploaded_by_user_id')->nullable()->index();
            $table->timestamp('created_at')->useCurrent()->index();
            $table->index(['file_kind', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('managed_files');
    }
};
