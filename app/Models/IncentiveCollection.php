<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class IncentiveCollection extends Model
{
    protected $table = 'incentive_collections';

    protected $fillable = [
        'deal_id', 'collection_date', 'amount', 'notes', 'created_by_user_id',
    ];

    protected $casts = [
        'collection_date' => 'date',
        'amount' => 'decimal:2',
    ];

    public function deal() { return $this->belongsTo(IncentiveDeal::class, 'deal_id'); }
    public function payouts() { return $this->hasMany(IncentivePayout::class, 'collection_id'); }
}
