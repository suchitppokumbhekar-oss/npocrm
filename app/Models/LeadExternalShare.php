<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LeadExternalShare extends Model
{
    protected $table = 'lead_external_shares';

    protected $fillable = [
        'lead_id',
        'shared_by_user_id',
        'group_name',
        'reason_key',
        'reason_label',
        'extra_notes',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function lead()
    {
        return $this->belongsTo(Lead::class);
    }

    public function sharedBy()
    {
        return $this->belongsTo(User::class, 'shared_by_user_id');
    }
}