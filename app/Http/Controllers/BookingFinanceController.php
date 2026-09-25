<?php
namespace App\Http\Controllers;

use App\Models\Agent;
use App\Models\Customer;
use App\Models\Project;
use App\Models\BookingEmployeeAllocation;
use App\Models\BookingBrokerageReceipt;
use App\Models\BookingFinancial;
use App\Models\BookingImportBatch;
use App\Models\BookingPayable;
use App\Models\BookingPayablePayment;
use App\Models\BookingPayableType;
use App\Models\FinancialRule;
use App\Models\Lead;
use App\Models\ManagedFile;
use App\Models\IncentiveDeal;
use App\Models\IncentivePayout;
use App\Services\AccessService;
use App\Services\AuditLogService;
use App\Services\BookingFinancialService;
use App\Services\DelegatedAccessService;
use App\Services\ManagedDocumentService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class BookingFinanceController extends Controller
{
    public function __construct(
        private AccessService $access,
        private DelegatedAccessService $delegated,
        private BookingFinancialService $engine,
        private AuditLogService $audit,
        private ManagedDocumentService $documents,
    ) {}

    private function canManage(): bool
    {
        return $this->access->can('incentives.manage') || $this->access->isUnrestrictedAdmin();
    }
    private function requireManage(): void { abort_unless($this->canManage(),403); }

    private function agents()
    {
        $q=Agent::with('user')->whereHas('user',fn($x)=>$x->where('is_on_payroll',true));
        $uid=(int)session('user_id'); $role=session('user_role');
        if($this->access->isUnrestrictedAdmin($uid)) return $q->orderBy('id')->get();
        if(in_array($role,['admin','team_manager'],true) && $this->delegated->hasProfile($uid)) return $q->whereIn('id',$this->delegated->visibleAgentIds($uid) ?: [-1])->orderBy('id')->get();
        return $q->whereRaw('1=0')->get();
    }

    public function index(Request $request)
    {
        $this->requireManage();
        $tab=$request->input('tab','bookings');
        $bookings=BookingFinancial::with(['lead.project','allocations.agent.user','payables','brokerageReceipts'])->latest('booking_date')->paginate(30)->withQueryString();
        $rules=FinancialRule::latest('effective_from')->latest('priority')->paginate(30,['*'],'rules_page')->withQueryString();
        $payableTypes=BookingPayableType::orderBy('sort_order')->orderBy('name')->get();
        $agents=$this->agents();
        $salaryRows=DB::table('salary_history')->join('agents','agents.id','=','salary_history.agent_id')->join('users','users.id','=','agents.user_id')->select('salary_history.*','users.name as employee_name')->orderByDesc('salary_history.effective_from')->paginate(30,['*'],'salary_page')->withQueryString();
        $editBooking=$request->filled('edit_booking')?BookingFinancial::with(['lead.project','allocations.agent.user','payables','brokerageReceipts'])->find((int)$request->input('edit_booking')):null;
        $receiptEvidence = collect();
        if ($editBooking && $editBooking->brokerageReceipts->isNotEmpty()) {
            $receiptIds = $editBooking->brokerageReceipts->pluck('id')->map(fn ($id) => (int) $id)->all();
            $receiptEvidence = ManagedFile::query()
                ->with(['uploader', 'links'])
                ->whereNull('removed_at')
                ->whereHas('links', fn ($q) => $q
                    ->where('entity_type', 'booking_brokerage_receipt')
                    ->whereIn('entity_id', $receiptIds))
                ->get()
                ->groupBy(function ($file) {
                    $link = $file->links->first(fn ($item) => $item->entity_type === 'booking_brokerage_receipt');
                    return (int) $link->entity_id;
                });
        }

        $editRule=$request->filled('edit_rule')?FinancialRule::find((int)$request->input('edit_rule')):null;
        $receiptSummary=$editBooking?$this->engine->brokerageReceiptSummary($editBooking):null;
        $receiptDeal=$editBooking && $editBooking->legacy_incentive_deal_id?IncentiveDeal::with('payouts')->find($editBooking->legacy_incentive_deal_id):null;
        $receiptRelease=$receiptDeal?(float)$receiptDeal->payouts->where('type',IncentivePayout::PROVISIONAL_PAID)->sum('amount'):0.0;
        $receiptHeld=$receiptDeal?(float)$receiptDeal->payouts->where('type',IncentivePayout::HELD_ACCRUAL)->sum('amount')-(float)$receiptDeal->payouts->where('type',IncentivePayout::HELD_RELEASE)->sum('amount')-(float)$receiptDeal->payouts->whereIn('type',[IncentivePayout::RECOVERY,IncentivePayout::TRUE_UP_RECOVERY])->sum('amount'):0.0;
        $importBatch=$request->filled('import_batch')?BookingImportBatch::find((int)$request->input('import_batch')):null;
        return view('admin.booking-finance',compact('tab','bookings','rules','payableTypes','salaryRows','agents','editBooking','editRule','importBatch','receiptEvidence','receiptSummary','receiptDeal','receiptRelease','receiptHeld'));
    }

    public function saveRule(Request $request, ?int $id=null)
    {
        $this->requireManage();
        $data=$request->validate([
            'rule_group'=>'required|in:brokerage,incentive,payable',
            'name'=>'required|string|max:160', 'scope_type'=>'required|in:global,builder,project,agent', 'scope_value'=>'nullable|string|max:160',
            'priority'=>'required|integer|min:1|max:9999','effective_from'=>'required|date','effective_to'=>'nullable|date|after_or_equal:effective_from',
            'calculation_type'=>'required|in:fixed,percentage,tiered','base_type'=>'nullable|string|max:60','fixed_amount'=>'nullable|numeric|min:0','rate'=>'nullable|numeric|min:0|max:1000',
            'builder_name'=>'nullable|string|max:200','project_id'=>'nullable|integer','booking_value_min'=>'nullable|numeric|min:0','booking_value_max'=>'nullable|numeric|min:0','sequence_min'=>'nullable|integer|min:1','sequence_max'=>'nullable|integer|min:1','month'=>'nullable|integer|min:1|max:12',
            'counter_scope'=>'nullable|in:global,builder,project,agent','counter_period'=>'nullable|in:month,quarter,year,lifetime','description'=>'nullable|string|max:2000',
        ]);
        $conditions=[];
        if($data['builder_name']??null)$conditions[]=['field'=>'builder_name','operator'=>'equals','value'=>$data['builder_name']];
        if($data['project_id']??null)$conditions[]=['field'=>'project_id','operator'=>'equals','value'=>(int)$data['project_id']];
        if(isset($data['booking_value_min'])||isset($data['booking_value_max']))$conditions[]=['field'=>'booking_value','operator'=>'between','min'=>(float)($data['booking_value_min']??0),'max'=>(float)($data['booking_value_max']??PHP_FLOAT_MAX)];
        if(isset($data['sequence_min'])||isset($data['sequence_max']))$conditions[]=['field'=>'booking_sequence','operator'=>'between','min'=>(int)($data['sequence_min']??1),'max'=>(int)($data['sequence_max']??PHP_INT_MAX)];
        if(isset($data['month']))$conditions[]=['field'=>'month','operator'=>'equals','value'=>(int)$data['month']];
        $conditionsMeta=['counter_scope'=>$data['counter_scope']??'global','counter_period'=>$data['counter_period']??'month'];
        $rule=$id?FinancialRule::findOrFail($id):new FinancialRule();
        if($id && $rule->status==='active'){$rule->status='retired';$rule->save();$rule=new FinancialRule();$id=null;}
        $maxVersion=(int)FinancialRule::where('name',$data['name'])->max('version');
        $rule->fill(['rule_group'=>$data['rule_group'],'name'=>$data['name'],'scope_type'=>$data['scope_type'],'scope_value'=>$data['scope_value']??null,'priority'=>$data['priority'],'effective_from'=>$data['effective_from'],'effective_to'=>$data['effective_to']??null,'calculation_type'=>$data['calculation_type'],'base_type'=>$data['base_type']??'booking_value','fixed_amount'=>$data['fixed_amount']??null,'rate'=>$data['rate']??null,'conditions_json'=>['conditions'=>$conditions] + $conditionsMeta,'calculation_json'=>['tiers'=>[]],'status'=>'active','version'=>$maxVersion+1,'description'=>$data['description']??null,'created_by_user_id'=>(int)session('user_id'),'approved_by_user_id'=>(int)session('user_id'),'approved_at'=>now()]);
        $rule->save();
        $this->audit->record('booking_financial','Financial rule created/versioned',$request,['rule_id'=>$rule->id,'rule'=>$rule->toArray()], 'FinancialRule',$rule->id);
        return redirect('/admin/booking-finance?tab=rules')->with('success','Financial rule saved as an immutable version.');
    }

    public function saveBooking(Request $request, ?int $id=null)
    {
        $this->requireManage();
        $data=$request->validate(['lead_id'=>'nullable|integer|exists:leads,id','source_type'=>'required|in:historical,manual,imported','customer_name'=>'required|string|max:200','customer_phone'=>'nullable|string|max:50','project_id'=>'nullable|integer','project_name'=>'nullable|string|max:200','builder_name'=>'nullable|string|max:200','unit_type'=>'nullable|string|max:100','booking_unit'=>'nullable|string|max:100','property_area_sqft'=>'nullable|numeric|min:0','rate_per_sqft'=>'nullable|numeric|min:0','booking_amount'=>'required|numeric|min:0','booking_date'=>'required|date','source_reference'=>'nullable|string|max:255']);
        $booking=$id?BookingFinancial::findOrFail($id):new BookingFinancial();
        $before=$booking->exists?$booking->toArray():null;
        if(!$booking->exists && empty($data['lead_id']) && in_array($data['source_type'],['historical','manual'],true)) {
            $data['lead_id']=$this->ensureHistoricalLead($data);
        }
        $booking->fill(['lead_id'=>$data['lead_id']??null,'source_type'=>$data['source_type'],'source_reference'=>$data['source_reference']??null,'customer_name_snapshot'=>$data['customer_name'],'customer_phone_snapshot'=>$data['customer_phone']??null,'project_id'=>$data['project_id']??null,'project_name_snapshot'=>$data['project_name']??null,'builder_name'=>$data['builder_name']??null,'unit_type'=>$data['unit_type']??null,'booking_unit'=>$data['booking_unit']??null,'property_area_sqft'=>$data['property_area_sqft']??null,'rate_per_sqft'=>$data['rate_per_sqft']??null,'booking_amount'=>$data['booking_amount'],'booking_date'=>$data['booking_date'],'created_by_user_id'=>$booking->created_by_user_id ?: (int)session('user_id'),'status'=>$booking->status==='authorized'?'authorized':'draft']);
        $booking->save(); $booking=$this->engine->recalculate($booking);
        $this->engine->audit($booking,$id?'updated':'created',(int)session('user_id'),null,$before,$booking->toArray());
        return redirect('/admin/booking-finance?tab=bookings&edit_booking='.$booking->id)->with('success','Booking financial record saved. It is still a draft until authorized.');
    }

    public function submit(Request $request,int $id)
    {
        $this->requireManage(); $booking=BookingFinancial::findOrFail($id); $before=$booking->toArray();
        $booking->status='submitted';$booking->submitted_by_user_id=(int)session('user_id');$booking->submitted_at=now();$booking->save();$this->engine->recalculate($booking);
        $this->engine->audit($booking,'submitted',(int)session('user_id'),trim((string)$request->input('note')),$before,$booking->fresh()->toArray());
        return back()->with('success','Booking submitted for financial authorization.');
    }

    public function authorizeBooking(Request $request,int $id)
    {
        $this->requireManage(); $booking=BookingFinancial::findOrFail($id);
        $this->engine->authorizeBooking($booking,(int)session('user_id'),trim((string)$request->input('note')));
        return back()->with('success','Booking authorized; brokerage/NB snapshot and incentive deal synchronized.');
    }

    public function addAllocation(Request $request,int $id)
    {
        $this->requireManage(); $booking=BookingFinancial::findOrFail($id); abort_if($booking->status==='cancelled',422);
        $data=$request->validate(['agent_id'=>'required|integer|exists:agents,id','allocation_type'=>'required|in:percentage,fixed','base_type'=>'required|in:net_brokerage,brokerage_gross,booking_value','rate'=>'nullable|numeric|min:0|max:1000','fixed_amount'=>'nullable|numeric|min:0']);
        $allowed=$this->agents()->pluck('id')->map(fn($x)=>(int)$x)->all(); abort_unless(in_array((int)$data['agent_id'],$allowed,true),403);
        $allocation=$this->engine->addAllocation($booking,$data,(int)session('user_id'));
        $this->engine->syncBrokerageReceiptsToIncentive($booking->fresh(['brokerageReceipts','allocations']),(int)session('user_id'));
        $this->audit->record('booking_financial','Employee incentive allocation added',$request,$allocation->toArray(),'BookingEmployeeAllocation',$allocation->id);
        return back()->with('success','Employee incentive allocation added.');
    }

    public function addBrokerageReceipt(Request $request,int $id)
    {
        $this->requireManage();
        $booking=BookingFinancial::with(['brokerageReceipts','allocations'])->findOrFail($id);
        abort_unless($booking->status==='authorized',422,'Brokerage can be received only after the booking financial record is authorized.');
        $data=$request->validate([
            'receipt_date'=>'required|date|before_or_equal:today',
            'amount'=>'required|numeric|min:0.01',
            'payer_name'=>'nullable|string|max:200',
            'payment_reference'=>'nullable|string|max:200',
            'receipt_evidence'=>'nullable|file|max:25600',
            'payment_mode'=>'nullable|string|max:60',
            'notes'=>'nullable|string|max:2000',
        ]);
        $received=(float)$booking->brokerageReceipts->sum('amount');
        $remaining=max(0,round((float)$booking->brokerage_gross-$received,2));
        if((float)$data['amount']>$remaining+0.01){
            return back()->withErrors(['amount'=>'Receipt exceeds brokerage receivable. Remaining brokerage receivable is ₹'.number_format($remaining,2).'.'])->withInput();
        }
        $receiptData=$data; unset($receiptData['receipt_evidence']);
        $receipt=DB::transaction(function() use($booking,$receiptData){
            $receipt=BookingBrokerageReceipt::create($receiptData+['booking_financial_id'=>$booking->id,'created_by_user_id'=>(int)session('user_id')]);
            $this->engine->syncBrokerageReceiptsToIncentive($booking->fresh(['brokerageReceipts','allocations']),(int)session('user_id'));
            $this->engine->audit($booking->fresh(),'brokerage_received',(int)session('user_id'),null,null,$receipt->toArray());
            return $receipt;
        });
        if ($request->hasFile('receipt_evidence')) {
            $this->documents->store(
                $request->file('receipt_evidence'),
                [
                    'document_category' => 'brokerage_receipt_evidence',
                    'context_type' => 'brokerage',
                    'context_id' => $receipt->id,
                    'workflow_stage' => 'brokerage_received',
                    'relationship' => 'evidence',
                    'source_label' => 'brokerage_receipt',
                    'visibility' => 'internal',
                ],
                'booking_brokerage_receipt',
                (int) $receipt->id,
                (int) session('user_id')
            );
        }

        return back()->with('success','Brokerage receipt recorded. Incentive release entitlement was recalculated from actual brokerage received.');
    }

    public function addPayable(Request $request,int $id)
    {
        $this->requireManage(); $booking=BookingFinancial::findOrFail($id);
        $data=$request->validate(['payable_type_id'=>'nullable|integer|exists:booking_payable_types,id','payable_type_name'=>'nullable|string|max:120','category'=>'nullable|string|max:80','payee_name'=>'required|string|max:200','payee_reference'=>'nullable|string|max:200','amount'=>'required|numeric|min:0','due_date'=>'nullable|date','affects_net_brokerage'=>'nullable|boolean','notes'=>'nullable|string|max:4000']);
        $type=$data['payable_type_id']?BookingPayableType::find($data['payable_type_id']):null;
        $payable=BookingPayable::create(['booking_financial_id'=>$booking->id,'payable_type_id'=>$type?->id,'payable_type_name_snapshot'=>$type?->name ?: ($data['payable_type_name']??'Other Payable'),'category'=>$type?->category ?: ($data['category']??'other'),'payee_name'=>$data['payee_name'],'payee_reference'=>$data['payee_reference']??null,'amount'=>$data['amount'],'calculated_amount'=>$data['amount'],'due_date'=>$data['due_date']??null,'status'=>$type?->requires_approval?'pending':'approved','affects_net_brokerage'=>(bool)($data['affects_net_brokerage']??$type?->affects_net_brokerage),'notes'=>$data['notes']??null,'created_by_user_id'=>(int)session('user_id'),'approved_by_user_id'=>$type?->requires_approval?null:(int)session('user_id'),'approved_at'=>$type?->requires_approval?null:now()]);
        $this->engine->recalculate($booking); return back()->with('success','Payable added and Net Brokerage recalculated.');
    }

    public function addPayment(Request $request,int $id)
    {
        $this->requireManage(); $payable=BookingPayable::findOrFail($id);
        $data=$request->validate(['payment_date'=>'required|date','amount'=>'required|numeric|min:0.01','payment_reference'=>'nullable|string|max:200','payment_mode'=>'nullable|string|max:60','notes'=>'nullable|string|max:2000']);
        $paid=(float)$payable->payments()->sum('amount'); if($paid+(float)$data['amount']>(float)$payable->amount+0.01)return back()->withErrors(['amount'=>'Payment exceeds payable balance.'])->withInput();
        BookingPayablePayment::create($data+['booking_payable_id'=>$payable->id,'created_by_user_id'=>(int)session('user_id')]);
        if($paid+(float)$data['amount'] >= (float)$payable->amount-0.01)$payable->status='paid'; else $payable->status='partially_paid'; $payable->save();
        return back()->with('success','Payable payment recorded.');
    }

    public function savePayableType(Request $request,?int $id=null)
    {
        $this->requireManage(); $data=$request->validate(['name'=>'required|string|max:120','code'=>['required','string','max:80',Rule::unique('booking_payable_types','code')->ignore($id)],'category'=>'required|string|max:80','calculation_type'=>'required|in:manual,fixed,percentage','base_type'=>'nullable|string|max:60','default_value'=>'nullable|numeric|min:0','affects_net_brokerage'=>'nullable|boolean','requires_approval'=>'nullable|boolean','description'=>'nullable|string|max:1000']);
        $type=$id?BookingPayableType::findOrFail($id):new BookingPayableType(); $type->fill($data+['active'=>true,'created_by_user_id'=>$type->created_by_user_id ?: (int)session('user_id')]);$type->save();
        return redirect('/admin/booking-finance?tab=payables')->with('success','Payable type saved.');
    }

    public function saveSalary(Request $request,int $agentId)
    {
        $this->requireManage();
        if($agentId===0) $agentId=(int)$request->input('agent_id');
        abort_unless($this->agents()->pluck('id')->contains($agentId),403);
        $data=$request->validate(['effective_from'=>'required|date','effective_to'=>'nullable|date|after_or_equal:effective_from','monthly_salary'=>'required|numeric|min:0','reason'=>'nullable|string|max:500']);
        $row=DB::table('salary_history')->where('agent_id',$agentId)->whereDate('effective_from',$data['effective_from'])->first();
        if($row) DB::table('salary_history')->where('id',$row->id)->update(['effective_to'=>$data['effective_to']??null,'monthly_salary'=>$data['monthly_salary'],'reason'=>$data['reason']??null,'created_by_user_id'=>(int)session('user_id'),'updated_at'=>now()]);
        else DB::table('salary_history')->insert(['agent_id'=>$agentId,'effective_from'=>$data['effective_from'],'effective_to'=>$data['effective_to']??null,'monthly_salary'=>$data['monthly_salary'],'reason'=>$data['reason']??null,'created_by_user_id'=>(int)session('user_id'),'created_at'=>now(),'updated_at'=>now()]);
        return back()->with('success','Salary history version saved. Historical quarters now use the effective salary.');
    }

    public function importPreview(Request $request)
    {
        $this->requireManage(); $file=$request->file('file'); abort_unless($file && $file->isValid(),422,'Please upload a CSV or XLSX file.');
        $ext=strtolower($file->getClientOriginalExtension()); abort_unless(in_array($ext,['csv','xlsx'],true),422,'Only CSV and XLSX are supported.');
        $rows=$ext==='csv'?$this->readCsv($file->getRealPath()):$this->readXlsx($file->getRealPath());
        abort_if(count($rows)<2,422,'The file contains no booking rows.');
        $headers=array_map(fn($x)=>trim((string)$x),array_keys($rows[0])); $mapping=$this->inferMapping($headers); $dataRows=array_slice($rows,0,500);
        $valid=0;$duplicates=0;$invalid=0;$preview=[];$seen=[];
        foreach($dataRows as $row){$mapped=$this->mapRow($row,$mapping);$isValid=!empty($mapped['customer_name'])&&!empty($mapped['booking_date'])&&is_numeric($mapped['booking_amount']??null);$key=!empty($mapped['source_reference'])?'ref:'.strtolower(trim((string)$mapped['source_reference'])):'phone:'.Customer::normalizePhone((string)($mapped['customer_phone']??'')).'|date:'.$mapped['booking_date'].'|amount:'.$mapped['booking_amount'];$dup=$isValid&&($this->isDuplicate($mapped)||isset($seen[$key]));if($isValid)$seen[$key]=true;if(!$isValid)$invalid++;elseif($dup)$duplicates++;else$valid++;$preview[]=['mapped'=>$mapped,'valid'=>$isValid,'duplicate'=>$dup];}
        $batch=BookingImportBatch::create(['source_type'=>$ext,'file_name'=>$file->getClientOriginalName(),'status'=>'preview','mapping_json'=>['headers'=>$headers,'mapping'=>$mapping,'rows'=>$preview],'total_rows'=>count($rows),'valid_rows'=>$valid,'invalid_rows'=>$invalid,'duplicate_rows'=>$duplicates,'created_by_user_id'=>(int)session('user_id')]);
        return redirect('/admin/booking-finance?tab=import&import_batch='.$batch->id)->with('success','Import preview created. Review mapping/results, then commit valid non-duplicate rows as drafts.');
    }

    public function importCommit(Request $request,int $batchId)
    {
        $this->requireManage(); $batch=BookingImportBatch::findOrFail($batchId); $payload=$batch->mapping_json?:[]; $rows=$payload['rows']??[]; $created=0;
        DB::transaction(function() use($rows,$batch,&$created){foreach($rows as $item){if(empty($item['valid'])||!empty($item['duplicate']))continue;$d=$item['mapped'];$d['lead_id']=$this->ensureHistoricalLead($d);$b=BookingFinancial::create(['lead_id'=>$d['lead_id'],'source_type'=>'imported','source_reference'=>$d['source_reference']??null,'customer_name_snapshot'=>$d['customer_name'],'customer_phone_snapshot'=>$d['customer_phone']??null,'project_id'=>$d['project_id']??null,'project_name_snapshot'=>$d['project_name']??null,'builder_name'=>$d['builder_name']??null,'unit_type'=>$d['unit_type']??null,'booking_unit'=>$d['booking_unit']??null,'property_area_sqft'=>$d['property_area_sqft']??null,'rate_per_sqft'=>$d['rate_per_sqft']??null,'booking_amount'=>$d['booking_amount'],'booking_date'=>$d['booking_date'],'import_batch_id'=>$batch->id,'status'=>'draft','created_by_user_id'=>(int)session('user_id')]);$this->engine->recalculate($b);$created++;}$batch->update(['status'=>'committed']);});
        $this->audit->record('booking_financial','Booking import committed',$request,['batch_id'=>$batch->id,'created'=>$created],'BookingImportBatch',$batch->id);
        return redirect('/admin/booking-finance?tab=bookings')->with('success',"Import committed: {$created} booking drafts created. No row was silently authorized.");
    }

    public function simulate(Request $request)
    {
        $this->requireManage(); $data=$request->validate(['booking_date'=>'required|date','booking_amount'=>'required|numeric|min:0','project_id'=>'nullable|integer','project_name'=>'nullable|string|max:200','builder_name'=>'nullable|string|max:200','property_area_sqft'=>'nullable|numeric|min:0','booking_unit'=>'nullable|string|max:100']);
        return back()->with('simulation',$this->engine->preview($data));
    }

    private function ensureHistoricalLead(array $data): int
    {
        $customer=null;
        if(!empty($data['customer_phone'])) {
            $normalized=Customer::normalizePhone((string)$data['customer_phone']);
            $customer=Customer::query()->where('phone',$normalized)->first();
            if(!$customer) $customer=Customer::create(['name'=>$data['customer_name'],'phone'=>$normalized,'first_seen_at'=>now(),'last_seen_at'=>now(),'total_enquiries'=>1]);
        }
        if(!$customer) $customer=Customer::create(['name'=>$data['customer_name'],'phone'=>$data['customer_phone']??null,'first_seen_at'=>now(),'last_seen_at'=>now(),'total_enquiries'=>1]);
        $projectId=(int)($data['project_id']??0);
        if(!$projectId && !empty($data['project_name'])) {
            $project=Project::findByNameCI((string)$data['project_name']);
            if(!$project) $project=Project::create(['name'=>$data['project_name'],'status'=>'active']);
            $projectId=(int)$project->id;
        }
        $lead=Lead::query()->where('customer_id',$customer->id)->when($projectId,fn($q)=>$q->where('project_id',$projectId))->where('status','booking')->latest('id')->first();
        if($lead) return (int)$lead->id;
        $lead=Lead::create(['customer_name'=>$data['customer_name'],'phone'=>$customer->phone,'source'=>'historical','status'=>'booking','previous_status'=>'booking','project_id'=>$projectId ?: null,'customer_id'=>$customer->id,'booking_amount'=>$data['booking_amount'],'booking_date'=>$data['booking_date'],'booking_unit'=>$data['booking_unit']??null,'property_area_sqft'=>$data['property_area_sqft']??null,'rate_per_sqft'=>$data['rate_per_sqft']??null,'brokerage_status'=>'pending']);
        return (int)$lead->id;
    }

    private function inferMapping(array $headers): array
    {
        $norm=[];foreach($headers as $h)$norm[strtolower(preg_replace('/[^a-z0-9]+/','_',trim($h)))]=$h;
        $find=function(array $names)use($norm){foreach($names as $n){if(isset($norm[$n]))return $norm[$n];}return null;};
        return ['customer_name'=>$find(['customer_name','customer','name','client_name']),'customer_phone'=>$find(['customer_phone','phone','mobile','contact']),'booking_date'=>$find(['booking_date','date','bookingdate']),'booking_amount'=>$find(['booking_amount','booking_value','value','amount']),'project_id'=>$find(['project_id','projectid']),'project_name'=>$find(['project_name','project']),'builder_name'=>$find(['builder_name','builder']),'unit_type'=>$find(['unit_type','type']),'booking_unit'=>$find(['booking_unit','unit','flat']),'property_area_sqft'=>$find(['property_area_sqft','area','sqft']),'rate_per_sqft'=>$find(['rate_per_sqft','rate']),'source_reference'=>$find(['source_reference','reference','booking_id'])];
    }
    private function mapRow(array $row,array $mapping): array { $out=[];foreach($mapping as $k=>$header)$out[$k]=$header!==null?($row[$header]??null):null; if($out['booking_date']){$t=strtotime((string)$out['booking_date']);if($t)$out['booking_date']=date('Y-m-d',$t);} if($out['booking_amount']!==null)$out['booking_amount']=(float)preg_replace('/[^0-9.\-]/','',(string)$out['booking_amount']); return $out; }
    private function isDuplicate(array $d): bool { $q=BookingFinancial::query();if(!empty($d['source_reference']))$q->where('source_reference',$d['source_reference']);else $q->where('customer_phone_snapshot',$d['customer_phone']??'')->whereDate('booking_date',$d['booking_date'])->where('booking_amount',$d['booking_amount']);return $q->exists(); }
    private function readCsv(string $path): array { $fh=fopen($path,'r');$headers=fgetcsv($fh);$rows=[];while(($r=fgetcsv($fh))!==false){$row=[];foreach($headers as $i=>$h)$row[$h]=$r[$i]??null;$rows[]=$row;}fclose($fh);return $rows; }
    private function readXlsx(string $path): array { if(!class_exists('ZipArchive'))abort(422,'XLSX support is unavailable on this server.');$z=new \ZipArchive();abort_unless($z->open($path)===true,422,'Could not read XLSX.');$shared=[];$xml=$z->getFromName('xl/sharedStrings.xml');if($xml){$sx=simplexml_load_string($xml);foreach($sx->si as $si)$shared[]=(string)implode('',array_map('strval',(array)$si->t));}$sheet=$z->getFromName('xl/worksheets/sheet1.xml');abort_unless($sheet!==false,422,'The XLSX has no first worksheet.');$sx=simplexml_load_string($sheet);$rows=[];foreach($sx->sheetData->row as $ri=>$r){$vals=[];foreach($r->c as $c){$ref=(string)$c['r'];preg_match('/([A-Z]+)/',$ref,$m);$col=$m[1]??'A';$idx=0;for($i=0;$i<strlen($col);$i++)$idx=$idx*26+(ord($col[$i])-64);$idx--; $v=(string)$c->v;if((string)$c['t']==='s')$v=$shared[(int)$v]??$v;$vals[$idx]=$v;}$rows[]=$vals;}$headers=$rows[0]??[];$out=[];foreach(array_slice($rows,1) as $r){$row=[];foreach($headers as $i=>$h)$row[$h]=$r[$i]??null;$out[]=$row;}return $out; }
}
