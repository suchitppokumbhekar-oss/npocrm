<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_share_packages', function (Blueprint $table) {
            $table->char('token_hash', 64)
                ->nullable()
                ->unique()
                ->after('token');
        });
    }

    public function down(): void
    {
        Schema::table('project_share_packages', function (Blueprint $table) {
            $table->dropUnique(['token_hash']);
            $table->dropColumn('token_hash');
        });
    }
};
