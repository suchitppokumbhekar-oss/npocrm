<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class BookingPayablePayment extends Model {
    protected $table='booking_payable_payments';
    protected $fillable=['booking_payable_id','payment_date','amount','payment_reference','payment_mode','notes','created_by_user_id'];
    protected $casts=['payment_date'=>'date','amount'=>'decimal:2'];
    public function payable(){return $this->belongsTo(BookingPayable::class,'booking_payable_id');}
}
