<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class FinancialRule extends Model {
    protected $table='financial_rules';
    protected $fillable=['rule_group','name','scope_type','scope_value','priority','effective_from','effective_to','calculation_type','base_type','fixed_amount','rate','conditions_json','calculation_json','status','version','description','created_by_user_id','approved_by_user_id','approved_at'];
    protected $casts=['effective_from'=>'date','effective_to'=>'date','fixed_amount'=>'decimal:2','rate'=>'decimal:6','conditions_json'=>'array','calculation_json'=>'array','approved_at'=>'datetime'];
    public function creator(){return $this->belongsTo(User::class,'created_by_user_id');}
}
