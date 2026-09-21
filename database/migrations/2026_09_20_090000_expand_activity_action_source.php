<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasColumn('activities', 'action_source')) {
            Schema::table('activities', function (Blueprint $table) {
                $table->string('action_source', 50)->default('manual')->change();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('activities', 'action_source')) {
            Schema::table('activities', function (Blueprint $table) {
                $table->string('action_source', 20)->default('manual')->change();
            });
        }
    }
};
