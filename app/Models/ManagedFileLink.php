<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ManagedFileLink extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'managed_file_id',
        'entity_type',
        'entity_id',
        'context_type',
        'context_id',
        'workflow_stage',
        'relationship',
        'linked_by_user_id',
        'created_at',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public function file()
    {
        return $this->belongsTo(ManagedFile::class, 'managed_file_id');
    }

    public function linkedBy()
    {
        return $this->belongsTo(User::class, 'linked_by_user_id');
    }
}
