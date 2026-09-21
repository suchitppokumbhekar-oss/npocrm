<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasColumn('activities', 'action_source')) {
            Schema::table('activities', function (Blueprint $table) {
                $table->string('action_source', 20)->default('manual')->after('logged_at')->index();
            });
            // Existing records cannot be safely classified from Build 31 history.
            // Keep them visibly unclassified rather than falsely calling them manual.
            \DB::table('activities')->update(['action_source' => 'legacy']);
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('activities', 'action_source')) {
            Schema::table('activities', function (Blueprint $table) {
                $table->dropIndex(['action_source']);
                $table->dropColumn('action_source');
            });
        }
    }
};
