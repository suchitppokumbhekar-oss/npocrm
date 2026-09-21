<?php

use App\Models\AuditLog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('audit_logs')) {
            return;
        }

        Schema::table('audit_logs', function (Blueprint $table) {
            if (! Schema::hasColumn('audit_logs', 'previous_hash')) {
                $table->char('previous_hash', 64)->nullable()->index()->after('created_at');
            }
            if (! Schema::hasColumn('audit_logs', 'hash')) {
                $table->char('hash', 64)->nullable()->unique()->after('previous_hash');
            }
            if (! Schema::hasColumn('audit_logs', 'hash_version')) {
                $table->unsignedSmallInteger('hash_version')->default(1)->after('hash');
            }
        });

        // Backfill the existing vigilance ledger in ID order. This is deterministic
        // and does not change any business/audit content, only integrity metadata.
        $previous = AuditLog::GENESIS_HASH;
        DB::table('audit_logs')->orderBy('id')->chunkById(500, function ($rows) use (&$previous): void {
            foreach ($rows as $row) {
                $log = new AuditLog();
                $log->forceFill((array) $row);
                $log->previous_hash = $previous;
                $log->hash_version = 1;
                $log->hash = $log->calculateHash();

                DB::table('audit_logs')->where('id', $log->id)->update([
                    'previous_hash' => $log->previous_hash,
                    'hash' => $log->hash,
                    'hash_version' => 1,
                ]);
                $previous = $log->hash;
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('audit_logs')) {
            return;
        }
        Schema::table('audit_logs', function (Blueprint $table) {
            if (Schema::hasColumn('audit_logs', 'hash')) {
                $table->dropUnique(['hash']);
            }
            $columns = [];
            foreach (['previous_hash', 'hash', 'hash_version'] as $column) {
                if (Schema::hasColumn('audit_logs', $column)) {
                    $columns[] = $column;
                }
            }
            if ($columns) {
                $table->dropColumn($columns);
            }
        });
    }
};
