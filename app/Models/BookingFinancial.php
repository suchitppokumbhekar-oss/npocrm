<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class BookingFinancial extends Model {
    protected $table='booking_financials';
    protected $fillable=['lead_id','import_batch_id','source_type','source_reference','customer_name_snapshot','customer_phone_snapshot','project_id','project_name_snapshot','builder_name','unit_type','booking_unit','property_area_sqft','rate_per_sqft','booking_amount','booking_date','status','brokerage_gross','brokerage_rule_id','brokerage_rule_version','brokerage_snapshot_json','payable_deduction_total','net_brokerage','incentive_pool','financial_snapshot_json','legacy_incentive_deal_id','submitted_by_user_id','submitted_at','authorized_by_user_id','authorized_at','cancelled_by_user_id','cancelled_at','cancellation_reason','created_by_user_id'];
    protected $casts=['booking_date'=>'date','booking_amount'=>'decimal:2','property_area_sqft'=>'decimal:2','rate_per_sqft'=>'decimal:2','brokerage_gross'=>'decimal:2','payable_deduction_total'=>'decimal:2','net_brokerage'=>'decimal:2','incentive_pool'=>'decimal:2','brokerage_snapshot_json'=>'array','financial_snapshot_json'=>'array','submitted_at'=>'datetime','authorized_at'=>'datetime','cancelled_at'=>'datetime'];
    public function lead(){return $this->belongsTo(Lead::class);}
    public function project(){return $this->belongsTo(Project::class);}
    public function brokerageComponents(){return $this->hasMany(BookingBrokerageComponent::class,'booking_financial_id');}
    public function allocations(){return $this->hasMany(BookingEmployeeAllocation::class,'booking_financial_id');}
    public function payables(){return $this->hasMany(BookingPayable::class,'booking_financial_id');}
    public function audits(){return $this->hasMany(BookingFinancialAudit::class,'booking_financial_id');}
    public function brokerageReceipts(){return $this->hasMany(BookingBrokerageReceipt::class,'booking_financial_id');}
}
