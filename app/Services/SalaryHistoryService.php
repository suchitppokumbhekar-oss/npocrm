<?php
namespace App\Services;
use App\Models\Agent;
use App\Models\SalaryHistory;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class SalaryHistoryService
{
    public function salaryForDate(Agent $agent, Carbon|string $date): float
    {
        $date = $date instanceof Carbon ? $date : Carbon::parse($date);
        $row = SalaryHistory::where('agent_id',$agent->id)
            ->whereDate('effective_from','<=',$date->toDateString())
            ->where(function($q) use($date){$q->whereNull('effective_to')->orWhereDate('effective_to','>=',$date->toDateString());})
            ->orderByDesc('effective_from')->first();
        if ($row) return (float)$row->monthly_salary;
        return (float)($agent->salary ?? 0);
    }

    public function averageMonthlySalary(Agent $agent, Carbon $from, Carbon $to): float
    {
        $months=[]; $cursor=$from->copy()->startOfMonth(); $end=$to->copy()->startOfMonth();
        while($cursor->lte($end)){ $months[]=$this->salaryForDate($agent,$cursor); $cursor->addMonth(); }
        return $months ? round(array_sum($months)/count($months),2) : 0.0;
    }

    public function snapshot(Agent $agent, Carbon $from, Carbon $to): array
    {
        $out=[]; $cursor=$from->copy()->startOfMonth(); $end=$to->copy()->startOfMonth();
        while($cursor->lte($end)){ $out[$cursor->format('Y-m')]=$this->salaryForDate($agent,$cursor); $cursor->addMonth(); }
        return $out;
    }

    public function recordChange(Agent $agent, float $salary, Carbon $effectiveFrom, ?string $reason, int $actor): SalaryHistory
    {
        if ($salary < 0) throw new \InvalidArgumentException('Salary cannot be negative.');
        return DB::transaction(function() use($agent,$salary,$effectiveFrom,$reason,$actor){
            $existing=SalaryHistory::where('agent_id',$agent->id)->whereDate('effective_from',$effectiveFrom->toDateString())->orderByDesc('id')->first();
            if($existing){
                $existing->update(['monthly_salary'=>$salary,'reason'=>$reason,'created_by_user_id'=>$actor]);
                return $existing->fresh();
            }
            $previous=SalaryHistory::where('agent_id',$agent->id)->whereDate('effective_from','<',$effectiveFrom->toDateString())->orderByDesc('effective_from')->first();
            if($previous && (!$previous->effective_to || Carbon::parse($previous->effective_to)->gte($effectiveFrom))) {
                $previous->update(['effective_to'=>$effectiveFrom->copy()->subDay()->toDateString()]);
            }
            $next=SalaryHistory::where('agent_id',$agent->id)->whereDate('effective_from','>',$effectiveFrom->toDateString())->orderBy('effective_from')->first();
            return SalaryHistory::create(['agent_id'=>$agent->id,'effective_from'=>$effectiveFrom->toDateString(),'effective_to'=>$next?->effective_from?->copy()->subDay()->toDateString(),'monthly_salary'=>$salary,'reason'=>$reason,'created_by_user_id'=>$actor]);
        });
    }

    public function history(Agent $agent)
    {
        return SalaryHistory::where('agent_id', $agent->id)->orderByDesc('effective_from')->get();
    }

    public function ensureBaseline(Agent $agent, int $actor): void
    {
        if (!$agent->salary || !$agent->joining_date) return;
        if (SalaryHistory::where('agent_id',$agent->id)->exists()) return;
        SalaryHistory::create(['agent_id'=>$agent->id,'effective_from'=>$agent->joining_date->toDateString(),'effective_to'=>$agent->exit_date?->toDateString(),'monthly_salary'=>$agent->salary,'reason'=>'Baseline imported from existing Agent master salary.','created_by_user_id'=>$actor]);
    }
}
