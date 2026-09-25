<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_share_packages', function (Blueprint $table) {
            $table->dropUnique(['token']);
            $table->dropColumn('token');

            $table->char('token_hash', 64)
                ->nullable(false)
                ->change();
        });
    }

    public function down(): void
    {
        Schema::table('project_share_packages', function (Blueprint $table) {
            $table->string('token', 64)->nullable()->unique()->after('id');
            $table->char('token_hash', 64)->nullable()->change();
        });
    }
};
