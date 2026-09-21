<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class BookingPayable extends Model {
    protected $table='booking_payables';
    protected $fillable=['booking_financial_id','payable_type_id','payable_type_name_snapshot','category','payee_name','payee_reference','amount','calculated_amount','calculation_rate','calculation_basis','due_date','status','affects_net_brokerage','notes','created_by_user_id','approved_by_user_id','approved_at','cancelled_by_user_id','cancelled_at','cancellation_reason'];
    protected $casts=['amount'=>'decimal:2','calculated_amount'=>'decimal:2','calculation_rate'=>'decimal:6','due_date'=>'date','affects_net_brokerage'=>'boolean','approved_at'=>'datetime','cancelled_at'=>'datetime'];
    public function booking(){return $this->belongsTo(BookingFinancial::class,'booking_financial_id');}
    public function type(){return $this->belongsTo(BookingPayableType::class,'payable_type_id');}
    public function payments(){return $this->hasMany(BookingPayablePayment::class,'booking_payable_id');}
}
