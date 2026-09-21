<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class IncentiveDeal extends Model
{
    protected $table = 'incentive_deals';

    protected $fillable = [
        'lead_id', 'agent_id', 'booking_date', 'booked_nb', 'settled_nb',
        'developer_adjustment', 'client_passback', 'sub_broker_share',
        'referral_fee', 'status', 'notes', 'policy_snapshot_json', 'created_by_user_id',
    ];

    protected $casts = [
        'booking_date' => 'date',
        'booked_nb' => 'decimal:2',
        'settled_nb' => 'decimal:2',
        'developer_adjustment' => 'decimal:2',
        'client_passback' => 'decimal:2',
        'sub_broker_share' => 'decimal:2',
        'referral_fee' => 'decimal:2',
    ];

    public function agent() { return $this->belongsTo(Agent::class); }
    public function lead() { return $this->belongsTo(Lead::class); }
    public function collections() { return $this->hasMany(IncentiveCollection::class, 'deal_id'); }
    public function payouts() { return $this->hasMany(IncentivePayout::class, 'deal_id'); }

    public function effectiveNb(): float
    {
        return (float) ($this->settled_nb ?? $this->booked_nb ?? 0);
    }
}
