<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class SalaryHistory extends Model {
    protected $table='salary_history';
    protected $fillable=['agent_id','effective_from','effective_to','monthly_salary','reason','created_by_user_id'];
    protected $casts=['effective_from'=>'date','effective_to'=>'date','monthly_salary'=>'decimal:2'];
    public function agent(){return $this->belongsTo(Agent::class);}
}
