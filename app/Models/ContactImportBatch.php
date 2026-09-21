<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ContactImportBatch extends Model
{
    protected $table = 'contact_import_batches';

    protected $fillable = [
        'user_id', 'filename', 'total_rows', 'imported', 'skipped', 'failed', 'error_log',
    ];

    protected $casts = [
        'total_rows' => 'integer',
        'imported'   => 'integer',
        'skipped'    => 'integer',
        'failed'     => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function user() { return $this->belongsTo(User::class); }
}