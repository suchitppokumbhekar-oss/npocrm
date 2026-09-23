<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('users', 'default_whatsapp_account_type')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            $table->string('default_whatsapp_account_type', 20)
                ->nullable()
                ->after('is_on_payroll');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('users', 'default_whatsapp_account_type')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('default_whatsapp_account_type');
        });
    }
};
