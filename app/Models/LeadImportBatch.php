<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LeadImportBatch extends Model
{
    protected $table = 'lead_import_batches';

    protected $fillable = [
        'user_id', 'filename', 'total_rows',
        'imported_rows', 'skipped_rows', 'failed_rows', 'error_log',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function user() { return $this->belongsTo(User::class); }
}