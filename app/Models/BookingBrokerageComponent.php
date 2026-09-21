<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class BookingBrokerageComponent extends Model {
    protected $table='booking_brokerage_components';
    protected $fillable=['booking_financial_id','component_type','label','base_amount','rate','amount','sequence_no','rule_id','rule_version','snapshot_json'];
    protected $casts=['base_amount'=>'decimal:2','rate'=>'decimal:6','amount'=>'decimal:2','snapshot_json'=>'array'];
    public function booking(){return $this->belongsTo(BookingFinancial::class,'booking_financial_id');}
}
