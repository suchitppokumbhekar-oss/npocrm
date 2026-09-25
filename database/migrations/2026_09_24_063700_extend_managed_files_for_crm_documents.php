<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('managed_files', function (Blueprint $table) {
            $table->string('title', 255)->nullable()->after('original_name');
            $table->text('description')->nullable()->after('title');

            $table->string('document_category', 60)
                ->default('general')
                ->index()
                ->after('file_kind');

            $table->string('visibility', 30)
                ->default('internal')
                ->index()
                ->after('document_category');

            $table->boolean('customer_shareable')
                ->default(false)
                ->index()
                ->after('visibility');

            $table->boolean('share_approved')
                ->default(false)
                ->index()
                ->after('customer_shareable');

            $table->unsignedBigInteger('share_approved_by_user_id')
                ->nullable()
                ->index()
                ->after('share_approved');

            $table->timestamp('share_approved_at')
                ->nullable()
                ->after('share_approved_by_user_id');

            $table->unsignedInteger('version_number')
                ->default(1)
                ->after('share_approved_at');

            $table->unsignedBigInteger('replaces_file_id')
                ->nullable()
                ->index()
                ->after('version_number');

            $table->timestamp('valid_from')
                ->nullable()
                ->index()
                ->after('replaces_file_id');

            $table->timestamp('valid_until')
                ->nullable()
                ->index()
                ->after('valid_from');

            $table->timestamp('removed_at')
                ->nullable()
                ->index()
                ->after('valid_until');

            $table->unsignedBigInteger('removed_by_user_id')
                ->nullable()
                ->index()
                ->after('removed_at');

            $table->string('removal_reason', 500)
                ->nullable()
                ->after('removed_by_user_id');
        });

        Schema::create('managed_file_links', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('managed_file_id')->index();

            $table->string('entity_type', 50)->index();
            $table->unsignedBigInteger('entity_id')->index();

            $table->string('context_type', 60)->nullable()->index();
            $table->unsignedBigInteger('context_id')->nullable()->index();

            $table->string('workflow_stage', 60)->nullable()->index();
            $table->string('relationship', 60)->default('attachment')->index();

            $table->unsignedBigInteger('linked_by_user_id')->nullable()->index();
            $table->timestamp('created_at')->useCurrent()->index();

            $table->unique(
                ['managed_file_id', 'entity_type', 'entity_id', 'relationship'],
                'managed_file_links_unique'
            );

            $table->index(
                ['entity_type', 'entity_id', 'relationship'],
                'managed_file_links_entity'
            );
        });

        Schema::create('managed_file_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('managed_file_id')->index();
            $table->string('event_type', 40)->index();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->string('channel', 40)->nullable()->index();
            $table->text('details')->nullable();
            $table->timestamp('created_at')->useCurrent()->index();

            $table->index(
                ['managed_file_id', 'event_type', 'created_at'],
                'managed_file_events_file_event'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('managed_file_events');
        Schema::dropIfExists('managed_file_links');

        Schema::table('managed_files', function (Blueprint $table) {
            $table->dropColumn([
                'title',
                'description',
                'document_category',
                'visibility',
                'customer_shareable',
                'share_approved',
                'share_approved_by_user_id',
                'share_approved_at',
                'version_number',
                'replaces_file_id',
                'valid_from',
                'valid_until',
                'removed_at',
                'removed_by_user_id',
                'removal_reason',
            ]);
        });
    }
};
