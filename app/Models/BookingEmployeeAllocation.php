<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class BookingEmployeeAllocation extends Model {
    protected $table='booking_employee_allocations';
    protected $fillable=['booking_financial_id','agent_id','allocation_type','base_type','rate','fixed_amount','calculated_amount','incentive_rule_id','incentive_rule_version','eligibility_snapshot_json','rule_snapshot_json','status','created_by_user_id'];
    protected $casts=['rate'=>'decimal:6','fixed_amount'=>'decimal:2','calculated_amount'=>'decimal:2','eligibility_snapshot_json'=>'array','rule_snapshot_json'=>'array'];
    public function booking(){return $this->belongsTo(BookingFinancial::class,'booking_financial_id');}
    public function agent(){return $this->belongsTo(Agent::class);}
}
