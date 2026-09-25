<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_share_packages', function (Blueprint $table) {
            $table->string('share_status', 30)
                ->default('pending')
                ->index()
                ->after('message');

            $table->timestamp('confirmed_at')
                ->nullable()
                ->index()
                ->after('share_status');

            $table->unsignedBigInteger('confirmed_by_user_id')
                ->nullable()
                ->index()
                ->after('confirmed_at');

            $table->string('failure_reason', 100)
                ->nullable()
                ->after('confirmed_by_user_id');
        });
    }

    public function down(): void
    {
        Schema::table('project_share_packages', function (Blueprint $table) {
            $table->dropColumn([
                'share_status',
                'confirmed_at',
                'confirmed_by_user_id',
                'failure_reason',
            ]);
        });
    }
};
