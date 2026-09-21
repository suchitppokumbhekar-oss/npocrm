<?php

namespace App\Models\Config;

use Illuminate\Database\Eloquent\Model;

class LeadStatusTransition extends Model
{
    protected $table = 'lead_status_transitions';

    public $timestamps = false;

    protected $fillable = ['from_status_id', 'to_status_id'];

    public function fromStatus()
    {
        return $this->belongsTo(LeadStatus::class, 'from_status_id');
    }

    public function toStatus()
    {
        return $this->belongsTo(LeadStatus::class, 'to_status_id');
    }
}