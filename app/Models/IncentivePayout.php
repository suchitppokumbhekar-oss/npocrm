<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class IncentivePayout extends Model
{
    public const PROVISIONAL_PAID = 'provisional_paid';
    public const HELD_ACCRUAL = 'held_accrual';
    public const HELD_RELEASE = 'held_release';
    public const RECOVERY = 'recovery';
    public const TRUE_UP_PAYMENT = 'true_up_payment';
    public const TRUE_UP_RECOVERY = 'true_up_recovery';
    public const RECOVERY_CARRY = 'recovery_carry';
    public const HELD_CLOSURE = 'held_closure';
    public const WRITE_OFF = 'write_off';

    protected $table = 'incentive_payouts';

    protected $fillable = [
        'agent_id', 'deal_id', 'collection_id', 'period_key', 'type',
        'amount', 'reference', 'notes', 'created_by_user_id',
    ];

    protected $casts = ['amount' => 'decimal:2'];

    public function agent() { return $this->belongsTo(Agent::class); }
    public function deal() { return $this->belongsTo(IncentiveDeal::class, 'deal_id'); }
    public function collection() { return $this->belongsTo(IncentiveCollection::class, 'collection_id'); }
}
