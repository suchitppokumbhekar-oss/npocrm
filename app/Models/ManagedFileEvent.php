<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ManagedFileEvent extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'managed_file_id',
        'event_type',
        'user_id',
        'channel',
        'details',
        'created_at',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public function file()
    {
        return $this->belongsTo(ManagedFile::class, 'managed_file_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
