<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class BookingPayableType extends Model {
    protected $table='booking_payable_types';
    protected $fillable=['name','code','category','calculation_type','base_type','default_value','affects_net_brokerage','requires_approval','active','sort_order','description','created_by_user_id'];
    protected $casts=['default_value'=>'decimal:6','affects_net_brokerage'=>'boolean','requires_approval'=>'boolean','active'=>'boolean'];
}
