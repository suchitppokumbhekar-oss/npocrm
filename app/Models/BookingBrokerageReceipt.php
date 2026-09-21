<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class BookingBrokerageReceipt extends Model {
    protected $table='booking_brokerage_receipts';
    protected $fillable=['booking_financial_id','receipt_date','amount','payer_name','payment_reference','payment_mode','notes','incentive_collection_id','created_by_user_id'];
    protected $casts=['receipt_date'=>'date','amount'=>'decimal:2'];
    public function booking(){return $this->belongsTo(BookingFinancial::class,'booking_financial_id');}
    public function incentiveCollection(){return $this->belongsTo(IncentiveCollection::class,'incentive_collection_id');}
}
