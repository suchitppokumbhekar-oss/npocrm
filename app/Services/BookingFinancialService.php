<?php
namespace App\Services;

use App\Models\Agent;
use App\Models\BookingEmployeeAllocation;
use App\Models\BookingFinancial;
use App\Models\BookingFinancialAudit;
use App\Models\BookingPayable;
use App\Models\BookingPayableType;
use App\Models\FinancialRule;
use App\Models\IncentiveDeal;
use App\Models\Lead;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class BookingFinancialService
{
    public function __construct(private SettingsService $settings) {}

    public function context(BookingFinancial $booking): array
    {
        $lead=$booking->lead;
        return [
            'booking_value'=>(float)$booking->booking_amount,
            'booking_amount'=>(float)$booking->booking_amount,
            'booking_date'=>$booking->booking_date?->toDateString(),
            'month'=>(int)optional($booking->booking_date)->month,
            'year'=>(int)optional($booking->booking_date)->year,
            'project_id'=>(int)($booking->project_id ?: 0),
            'project_name'=>(string)($booking->project_name_snapshot ?: ''),
            'builder_name'=>(string)($booking->builder_name ?: ''),
            'unit_type'=>(string)($booking->unit_type ?: ''),
            'area_sqft'=>(float)($booking->property_area_sqft ?: 0),
            'source_type'=>$booking->source_type,
            'status'=>$booking->status,
            'booking_sequence'=>0,
            'lead_id'=>(int)($booking->lead_id ?: 0),
        ];
    }

    public function findRule(string $group, array $context, ?int $agentId=null, ?BookingFinancial $booking=null): ?FinancialRule
    {
        $date=$context['booking_date'] ?? now()->toDateString();
        $rules=FinancialRule::query()->where('rule_group',$group)->where('status','active')
            ->whereDate('effective_from','<=',$date)
            ->where(function($q) use($date){$q->whereNull('effective_to')->orWhereDate('effective_to','>=',$date);})
            ->orderByDesc('priority')->orderByDesc('effective_from')->orderByDesc('version')->get();
        foreach($rules as $rule){
            if(!$this->scopeMatches($rule,$context,$agentId)) continue;
            $testContext=$context; $ruleConditions=($rule->conditions_json['conditions'] ?? $rule->conditions_json) ?: [];
            foreach($ruleConditions as $condition){ if(($condition['field']??null)==='booking_sequence' && $booking){ $testContext['booking_sequence']=$this->bookingSequence($booking,$rule->conditions_json ?: []); break; } }
            if(!$this->conditionsMatch($ruleConditions,$testContext)) continue;
            return $rule;
        }
        return null;
    }

    private function scopeMatches(FinancialRule $rule,array $c,?int $agentId): bool
    {
        return match($rule->scope_type){
            'global' => true,
            'builder' => strcasecmp((string)$rule->scope_value,(string)($c['builder_name']??''))===0,
            'project' => (string)$rule->scope_value===(string)($c['project_id']??''),
            'agent' => $agentId && (string)$rule->scope_value===(string)$agentId,
            default => true,
        };
    }

    private function conditionsMatch(array $conditions,array $context): bool
    {
        foreach($conditions as $cond){
            $field=$cond['field']??null; $op=$cond['operator']??'equals';
            $value=$context[$field]??null;
            if($field==='booking_sequence') $value=(int)$value;
            if(!$this->condition($value,$op,$cond)) return false;
        }
        return true;
    }

    private function condition($value,string $op,array $c): bool
    {
        $target=$c['value']??null;
        if($op==='equals') return strcasecmp((string)$value,(string)$target)===0;
        if($op==='not_equals') return strcasecmp((string)$value,(string)$target)!==0;
        if($op==='contains') return stripos((string)$value,(string)$target)!==false;
        if($op==='gte') return (float)$value >= (float)$target;
        if($op==='lte') return (float)$value <= (float)$target;
        if($op==='gt') return (float)$value > (float)$target;
        if($op==='lt') return (float)$value < (float)$target;
        if($op==='between') return (float)$value >= (float)($c['min']??0) && (float)$value <= (float)($c['max']??PHP_FLOAT_MAX);
        if($op==='in') return in_array((string)$value,array_map('strval',(array)$target),true);
        return false;
    }

    public function bookingSequence(BookingFinancial $booking, array $ruleConditions=[]): int
    {
        $scope=$ruleConditions['counter_scope']??'global'; $period=$ruleConditions['counter_period']??'month';
        $q=BookingFinancial::query()->where('status','authorized')->whereDate('booking_date','<=',$booking->booking_date->toDateString())->where('id','!=',$booking->id);
        if($scope==='project' && $booking->project_id) $q->where('project_id',$booking->project_id);
        if($scope==='builder' && $booking->builder_name) $q->where('builder_name',$booking->builder_name);
        if($scope==='agent'){
            $agentIds=$booking->allocations()->pluck('agent_id')->all();
            if($agentIds) $q->whereHas('allocations',fn($x)=>$x->whereIn('agent_id',$agentIds));
        }
        if($period==='month') $q->whereBetween('booking_date',[$booking->booking_date->copy()->startOfMonth()->toDateString(),$booking->booking_date->copy()->endOfMonth()->toDateString()]);
        elseif($period==='quarter') $q->whereBetween('booking_date',[$booking->booking_date->copy()->startOfQuarter()->toDateString(),$booking->booking_date->copy()->endOfQuarter()->toDateString()]);
        elseif($period==='year') $q->whereYear('booking_date',$booking->booking_date->year);
        return (int)$q->count()+1;
    }

    public function calculateRule(?FinancialRule $rule,array $context): float
    {
        if(!$rule) return 0.0;
        $type=$rule->calculation_type;
        if($type==='fixed') return round((float)$rule->fixed_amount,2);
        $base=$this->baseValue($rule->base_type ?: 'booking_value',$context);
        if($type==='percentage') return round($base*((float)$rule->rate/100),2);
        if($type==='tiered'){
            $tiers=(array)($rule->calculation_json['tiers']??[]);
            foreach($tiers as $tier){
                $min=$tier['min']??0; $max=$tier['max']??null;
                if($base>=(float)$min && ($max===null || $base<(float)$max)){
                    return ($tier['type']??'percentage')==='fixed' ? round((float)($tier['amount']??0),2) : round($base*((float)($tier['rate']??0)/100),2);
                }
            }
        }
        return 0.0;
    }

    private function baseValue(string $base,array $c): float
    {
        return (float)match($base){
            'booking_value','booking_amount'=>($c['booking_value']??0),
            'brokerage_gross'=>($c['brokerage_gross']??0),
            'net_brokerage'=>($c['net_brokerage']??0),
            'incentive_pool'=>($c['incentive_pool']??0),
            'settled_nb'=>($c['settled_nb']??0),
            'collection_amount'=>($c['collection_amount']??0),
            default=>0,
        };
    }

    public function calculateBrokerage(BookingFinancial $booking): array
    {
        $c=$this->context($booking);
        $rule=$this->findRule('brokerage',$c,null,$booking);
        $components=[];
        if($rule){
            $ruleConditions=($rule->conditions_json['conditions'] ?? $rule->conditions_json) ?: [];
            $usesSequence=false; foreach($ruleConditions as $condition){ if(($condition['field']??null)==='booking_sequence'){ $usesSequence=true; break; } }
            if($usesSequence) $c['booking_sequence']=$this->bookingSequence($booking,$rule->conditions_json ?: []);
            if($this->conditionsMatch($ruleConditions,$c)) $amount=$this->calculateRule($rule,$c); else $amount=0;
            $components[]=['component_type'=>'rule','label'=>$rule->name,'base_amount'=>$this->baseValue($rule->base_type ?: 'booking_value',$c),'rate'=>$rule->rate,'amount'=>$amount,'rule_id'=>$rule->id,'rule_version'=>$rule->version,'snapshot_json'=>$rule->toArray()];
            return ['amount'=>$amount,'rule'=>$rule,'components'=>$components];
        }
        $lead=$booking->lead;
        $legacy=(float)($lead?->brokerage_amount ?? 0);
        if($legacy>0){
            $components[]=['component_type'=>'legacy','label'=>'Legacy CRM brokerage','base_amount'=>(float)$booking->booking_amount,'rate'=>$lead?->brokerage_percentage,'amount'=>$legacy,'rule_id'=>null,'rule_version'=>null,'snapshot_json'=>['source'=>'lead.brokerage_amount']];
            return ['amount'=>$legacy,'rule'=>null,'components'=>$components];
        }
        $pct=(float)$this->settings->get('default_brokerage_percentage',2);
        $amount=round((float)$booking->booking_amount*$pct/100,2);
        $components[]=['component_type'=>'default','label'=>'CRM default brokerage','base_amount'=>(float)$booking->booking_amount,'rate'=>$pct,'amount'=>$amount,'rule_id'=>null,'rule_version'=>null,'snapshot_json'=>['default_percentage'=>$pct]];
        return ['amount'=>$amount,'rule'=>null,'components'=>$components];
    }

    public function recalculate(BookingFinancial $booking): BookingFinancial
    {
        return DB::transaction(function() use($booking){
            $booking->refresh();
            $calc=$this->calculateBrokerage($booking);
            $gross=(float)$calc['amount'];
            $booking->brokerage_gross=$gross;
            $booking->brokerage_rule_id=$calc['rule']?->id;
            $booking->brokerage_rule_version=$calc['rule']?->version;
            $booking->brokerage_snapshot_json=['rule'=>$calc['rule']?->toArray(),'components'=>$calc['components'],'calculated_at'=>now()->toIso8601String()];
            $booking->save();
            $booking->brokerageComponents()->delete();
            foreach($calc['components'] as $i=>$row){$booking->brokerageComponents()->create($row+['sequence_no'=>$i+1]);}
            $deduct=(float)$booking->payables()->where('status','!=','cancelled')->where('affects_net_brokerage',1)->sum('amount');
            $booking->payable_deduction_total=round($deduct,2);
            $booking->net_brokerage=max(0,round($gross-$deduct,2));
            $booking->incentive_pool=$booking->net_brokerage;
            // If explicit employee allocation rows exist, keep the pool as the authoritative shared base;
            // allocation amounts are calculated per employee and do not duplicate NB across the deal ledger.
            $booking->financial_snapshot_json=['booking'=>$booking->only(['booking_date','booking_amount','project_id','builder_name','unit_type','booking_unit']),'brokerage_gross'=>$gross,'payable_deduction_total'=>$deduct,'net_brokerage'=>$booking->net_brokerage,'incentive_pool'=>$booking->incentive_pool,'calculated_at'=>now()->toIso8601String()];
            $booking->save();
            return $booking->fresh(['lead','allocations','payables','brokerageComponents']);
        });
    }


    public function brokerageReceiptSummary(BookingFinancial $booking): array
    {
        $booking->loadMissing('brokerageReceipts');
        $gross=(float)$booking->brokerage_gross;
        $received=(float)$booking->brokerageReceipts->sum('amount');
        $outstanding=max(0,round($gross-$received,2));
        $ratio=$gross>0?min(1,$received/$gross):0;
        $eligibleReceived=round(min((float)$booking->net_brokerage,(float)$booking->net_brokerage*$ratio),2);
        return ['gross'=>$gross,'received'=>round($received,2),'outstanding'=>$outstanding,'eligible_received_nb'=>$eligibleReceived,'receipt_count'=>$booking->brokerageReceipts->count()];
    }

    public function syncBrokerageReceiptsToIncentive(BookingFinancial $booking,int $actorUserId): array
    {
        return DB::transaction(function() use($booking,$actorUserId){
            $booking->loadMissing('brokerageReceipts','allocations','lead');
            $receipts=$booking->brokerageReceipts->sortBy('id')->values();
            if($receipts->isEmpty()) return ['synced'=>0,'eligible_received_nb'=>0.0,'deal_id'=>null];
            $agentId=$booking->lead?->agent_id ?: $booking->allocations->first()?->agent_id;
            if(!$agentId) return ['synced'=>0,'eligible_received_nb'=>0.0,'deal_id'=>null,'pending_reason'=>'Add at least one employee allocation before receipts can create incentive collections.'];
            $deal=$booking->legacy_incentive_deal_id ? IncentiveDeal::find($booking->legacy_incentive_deal_id) : null;
            $deal ??= IncentiveDeal::where('lead_id',$booking->lead_id)->first();
            $deal ??= new IncentiveDeal();
            $gross=(float)$booking->brokerage_gross; $net=(float)$booking->net_brokerage;
            $deal->fill(['lead_id'=>$booking->lead_id,'agent_id'=>$agentId,'booking_date'=>$booking->booking_date,'booked_nb'=>$net,'status'=>$gross>0 && (float)$receipts->sum('amount')+0.01 >= $gross?'settled':'partial','notes'=>'Synchronized from Booking Financial Engine #'.$booking->id,'policy_snapshot_json'=>json_encode(['booking_financial_id'=>$booking->id,'brokerage_gross'=>$gross,'net_brokerage'=>$net]),'created_by_user_id'=>$deal->created_by_user_id ?: $actorUserId]);
            $deal->save();
            $previousEligible=0.0; $synced=0;
            foreach($receipts as $receipt){
                $grossBefore=(float)$receipts->where('id','<=',$receipt->id)->sum('amount');
                $eligibleCumulative=$gross>0?min($net,round($net*min(1,$grossBefore/$gross),2)):0;
                $increment=max(0,round($eligibleCumulative-$previousEligible,2));
                $previousEligible=$eligibleCumulative;
                if($receipt->incentive_collection_id) continue;
                if($increment>0){
                    $collection=\App\Models\IncentiveCollection::create(['deal_id'=>$deal->id,'collection_date'=>$receipt->receipt_date,'amount'=>$increment,'notes'=>'Brokerage receipt #'.$receipt->id.' from booking #'.$booking->id.'; actual brokerage received ₹'.number_format((float)$receipt->amount,2),'created_by_user_id'=>$actorUserId]);
                    $receipt->incentive_collection_id=$collection->id; $receipt->save(); $synced++;
                } else { $receipt->incentive_collection_id=null; $receipt->save(); }
            }
            $eligibleTotal=round(min($net,$net*min(1,(float)$receipts->sum('amount')/max($gross,0.00001))),2);
            $deal->settled_nb=$eligibleTotal; $deal->status=$gross>0 && (float)$receipts->sum('amount')+0.01 >= $gross?'settled':'partial'; $deal->save();
            $booking->legacy_incentive_deal_id=$deal->id; $booking->save();
            $this->recalculate($booking);
            return ['synced'=>$synced,'eligible_received_nb'=>$eligibleTotal,'deal_id'=>$deal->id,'brokerage_received'=>(float)$receipts->sum('amount'),'brokerage_outstanding'=>max(0,round($gross-(float)$receipts->sum('amount'),2))];
        });
    }

    public function syncLeadBooking(Lead $lead, int $actorUserId, bool $submitted=false): BookingFinancial
    {
        $booking=BookingFinancial::firstOrNew(['lead_id'=>$lead->id]);
        $booking->fill([
            'source_type'=>'live_lead','source_reference'=>'lead:'.$lead->id,
            'customer_name_snapshot'=>$lead->customer_name,'customer_phone_snapshot'=>$lead->phone,
            'project_id'=>$lead->project_id,'project_name_snapshot'=>$lead->project?->name,
            'builder_name'=>$lead->project?->builder_name ?? null,
            'booking_unit'=>$lead->booking_unit,'property_area_sqft'=>$lead->property_area_sqft,'rate_per_sqft'=>$lead->rate_per_sqft,
            'booking_amount'=>(float)$lead->booking_amount,'booking_date'=>$lead->booking_date ?: now()->toDateString(),
            'status'=>$submitted?'submitted':($booking->status ?: 'draft'),'created_by_user_id'=>$booking->created_by_user_id ?: $actorUserId,
        ]);
        if($submitted){$booking->submitted_by_user_id=$actorUserId;$booking->submitted_at=now();}
        $booking->save();
        return $this->recalculate($booking);
    }

    public function authorizeBooking(BookingFinancial $booking,int $actorUserId,?string $note=null): BookingFinancial
    {
        return DB::transaction(function() use($booking,$actorUserId,$note){
            $booking=$this->recalculate($booking->fresh());
            $booking->status='authorized'; $booking->authorized_by_user_id=$actorUserId; $booking->authorized_at=now();
            $booking->save();
            $this->audit($booking,'authorized',$actorUserId,$note,null,$booking->toArray());
            $this->syncLegacyIncentiveDeal($booking,$actorUserId);
            return $booking->fresh(['allocations','payables']);
        });
    }

    public function syncLegacyIncentiveDeal(BookingFinancial $booking,int $actorUserId): ?IncentiveDeal
    {
        if(!$booking->authorized_by_user_id) return null;
        $lead=$booking->lead()->first();
        $agentId=$lead?->agent_id ?: $booking->allocations()->value('agent_id');
        if(!$agentId) return null;
        $deal= $booking->legacy_incentive_deal_id ? IncentiveDeal::find($booking->legacy_incentive_deal_id) : null;
        $deal ??= IncentiveDeal::where('lead_id',$booking->lead_id)->first();
        $deal ??= new IncentiveDeal();
        $deal->fill(['lead_id'=>$booking->lead_id,'agent_id'=>$agentId,'booking_date'=>$booking->booking_date,'booked_nb'=>$booking->net_brokerage,'settled_nb'=>null,'status'=>'booked','notes'=>'Created from Booking Financial Engine #'.$booking->id,'policy_snapshot_json'=>json_encode(['financial_rule_id'=>$booking->brokerage_rule_id,'financial_rule_version'=>$booking->brokerage_rule_version,'financial_snapshot'=>$booking->financial_snapshot_json]),'created_by_user_id'=>$actorUserId]);
        $deal->save();
        $booking->legacy_incentive_deal_id=$deal->id; $booking->save();
        return $deal;
    }

    public function addAllocation(BookingFinancial $booking,array $data,int $actor): BookingEmployeeAllocation
    {
        $base=$data['base_type']??'net_brokerage'; $baseAmount=(float)match($base){'brokerage_gross'=>$booking->brokerage_gross,'booking_value'=>$booking->booking_amount,default=>$booking->net_brokerage};
        $context=['booking_value'=>(float)$booking->booking_amount,'booking_date'=>$booking->booking_date?->toDateString(),'project_id'=>$booking->project_id,'builder_name'=>$booking->builder_name,'net_brokerage'=>$booking->net_brokerage,'incentive_pool'=>$booking->incentive_pool];
        $rule=$this->findRule('incentive',$context,(int)$data['agent_id']);
        if($rule){
            $ruleContext=$context+['brokerage_gross'=>$booking->brokerage_gross];
            $amount=$this->calculateRule($rule,$ruleContext);
            $allocationType=$rule->calculation_type==='fixed'?'fixed':'percentage';
            $base=$rule->base_type ?: $base; $rate=$rule->rate; $fixed=$rule->fixed_amount;
        } else {
            $allocationType=$data['allocation_type']??'percentage'; $rate=$data['rate']??null; $fixed=$data['fixed_amount']??null;
            $amount=$allocationType==='fixed' ? (float)($fixed??0) : round($baseAmount*((float)($rate??0)/100),2);
        }
        return $booking->allocations()->create(['agent_id'=>$data['agent_id'],'allocation_type'=>$allocationType,'base_type'=>$base,'rate'=>$rate,'fixed_amount'=>$fixed,'calculated_amount'=>$amount,'incentive_rule_id'=>$rule?->id,'incentive_rule_version'=>$rule?->version,'eligibility_snapshot_json'=>['payroll'=>true,'captured_at'=>now()->toIso8601String()],'rule_snapshot_json'=>$rule?->toArray(),'created_by_user_id'=>$actor]);
    }

    public function audit(BookingFinancial $booking,string $event,int $actor,?string $reason,$before=null,$after=null): void
    {
        BookingFinancialAudit::create(['booking_financial_id'=>$booking->id,'event_type'=>$event,'actor_user_id'=>$actor,'reason'=>$reason,'before_json'=>$before,'after_json'=>$after,'created_at'=>now()]);
    }

    public function preview(array $input): array
    {
        $booking=new BookingFinancial($input); $booking->booking_date=Carbon::parse($input['booking_date']??now());
        $booking->id=0; $calc=$this->calculateBrokerage($booking);
        return ['brokerage'=>$calc['amount'],'rule'=>$calc['rule'],'components'=>$calc['components']];
    }
}
