<?php

namespace App\Http\Controllers;

use App\Models\Agent;
use App\Models\IncentiveCollection;
use App\Models\IncentiveDeal;
use App\Models\IncentivePayout;
use App\Models\Config\Setting;
use App\Services\AuditLogService;
use App\Services\AccessService;
use App\Services\IncentiveCalculationService;
use App\Services\SuperAdminService;
use App\Services\SalaryHistoryService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class IncentiveController extends Controller
{
    public function __construct(
        private IncentiveCalculationService $engine,
        private AuditLogService $audit,
        private SuperAdminService $superAdmins,
        private AccessService $access,
        private SalaryHistoryService $salaryHistory,
    ) {}

    private function canView(): bool
    {
        $userId = (int) session('user_id');
        if ($userId && $this->superAdmins->isSuperAdmin($userId)) {
            return true;
        }
        return $this->access->can('incentives.view') || $this->access->can('incentives.manage');
    }

    private function canManage(): bool
    {
        $userId = (int) session('user_id');
        if ($userId && $this->superAdmins->isSuperAdmin($userId)) {
            return true;
        }
        return $this->access->can('incentives.manage');
    }

    private function requireView(): void
    {
        abort_unless($this->canView(), 403);
    }

    private function requireManage(): void
    {
        abort_unless($this->canManage(), 403);
    }

    /**
     * Incentives are payroll-only and delegated scope is authoritative.
     */
    private function scopedPayrollAgentQuery()
    {
        $query = Agent::query()->with(['user', 'salaryHistory'])->whereHas('user', fn ($q) => $q->where('is_on_payroll', true));
        $userId = (int) session('user_id');
        $role = session('user_role');

        if ($userId && $this->superAdmins->isSuperAdmin($userId)) {
            return $query;
        }

        // Any active delegated profile supersedes the normal role default.
        if ($role === 'admin' || $role === 'team_manager') {
            if (! app(\App\Services\DelegatedAccessService::class)->hasProfile($userId)) {
                return $query->whereRaw('1 = 0');
            }
            $ids = app(\App\Services\DelegatedAccessService::class)->visibleAgentIds($userId);
            return $query->whereIn('agents.id', $ids ?: [-1]);
        }

        return $query->whereRaw('1 = 0');
    }

    private function scopedAgentOrFail(int $id): Agent
    {
        $agent = $this->scopedPayrollAgentQuery()->where('agents.id', $id)->first();
        abort_unless($agent, 404);
        return $agent;
    }

    public function index(Request $request)
    {
        $this->requireView();

        $year = (int) ($request->input('year') ?: now()->year);
        $tab = $request->input('tab', 'dashboard');
        $cfg = $this->engine->config();
        $agents = $this->scopedPayrollAgentQuery()->orderBy('id')->get();
        $activeAgents = $agents->where('status', 'active');
        $allowedAgentIds = $agents->pluck('id')->map(fn ($id) => (int) $id)->all();
        $editDeal = $request->filled('edit_deal') ? IncentiveDeal::whereIn('agent_id', $allowedAgentIds ?: [-1])->find((int) $request->input('edit_deal')) : null;
        $deals = IncentiveDeal::query()->whereIn('agent_id', $allowedAgentIds ?: [-1])->with(['agent.user', 'lead', 'collections'])->latest('booking_date')->paginate(25)->withQueryString();
        $recentCollections = IncentiveCollection::query()->whereHas('deal', fn ($q) => $q->whereIn('agent_id', $allowedAgentIds ?: [-1]))->with(['deal.agent.user'])->latest('collection_date')->paginate(20, ['*'], 'collections_page')->withQueryString();

        $dashboard = collect($activeAgents)->map(function (Agent $agent) use ($year) {
            $annual = $this->engine->annual($agent, $year);
            $balances = $this->engine->balances($agent);
            return compact('agent', 'annual', 'balances');
        });

        $totals = [
            'annual_incentive' => (float) $dashboard->sum(fn ($r) => $r['annual']['annual_incentive']),
            'paid' => (float) $dashboard->sum(fn ($r) => $r['balances']['paid']),
            'held' => (float) $dashboard->sum(fn ($r) => $r['balances']['held_balance']),
            'recovered' => (float) $dashboard->sum(fn ($r) => $r['balances']['recovered']),
        ];

        $reportAgent = $request->filled('report_agent_id') ? $agents->firstWhere('id', (int) $request->input('report_agent_id')) : $activeAgents->first();
        $reportQuarter = (int) $request->input('report_quarter', ceil(now()->month / 3));
        $reportQuarterCalc = $reportAgent ? $this->engine->quarter($reportAgent, $year, $reportQuarter) : null;
        $reportAnnualCalc = $reportAgent ? $this->engine->annual($reportAgent, $year) : null;
        $reportBalances = $reportAgent ? $this->engine->balances($reportAgent) : null;
        $reportQuarterKey = $year . '-Q' . $reportQuarter;
        $reportLedgerRows = $reportAgent
            ? IncentivePayout::query()->where('agent_id', $reportAgent->id)->where('period_key', $reportQuarterKey)->get()
            : collect();
        $reportLedgerProvisional = (float) $reportLedgerRows->where('type', IncentivePayout::PROVISIONAL_PAID)->sum('amount');
        $reportLedgerHeld = (float) $reportLedgerRows->where('type', IncentivePayout::HELD_ACCRUAL)->sum('amount')
            - (float) $reportLedgerRows->where('type', IncentivePayout::HELD_RELEASE)->sum('amount')
            - (float) $reportLedgerRows->whereIn('type', [IncentivePayout::RECOVERY, IncentivePayout::TRUE_UP_RECOVERY])->sum('amount');
        $reportLedgerAccrued = max(0, round($reportLedgerProvisional + $reportLedgerHeld, 2));
        $reportLedgerVariance = $reportQuarterCalc ? round((float) $reportQuarterCalc['incentive'] - $reportLedgerAccrued, 2) : 0.0;
        $reportPayouts = $reportAgent ? IncentivePayout::query()->where('agent_id', $reportAgent->id)->latest('id')->paginate(50, ['*'], 'payouts_page')->withQueryString() : collect();

        $simulation = null;
        if ($tab === 'simulation' && $request->boolean('run')) {
            $simulation = $this->engine->simulate([
                'from' => $request->input('from', $year . '-01-01'),
                'to' => $request->input('to', $year . '-12-31'),
                'threshold_multiple' => (float) $request->input('threshold_multiple', $cfg['threshold_multiple']),
                'paid_percent' => (float) $request->input('paid_percent', $cfg['paid_percent']),
                'held_percent' => (float) $request->input('held_percent', $cfg['held_percent']),
                'slabs' => $this->slabsFromRequest($request, $cfg['slabs']),
                'agent_ids' => $allowedAgentIds,
            ]);
        }

        return view('admin.incentives.index', compact(
            'year', 'tab', 'cfg', 'agents', 'deals', 'recentCollections',
            'dashboard', 'totals', 'simulation', 'editDeal', 'reportAgent', 'reportQuarter',
            'reportQuarterCalc', 'reportAnnualCalc', 'reportBalances', 'reportPayouts', 'reportQuarterKey', 'reportLedgerProvisional', 'reportLedgerHeld', 'reportLedgerAccrued', 'reportLedgerVariance'
        ));
    }

    public function saveConfig(Request $request)
    {
        $this->requireManage();
        $data = $request->validate([
            'threshold_multiple' => 'required|numeric|min:0|max:100',
            'paid_percent' => 'required|numeric|min:0|max:100',
            'held_percent' => 'required|numeric|min:0|max:100',
            'probation_months' => 'required|integer|min:0|max:24',
            'annual_true_up_month' => 'required|integer|min:1|max:12',
            'nb_revision_months' => 'required|integer|min:1|max:120',
            'currency' => 'required|string|max:10',
            'currency_symbol' => 'required|string|max:5',
            'slab_min' => 'required|array|size:5',
            'slab_max' => 'nullable|array|size:5',
            'slab_rate' => 'required|array|size:5',
            'slab_min.*' => 'required|numeric|min:0|max:1000',
            'slab_max.*' => 'nullable|numeric|min:0|max:1000',
            'slab_rate.*' => 'required|numeric|min:0|max:100',
        ]);

        if (abs(((float) $data['paid_percent'] + (float) $data['held_percent']) - 100) > 0.0001) {
            return back()->withErrors(['paid_percent' => 'Paid + held must equal 100%.'])->withInput();
        }

        $slabs = [];
        for ($i = 0; $i < 5; $i++) {
            $min = (float) $data['slab_min'][$i];
            $maxRaw = $data['slab_max'][$i] ?? null;
            $max = ($maxRaw === '' || $maxRaw === null) ? null : (float) $maxRaw;
            $rate = (float) $data['slab_rate'][$i];
            if ($i === 0 && $min !== 0.0) return back()->withErrors(['slab_min.0' => 'Band 0 must start at 0.'])->withInput();
            if ($max !== null && $max <= $min) return back()->withErrors(['slab_max.' . $i => 'Slab maximum must be greater than its minimum.'])->withInput();
            if ($i > 0 && abs($min - (float) $slabs[$i - 1]['max']) > 0.0001) {
                return back()->withErrors(['slab_min.' . $i => 'Each slab must start where the previous slab ends.'])->withInput();
            }
            $slabs[] = ['min' => $min, 'max' => $max, 'rate' => $rate];
        }

        if ($slabs[4]['max'] !== null) {
            return back()->withErrors(['slab_max.4' => 'The final slab must have no upper boundary.'])->withInput();
        }

        $before = $this->engine->config();
        $values = [
            'incentive_threshold_multiple' => (string) $data['threshold_multiple'],
            'incentive_paid_percent' => (string) $data['paid_percent'],
            'incentive_held_percent' => (string) $data['held_percent'],
            'incentive_probation_months' => (string) $data['probation_months'],
            'incentive_annual_true_up_month' => (string) $data['annual_true_up_month'],
            'incentive_nb_revision_months' => (string) $data['nb_revision_months'],
            'incentive_currency' => $data['currency'],
            'incentive_currency_symbol' => $data['currency_symbol'],
            'incentive_slabs_json' => json_encode($slabs, JSON_UNESCAPED_SLASHES),
        ];
        foreach ($values as $key => $value) Setting::put($key, $value);
        app(\App\Services\SettingsService::class)->flush();

        $this->audit->record('incentive', 'Incentive configuration changed', $request, [
            'before' => $before,
            'after' => $this->engine->config(),
        ], 'IncentiveConfig', null);

        return back()->with('success', 'Incentive configuration saved and audited.');
    }

    public function saveAgent(Request $request, ?int $id = null)
    {
        $this->requireManage();
        $agent = $id ? $this->scopedAgentOrFail($id) : abort(404, 'Payroll employee record must already exist in CRM.');
        $data = $request->validate([
            'employee_id' => ['nullable', 'string', 'max:50', Rule::unique('agents', 'employee_id')->ignore($agent->id)],
            'salary' => 'required|numeric|min:0|max:999999999',
            'joining_date' => 'required|date',
            'confirmation_date' => 'nullable|date|after_or_equal:joining_date',
            'exit_date' => 'nullable|date|after_or_equal:joining_date',
            'salary_effective_from' => 'nullable|date',
            'salary_change_reason' => 'nullable|string|max:500',
        ]);

        $before = $agent->only(['employee_id', 'salary', 'joining_date', 'confirmation_date', 'exit_date']);
        $oldSalary = (float)($agent->salary ?? 0);
        $newSalary = (float)$data['salary'];
        $actor = (int)session('user_id');
        $effective = $request->filled('salary_effective_from')
            ? Carbon::parse($request->input('salary_effective_from'))->startOfDay()
            : Carbon::today();

        // Keep the operational Agent salary as today's salary. Historical/future salary is stored separately.
        // This prevents a future revision from changing today's payroll view prematurely.
        $salaryHistoryRow = null;
        if ($oldSalary > 0 && abs($oldSalary - $newSalary) > 0.005) {
            $this->salaryHistory->ensureBaseline($agent->fresh(), $actor);
            $salaryHistoryRow = $this->salaryHistory->recordChange(
                $agent->fresh(),
                $newSalary,
                $effective,
                trim((string)$request->input('salary_change_reason')) ?: 'Salary revision',
                $actor
            );
        } elseif ($oldSalary <= 0 && $newSalary > 0) {
            $salaryHistoryRow = $this->salaryHistory->recordChange(
                $agent->fresh(),
                $newSalary,
                $effective,
                trim((string)$request->input('salary_change_reason')) ?: 'Initial salary history entry',
                $actor
            );
        }

        $agent->update([
            'employee_id' => $data['employee_id'] ?? null,
            'joining_date' => $data['joining_date'],
            'confirmation_date' => $data['confirmation_date'] ?? null,
            'exit_date' => $data['exit_date'] ?? null,
        ]);

        // Sync Agent.salary only when the effective revision is active today.
        if ($effective->lte(Carbon::today())) {
            $todaySalary = $this->salaryHistory->salaryForDate($agent->fresh(), Carbon::today());
            $agent->update(['salary' => $todaySalary]);
        }

        $this->audit->record('incentive', 'Incentive agent master updated', $request, [
            'agent_id' => $agent->id,
            'before' => $before,
            'after' => $agent->fresh()->only(array_keys($before)),
            'salary_effective_from' => $effective->toDateString(),
            'salary_history_id' => $salaryHistoryRow?->id,
        ], 'Agent', $agent->id);

        return back()->with('success', 'Agent incentive master updated. Salary history was preserved by effective date.');
    }

    public function saveSalaryHistory(Request $request, int $id)
    {
        $this->requireManage();
        $agent = $this->scopedAgentOrFail($id);
        $data = $request->validate([
            'monthly_salary' => 'required|numeric|min:0|max:999999999',
            'effective_from' => 'required|date',
            'reason' => 'required|string|max:500',
        ]);

        $effective = Carbon::parse($data['effective_from'])->startOfDay();
        if ($agent->joining_date && $effective->lt(Carbon::parse($agent->joining_date)->startOfDay())) {
            return back()->withErrors(['effective_from' => 'Salary effective date cannot be before the employee joining date.'])->withInput();
        }

        $actor = (int)session('user_id');
        $this->salaryHistory->ensureBaseline($agent->fresh(), $actor);
        $row = $this->salaryHistory->recordChange($agent->fresh(), (float)$data['monthly_salary'], $effective, trim($data['reason']), $actor);

        // Keep current Agent Master salary synchronized with the salary applicable today.
        if ($effective->lte(Carbon::today())) {
            $todaySalary = $this->salaryHistory->salaryForDate($agent->fresh(), Carbon::today());
            $agent->update(['salary' => $todaySalary]);
        }

        $this->audit->record('incentive', 'Salary history entry created/updated', $request, [
            'agent_id' => $agent->id,
            'salary_history_id' => $row->id,
            'monthly_salary' => (float)$row->monthly_salary,
            'effective_from' => $row->effective_from?->toDateString(),
            'effective_to' => $row->effective_to?->toDateString(),
            'reason' => $row->reason,
        ], 'SalaryHistory', $row->id);

        return back()->with('success', 'Salary history saved. Historical incentive calculations will use the effective-dated salary.');
    }

    public function saveDeal(Request $request, ?int $id = null)
    {
        $this->requireManage();
        $deal = $id ? IncentiveDeal::findOrFail($id) : new IncentiveDeal();
        if ($deal->exists) {
            $this->scopedAgentOrFail((int) $deal->agent_id);
        }
        $data = $request->validate([
            'agent_id' => 'required|integer|exists:agents,id',
            'lead_id' => 'nullable|integer|exists:leads,id',
            'booking_date' => 'required|date',
            'booked_nb' => 'required|numeric|min:0',
            'settled_nb' => 'nullable|numeric|min:0',
            'developer_adjustment' => 'nullable|numeric|min:0',
            'client_passback' => 'nullable|numeric|min:0',
            'sub_broker_share' => 'nullable|numeric|min:0',
            'referral_fee' => 'nullable|numeric|min:0',
            'status' => 'required|in:booked,partially_settled,settled',
            'notes' => 'nullable|string|max:4000',
            'revision_reason' => 'nullable|string|max:2000',
            'authorization_note' => 'nullable|string|max:2000',
        ]);

        $this->scopedAgentOrFail((int) $data['agent_id']);

        foreach (['settled_nb','developer_adjustment','client_passback','sub_broker_share','referral_fee'] as $key) {
            $data[$key] = $data[$key] ?? null;
        }

        if ($deal->exists) {
            $nbChanged = abs((float) $deal->booked_nb - (float) $data['booked_nb']) > 0.005
                || abs((float) ($deal->settled_nb ?? 0) - (float) ($data['settled_nb'] ?? 0)) > 0.005;
            if ($nbChanged) {
                $reason = trim((string) ($data['revision_reason'] ?? ''));
                if (strlen($reason) < 3) return back()->withErrors(['revision_reason' => 'A reason is required for every NB revision.'])->withInput();
                $limit = (int) $this->engine->config()['nb_revision_months'];
                $ageMonths = Carbon::parse($deal->booking_date)->diffInMonths(now());
                if ($ageMonths > $limit && strlen(trim((string) ($data['authorization_note'] ?? ''))) < 10) {
                    return back()->withErrors(['authorization_note' => "This booking is older than {$limit} months. Written MD/legal authorization reference is required."])->withInput();
                }
            }
        }

        $beforeDeal = $deal->exists ? $deal->toArray() : null;
        $oldCalc = null;
        if ($deal->exists) {
            $agentBefore = Agent::find($deal->agent_id);
            if ($agentBefore) {
                $q = $this->engine->quarterFor(Carbon::parse($deal->booking_date));
                $oldCalc = $this->engine->quarter($agentBefore, $q['year'], $q['quarter']);
            }
        }

        $deal->fill($data);
        if (! $deal->exists || empty($deal->policy_snapshot_json)) {
            $deal->policy_snapshot_json = json_encode($this->engine->config(), JSON_UNESCAPED_SLASHES);
        }
        $deal->created_by_user_id ??= (int) session('user_id');
        $deal->save();

        $reconciliation = null;
        if ($deal->exists && $deal->collections()->exists()) {
            $reconciliation = $this->engine->reconcileDealPayouts($deal->fresh(), (int) session('user_id'), (string) ($data['revision_reason'] ?? 'Deal/NB update'));
        }

        $newCalc = null;
        $agentAfter = Agent::find($deal->agent_id);
        if ($agentAfter) {
            $q = $this->engine->quarterFor(Carbon::parse($deal->booking_date));
            $newCalc = $this->engine->quarter($agentAfter, $q['year'], $q['quarter']);
        }

        $this->audit->record('incentive', $id ? 'Deal/NB updated' : 'Incentive deal created', $request, [
            'deal_id' => $deal->id,
            'before' => $beforeDeal,
            'after' => $deal->toArray(),
            'revision_reason' => $data['revision_reason'] ?? null,
            'authorization_note' => $data['authorization_note'] ?? null,
            'old_quarter' => $oldCalc,
            'new_quarter' => $newCalc,
            'payout_reconciliation' => $reconciliation,
        ], 'IncentiveDeal', $deal->id);

        return redirect('/admin/incentives?tab=deals')->with('success', $id ? 'Deal/NB updated and payout impact reconciled.' : 'Deal created.');
    }

    public function addCollection(Request $request, int $dealId)
    {
        $this->requireManage();
        $deal = IncentiveDeal::query()->with(['agent', 'collections'])->findOrFail($dealId);
        $this->scopedAgentOrFail((int) $deal->agent_id);
        $data = $request->validate([
            'collection_date' => 'required|date|before_or_equal:today',
            'amount' => 'required|numeric|min:0.01',
            'notes' => 'nullable|string|max:2000',
        ]);

        $settledNb = (float) $deal->effectiveNb();
        if ($settledNb <= 0) {
            return back()->withErrors(['amount' => 'A positive Settled NB is required before a collection can be recorded.'])->withInput();
        }

        $existingCollected = (float) $deal->collections->sum('amount');
        $newTotal = round($existingCollected + (float) $data['amount'], 2);
        if ($newTotal > $settledNb + 0.01) {
            return back()->withErrors([
                'amount' => 'Collection would exceed Settled NB. Remaining receivable is ₹' . number_format(max(0, $settledNb - $existingCollected), 2) . '.',
            ])->withInput();
        }

        $collection = DB::transaction(function () use ($deal, $data) {
            $collection = IncentiveCollection::create([
                'deal_id' => $deal->id,
                'collection_date' => $data['collection_date'],
                'amount' => $data['amount'],
                'notes' => $data['notes'] ?? null,
                'created_by_user_id' => (int) session('user_id'),
            ]);
            $this->reconcileCollectionLedger(
                $deal->fresh(['agent', 'collections']),
                (int) session('user_id'),
                (int) $collection->id
            );
            return $collection;
        });

        $this->audit->record('incentive', 'Deal collection entered', $request, [
            'deal_id' => $deal->id, 'collection_id' => $collection->id,
            'date' => $collection->collection_date?->toDateString(), 'amount' => (float) $collection->amount,
        ], 'IncentiveCollection', $collection->id);

        return back()->with('success', 'Collection recorded; provisional/held incentive ledger reconciled.');
    }

    /**
     * Repairs/reconciles payout rows for collections that were imported or entered
     * before the payout ledger workflow existed. This is an explicit POST action,
     * never a mutation on a GET page load.
     */
    public function reconcileDeal(Request $request, int $dealId)
    {
        $this->requireManage();
        $deal = IncentiveDeal::query()->with(['agent', 'collections'])->findOrFail($dealId);
        $this->scopedAgentOrFail((int) $deal->agent_id);

        $result = DB::transaction(function () use ($deal) {
            return $this->reconcileCollectionLedger(
                $deal->fresh(['agent', 'collections']),
                (int) session('user_id'),
                null,
                'Manual payout ledger reconciliation'
            );
        });

        $this->audit->record('incentive', 'Deal payout ledger reconciled', $request, [
            'deal_id' => $deal->id,
            'result' => $result,
        ], 'IncentiveDeal', $deal->id);

        return back()->with('success', 'Payout ledger reconciled for Deal #' . $deal->id . '.');
    }

    private function reconcileCollectionLedger(IncentiveDeal $deal, int $actorUserId, ?int $collectionId = null, string $reason = 'Collection ledger reconciliation'): array
    {
        $target = $this->engine->dealPayoutTargets($deal);
        $rows = IncentivePayout::query()->where('deal_id', $deal->id)->get();
        $currentPaid = (float) $rows->where('type', IncentivePayout::PROVISIONAL_PAID)->sum('amount');
        $currentHeld = (float) $rows->where('type', IncentivePayout::HELD_ACCRUAL)->sum('amount')
            - (float) $rows->where('type', IncentivePayout::HELD_RELEASE)->sum('amount')
            - (float) $rows->whereIn('type', [IncentivePayout::RECOVERY, IncentivePayout::TRUE_UP_RECOVERY])->sum('amount');
        $currentTotal = $currentPaid + max(0, $currentHeld);
        $targetTotal = $target['paid'] + $target['held'];
        $result = ['target' => $target, 'paid_added' => 0.0, 'held_added' => 0.0, 'recovered' => 0.0, 'carry' => 0.0];

        $referenceCollectionId = $collectionId;
        if (! $referenceCollectionId) {
            $referenceCollectionId = $deal->collections->sortByDesc('id')->first()?->id;
        }

        if ($targetTotal > $currentTotal + 0.01) {
            $needed = round($targetTotal - $currentTotal, 2);
            $paidGap = max(0, round($target['paid'] - $currentPaid, 2));
            if ($paidGap > 0) {
                $amount = min($needed, $paidGap);
                IncentivePayout::create([
                    'agent_id' => $deal->agent_id, 'deal_id' => $deal->id, 'collection_id' => $referenceCollectionId,
                    'period_key' => $target['quarter_key'], 'type' => IncentivePayout::PROVISIONAL_PAID, 'amount' => $amount,
                    'reference' => 'Collection release', 'notes' => $reason . ' — 75% provisional payment.', 'created_by_user_id' => $actorUserId,
                ]);
                $result['paid_added'] = round($amount, 2);
                $needed -= $amount;
            }
            if ($needed > 0.01) {
                IncentivePayout::create([
                    'agent_id' => $deal->agent_id, 'deal_id' => $deal->id, 'collection_id' => $referenceCollectionId,
                    'period_key' => $target['quarter_key'], 'type' => IncentivePayout::HELD_ACCRUAL, 'amount' => $needed,
                    'reference' => 'Collection held accrual', 'notes' => $reason . ' — 25% held until true-up.', 'created_by_user_id' => $actorUserId,
                ]);
                $result['held_added'] = round($needed, 2);
            }
        } elseif ($targetTotal < $currentTotal - 0.01) {
            $overpayment = round($currentTotal - $targetTotal, 2);
            $fromHeld = min(max(0, $currentHeld), $overpayment);
            if ($fromHeld > 0.01) {
                IncentivePayout::create([
                    'agent_id' => $deal->agent_id, 'deal_id' => $deal->id,
                    'period_key' => $target['quarter_key'], 'type' => IncentivePayout::RECOVERY, 'amount' => round($fromHeld, 2),
                    'reference' => 'Collection/NB reconciliation', 'notes' => $reason . ' — recovered from held only; no salary recovery.', 'created_by_user_id' => $actorUserId,
                ]);
                $result['recovered'] = round($fromHeld, 2);
                $overpayment -= $fromHeld;
            }
            if ($overpayment > 0.01) {
                IncentivePayout::create([
                    'agent_id' => $deal->agent_id, 'deal_id' => $deal->id,
                    'period_key' => $target['quarter_key'], 'type' => IncentivePayout::RECOVERY_CARRY, 'amount' => round($overpayment, 2),
                    'reference' => 'Collection/NB reconciliation carry', 'notes' => $reason . ' — carried for recovery; never deducted from salary.', 'created_by_user_id' => $actorUserId,
                ]);
                $result['carry'] = round($overpayment, 2);
            }
        }

        return $result;
    }

    public function runAnnualTrueUp(Request $request)
    {
        $this->requireManage();
        $year = (int) $request->input('year', now()->year);
        if ($year >= (int) now()->year) {
            return back()->with('error', 'Annual true-up is available only after the selected calendar year has fully ended. Current/future years are shown as YTD or future, not eligible for final true-up.');
        }
        $already = IncentivePayout::query()->where('period_key', (string) $year)->whereIn('type', [IncentivePayout::HELD_RELEASE, IncentivePayout::TRUE_UP_PAYMENT, IncentivePayout::TRUE_UP_RECOVERY, IncentivePayout::RECOVERY])->exists();
        if ($already) return back()->with('error', "Annual true-up {$year} already has ledger entries; rerun is blocked to protect the audit ledger.");
        $agents = $this->scopedPayrollAgentQuery()->get();
        $results = [];
        DB::transaction(function () use ($agents, $year, &$results) {
            foreach ($agents as $agent) {
                $results[] = ['agent' => $agent, 'result' => $this->engine->annualTrueUp($agent, $year, (int) session('user_id'))];
            }
        });
        $this->audit->record('incentive', 'Annual true-up run', $request, ['year' => $year, 'agent_count' => $agents->count()]);
        return back()->with('success', "Annual true-up {$year} completed for {$agents->count()} agents.");
    }

    public function runExitSettlement(Request $request, int $agentId)
    {
        $this->requireManage();
        $agent = $this->scopedAgentOrFail($agentId);
        $exitPeriodKey = 'EXIT-' . ($agent->exit_date ?? now()->toDateString());
        if (IncentivePayout::query()->where('agent_id', $agent->id)->where('period_key', $exitPeriodKey)->exists()) {
            return back()->with('error', 'Exit settlement is already recorded for this exit date; rerun is blocked.');
        }
        $settlement = $this->engine->exitSettlement($agent, $agent->exit_date ? Carbon::parse($agent->exit_date) : now());
        $released = (float) $settlement['release_from_held'];
        $recovery = (float) $settlement['recovery_from_held'];
        $carry = (float) $settlement['carry_recovery'];
        if ($released > 0.01) {
            IncentivePayout::create([
                'agent_id' => $agent->id, 'period_key' => $exitPeriodKey,
                'type' => IncentivePayout::HELD_RELEASE, 'amount' => $released,
                'reference' => 'Exit settlement', 'notes' => 'Held released on exit settlement.', 'created_by_user_id' => (int) session('user_id'),
            ]);
        }
        if ((float) ($settlement['extra_payment'] ?? 0) > 0.01) {
            IncentivePayout::create([
                'agent_id' => $agent->id, 'period_key' => $exitPeriodKey,
                'type' => IncentivePayout::TRUE_UP_PAYMENT, 'amount' => (float) $settlement['extra_payment'],
                'reference' => 'Exit settlement additional payment', 'notes' => 'Final incentive exceeded paid plus held.', 'created_by_user_id' => (int) session('user_id'),
            ]);
        }
        if ($recovery > 0.01) {
            IncentivePayout::create([
                'agent_id' => $agent->id, 'period_key' => $exitPeriodKey,
                'type' => IncentivePayout::RECOVERY, 'amount' => $recovery,
                'reference' => 'Exit settlement recovery', 'notes' => 'Recovered from held only; no salary recovery.', 'created_by_user_id' => (int) session('user_id'),
            ]);
        }
        if ($carry > 0.01) {
            IncentivePayout::create([
                'agent_id' => $agent->id, 'period_key' => $exitPeriodKey,
                'type' => IncentivePayout::RECOVERY_CARRY, 'amount' => $carry,
                'reference' => 'Exit settlement carry', 'notes' => 'Unrecoverable balance carried for up to 60 days; never from salary.', 'created_by_user_id' => (int) session('user_id'),
            ]);
        }
        $this->audit->record('incentive', 'Agent exit settlement run', $request, [
            'agent_id' => $agent->id, 'settlement' => $settlement,
        ], 'Agent', $agent->id);
        return back()->with('success', 'Exit incentive settlement calculated and ledger updated.');
    }

    private function slabsFromRequest(Request $request, array $defaults): array
    {
        $mins = $request->input('slab_min', []);
        $maxs = $request->input('slab_max', []);
        $rates = $request->input('slab_rate', []);
        $out = [];
        for ($i = 0; $i < 5; $i++) {
            $out[] = [
                'min' => (float) ($mins[$i] ?? $defaults[$i]['min']),
                'max' => (($maxs[$i] ?? null) === '' || ($maxs[$i] ?? null) === null) ? null : (float) $maxs[$i],
                'rate' => (float) ($rates[$i] ?? $defaults[$i]['rate']),
            ];
        }
        return $out;
    }
}
