<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class BookingFinancialAudit extends Model {
    public $timestamps=false;
    protected $table='booking_financial_audits';
    protected $fillable=['booking_financial_id','event_type','actor_user_id','reason','before_json','after_json','created_at'];
    protected $casts=['created_at'=>'datetime','before_json'=>'array','after_json'=>'array'];
}
