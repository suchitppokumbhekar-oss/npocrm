<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('leads', 'lost_reason_key')) {
            Schema::table('leads', function (Blueprint $table) {
                $table->string('lost_reason_key', 80)->nullable()->after('lost_reason')->index();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('leads', 'lost_reason_key')) {
            Schema::table('leads', function (Blueprint $table) {
                $table->dropIndex(['lost_reason_key']);
                $table->dropColumn('lost_reason_key');
            });
        }
    }
};
