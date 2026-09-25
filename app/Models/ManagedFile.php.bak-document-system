<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ManagedFile extends Model
{
    public $timestamps = false;

    protected $table = 'managed_files';

    protected $fillable = [
        'original_name',
        'storage_path',
        'disk',
        'mime_type',
        'size_bytes',
        'sha256',
        'file_kind',
        'source_label',
        'uploaded_by_user_id',
        'created_at',
    ];

    protected $casts = [
        'size_bytes' => 'integer',
        'uploaded_by_user_id' => 'integer',
        'created_at' => 'datetime',
    ];

    public function uploader()
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id');
    }
}
