<?php

namespace App\Services;

use App\Models\Agent;
use App\Models\IncentiveCollection;
use App\Models\IncentiveDeal;
use App\Models\IncentivePayout;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class IncentiveCalculationService
{
    public function config(array $overrides = []): array
    {
        $settings = app(SettingsService::class);

        $defaultSlabs = [
            ['min' => 0, 'max' => 5, 'rate' => 0],
            ['min' => 5, 'max' => 7.5, 'rate' => 5],
            ['min' => 7.5, 'max' => 10, 'rate' => 7.5],
            ['min' => 10, 'max' => 12, 'rate' => 10],
            ['min' => 12, 'max' => null, 'rate' => 12],
        ];
        $storedSlabs = json_decode((string) $settings->get('incentive_slabs_json', ''), true);
        $cfg = [
            'threshold_multiple' => (float) $settings->get('incentive_threshold_multiple', 5),
            'slabs' => is_array($storedSlabs) && count($storedSlabs) === 5 ? $storedSlabs : $defaultSlabs,
            'paid_percent' => (float) $settings->get('incentive_paid_percent', 75),
            'held_percent' => (float) $settings->get('incentive_held_percent', 25),
            'probation_months' => (int) $settings->get('incentive_probation_months', 2),
            'annual_true_up_month' => (int) $settings->get('incentive_annual_true_up_month', 6),
            'nb_revision_months' => (int) $settings->get('incentive_nb_revision_months', 24),
            'currency' => (string) $settings->get('incentive_currency', 'INR'),
            'currency_symbol' => (string) $settings->get('incentive_currency_symbol', '₹'),
        ];

        foreach ($overrides as $key => $value) {
            if (array_key_exists($key, $cfg)) {
                $cfg[$key] = $value;
            }
        }

        if (isset($overrides['slabs'])) {
            $cfg['slabs'] = $overrides['slabs'];
        }

        return $cfg;
    }

    public function slabForMultiple(float $multiple, ?array $slabs = null): array
    {
        $slabs ??= $this->config()['slabs'];
        foreach ($slabs as $index => $slab) {
            $min = (float) $slab['min'];
            $max = $slab['max'] === null ? null : (float) $slab['max'];
            if ($multiple >= $min && ($max === null || $multiple < $max)) {
                return [
                    'band' => $index,
                    'label' => $index === 0 ? 'Band 0' : 'Slab ' . $index,
                    'rate' => (float) $slab['rate'],
                ];
            }
        }

        return ['band' => 0, 'label' => 'Band 0', 'rate' => 0.0];
    }

    public function confirmationDate(Agent $agent, ?array $cfg = null): ?Carbon
    {
        $cfg ??= $this->config();
        if (! $agent->joining_date) return null;
        $probationEnd = Carbon::parse($agent->joining_date)
            ->addMonthsNoOverflow((int) $cfg['probation_months'])
            ->startOfDay();
        if ($agent->confirmation_date) {
            $recorded = Carbon::parse($agent->confirmation_date)->startOfDay();
            return $recorded->greaterThan($probationEnd) ? $recorded : $probationEnd;
        }
        return $probationEnd;
    }

    public function firstEligibleMonth(Agent $agent, ?array $cfg = null): ?Carbon
    {
        $confirmation = $this->confirmationDate($agent, $cfg);
        return $confirmation?->copy()->startOfMonth()->addMonth();
    }

    public function confirmedMonths(Agent $agent, Carbon $from, Carbon $to, ?array $cfg = null): int
    {
        $first = $this->firstEligibleMonth($agent, $cfg);
        if (! $first) return 0;

        $fromMonth = $from->copy()->startOfMonth();
        $toMonth = $to->copy()->startOfMonth();
        $start = $first->greaterThan($fromMonth) ? $first : $fromMonth;

        if ($agent->exit_date) {
            $exitMonth = Carbon::parse($agent->exit_date)->startOfMonth();
            if ($exitMonth->lessThan($toMonth)) $toMonth = $exitMonth;
        }

        if ($start->greaterThan($toMonth)) return 0;
        return $start->diffInMonths($toMonth) + 1;
    }

    public function quarterFor(Carbon $date): array
    {
        $quarter = (int) ceil($date->month / 3);
        $startMonth = (($quarter - 1) * 3) + 1;
        return [
            'key' => $date->year . '-Q' . $quarter,
            'year' => $date->year,
            'quarter' => $quarter,
            'from' => Carbon::create($date->year, $startMonth, 1)->startOfDay(),
            'to' => Carbon::create($date->year, $startMonth + 2, 1)->endOfMonth()->endOfDay(),
        ];
    }

    public function annualPeriod(int $year): array
    {
        return [
            'from' => Carbon::create($year, 1, 1)->startOfDay(),
            'to' => Carbon::create($year, 12, 31)->endOfDay(),
        ];
    }

    public function calculatePeriod(Agent $agent, Carbon $from, Carbon $to, ?array $cfg = null): array
    {
        $cfg ??= $this->config();
        $months = $this->confirmedMonths($agent, $from, $to, $cfg);
        $deals = IncentiveDeal::query()
            ->where('agent_id', $agent->id)
            ->whereBetween('booking_date', [$from->toDateString(), $to->toDateString()])
            ->get();

        $nb = (float) $deals->sum(fn ($d) => $d->effectiveNb());
        $salaryService = app(\App\Services\SalaryHistoryService::class);
        $eligibleFrom = $from->copy()->startOfMonth();
        $firstEligible = $this->firstEligibleMonth($agent, $cfg);
        if ($firstEligible && $firstEligible->greaterThan($eligibleFrom)) $eligibleFrom = $firstEligible->copy();
        $eligibleTo = $to->copy()->startOfMonth();
        if ($agent->exit_date) {
            $exitMonth = Carbon::parse($agent->exit_date)->startOfMonth();
            if ($exitMonth->lessThan($eligibleTo)) $eligibleTo = $exitMonth;
        }
        $salary = $months > 0 && $eligibleFrom->lte($eligibleTo)
            ? $salaryService->averageMonthlySalary($agent, $eligibleFrom, $eligibleTo)
            : 0.0;
        $salarySnapshot = $months > 0 && $eligibleFrom->lte($eligibleTo)
            ? $salaryService->snapshot($agent, $eligibleFrom, $eligibleTo)
            : [];
        $avg = $months > 0 ? $nb / $months : 0.0;
        $multiple = $salary > 0 ? $avg / $salary : 0.0;
        $slab = $this->slabForMultiple($multiple, $cfg['slabs']);
        $threshold = $salary * (float) $cfg['threshold_multiple'];
        $surplus = max(0, $avg - $threshold);

        return [
            'agent' => $agent,
            'from' => $from,
            'to' => $to,
            'months' => $months,
            'nb' => $nb,
            'salary' => $salary,
            'salary_snapshot' => $salarySnapshot,
            'average_monthly_nb' => $avg,
            'multiple' => $multiple,
            'slab' => $slab,
            'threshold' => $threshold,
            'surplus' => $surplus,
            'incentive' => $months > 0 ? $surplus * ($slab['rate'] / 100) * 3 : 0.0,
        ];
    }

    public function quarter(Agent $agent, int $year, int $quarter, ?array $cfg = null): array
    {
        $startMonth = (($quarter - 1) * 3) + 1;
        $from = Carbon::create($year, $startMonth, 1)->startOfDay();
        $to = $from->copy()->addMonths(2)->endOfMonth()->endOfDay();
        $result = $this->calculatePeriod($agent, $from, $to, $cfg);
        $result['quarter_key'] = $year . '-Q' . $quarter;
        $result['quarter'] = $quarter;
        return $result;
    }

    public function exitSettlement(Agent $agent, ?Carbon $asOf = null, ?array $cfg = null): array
    {
        $cfg ??= $this->config();
        $exit = $asOf?->copy()->endOfDay()
            ?? ($agent->exit_date ? Carbon::parse($agent->exit_date)->endOfDay() : now()->endOfDay());
        $yearStart = $exit->copy()->startOfYear();
        $calc = $this->calculatePeriod($agent, $yearStart, $exit, $cfg);
        $finalIncentive = $calc['months'] > 0
            ? $calc['surplus'] * ($calc['slab']['rate'] / 100) * $calc['months']
            : 0.0;
        $balances = $this->balances($agent);
        $alreadyPaid = $balances['paid'];
        $held = $balances['held_balance'];
        $provisionalTotal = $alreadyPaid + $held;
        $delta = round($finalIncentive - $provisionalTotal, 2);

        if ($delta >= -0.01) {
            $releaseFromHeld = $held;
            $extraPayment = max(0, $delta);
            $recoveryFromHeld = 0.0;
            $carryRecovery = 0.0;
        } else {
            $overpayment = abs($delta);
            $recoveryFromHeld = min($held, $overpayment);
            $releaseFromHeld = max(0, $held - $recoveryFromHeld);
            $extraPayment = 0.0;
            $carryRecovery = max(0, $overpayment - $recoveryFromHeld);
        }

        return [
            'exit_date' => $exit,
            'calc' => $calc,
            'final_incentive' => $finalIncentive,
            'already_paid' => $alreadyPaid,
            'held' => $held,
            'provisional_total' => $provisionalTotal,
            'delta' => $delta,
            'release_from_held' => round($releaseFromHeld, 2),
            'extra_payment' => round($extraPayment, 2),
            'recovery_from_held' => round($recoveryFromHeld, 2),
            'carry_recovery' => round($carryRecovery, 2),
            'remaining_held_after_recovery' => round($releaseFromHeld, 2),
        ];
    }

    public function annual(Agent $agent, int $year, ?array $cfg = null): array
    {
        $period = $this->annualPeriod($year);
        $today = now()->startOfDay();
        $isCurrentYear = $year === (int) $today->year;
        $isFutureYear = $year > (int) $today->year;

        if ($isFutureYear) {
            return [
                'agent' => $agent, 'from' => $period['from'], 'to' => $period['to'],
                'months' => 0, 'nb' => 0.0, 'salary' => 0.0, 'salary_snapshot' => [],
                'average_monthly_nb' => 0.0, 'multiple' => 0.0,
                'slab' => $this->slabForMultiple(0, $cfg ? $cfg['slabs'] ?? null : null),
                'threshold' => 0.0, 'surplus' => 0.0, 'annual_incentive' => 0.0,
                'period_label' => 'Future year', 'is_ytd' => false, 'is_future' => true,
            ];
        }

        if ($isCurrentYear) {
            $period['to'] = $today->copy()->endOfDay();
        }

        $result = $this->calculatePeriod($agent, $period['from'], $period['to'], $cfg);
        $result['annual_incentive'] = $result['months'] > 0
            ? $result['surplus'] * ($result['slab']['rate'] / 100) * $result['months']
            : 0.0;
        $result['period_label'] = $isCurrentYear ? 'Year to date' : 'Full year';
        $result['is_ytd'] = $isCurrentYear;
        $result['is_future'] = false;
        return $result;
    }

    public function earnedOnCollection(IncentiveDeal $deal, IncentiveCollection $collection, ?array $cfg = null): array
    {
        $cfg ??= $this->config();
        $q = $this->quarterFor(Carbon::parse($deal->booking_date));
        $quarter = $this->quarter($deal->agent()->firstOrFail(), $q['year'], $q['quarter'], $cfg);
        $dealNb = max(0.0, $deal->effectiveNb());
        $collectionAmount = min((float) $collection->amount, max(0.0, $dealNb));
        $ratio = $dealNb > 0 ? min(1, $collectionAmount / $dealNb) : 0.0;
        $earned = $quarter['incentive'] * $ratio;
        $paid = $earned * ((float) $cfg['paid_percent'] / 100);
        $held = $earned * ((float) $cfg['held_percent'] / 100);

        return compact('q', 'quarter', 'dealNb', 'ratio', 'earned', 'paid', 'held');
    }

    /**
     * Calculate the target ledger for a deal using the policy active at reconciliation time.
     * Once a collection creates payout rows, those rows are immutable audit history; any
     * later NB revision creates explicit reconciliation entries rather than rewriting them.
     */
    public function dealPayoutTargets(IncentiveDeal $deal, ?array $cfg = null): array
    {
        $cfg ??= $deal->policy_snapshot_json ? (json_decode((string) $deal->policy_snapshot_json, true) ?: $this->config()) : $this->config();
        $deal->loadMissing('agent', 'collections');
        $dealNb = max(0.0, $deal->effectiveNb());
        if ($dealNb <= 0) return ['paid' => 0.0, 'held' => 0.0, 'earned' => 0.0];

        $totalCollection = min($dealNb, (float) $deal->collections->sum('amount'));
        $q = $this->quarterFor(Carbon::parse($deal->booking_date));
        $quarter = $this->quarter($deal->agent, $q['year'], $q['quarter'], $cfg);
        $earned = $quarter['incentive'] * min(1, $totalCollection / $dealNb);

        return [
            'earned' => $earned,
            'paid' => $earned * ($cfg['paid_percent'] / 100),
            'held' => $earned * ($cfg['held_percent'] / 100),
            'quarter_key' => $q['key'],
            'quarter_incentive' => $quarter['incentive'],
        ];
    }

    public function reconcileDealPayouts(IncentiveDeal $deal, int $actorUserId, string $reason): array
    {
        $deal->loadMissing('agent', 'collections');
        $target = $this->dealPayoutTargets($deal);
        $rows = IncentivePayout::query()->where('deal_id', $deal->id)->get();
        $currentPaid = (float) $rows->where('type', IncentivePayout::PROVISIONAL_PAID)->sum('amount');
        $currentHeld = (float) $rows->where('type', IncentivePayout::HELD_ACCRUAL)->sum('amount')
            - (float) $rows->where('type', IncentivePayout::HELD_RELEASE)->sum('amount')
            - (float) $rows->where('type', IncentivePayout::RECOVERY)->sum('amount');
        $adjustments = ['paid_added' => 0.0, 'recovered' => 0.0, 'held_added' => 0.0];

        if ($target['paid'] > $currentPaid + 0.01) {
            $amount = round($target['paid'] - $currentPaid, 2);
            IncentivePayout::create([
                'agent_id' => $deal->agent_id, 'deal_id' => $deal->id,
                'period_key' => $target['quarter_key'], 'type' => IncentivePayout::PROVISIONAL_PAID,
                'amount' => $amount, 'reference' => 'NB revision reconciliation',
                'notes' => $reason, 'created_by_user_id' => $actorUserId,
            ]);
            $adjustments['paid_added'] = $amount;
        } elseif ($target['paid'] < $currentPaid - 0.01) {
            $amount = round($currentPaid - $target['paid'], 2);
            $fromHeld = min(max(0, $currentHeld), $amount);
            if ($fromHeld > 0) {
                IncentivePayout::create([
                    'agent_id' => $deal->agent_id, 'deal_id' => $deal->id,
                    'period_key' => $target['quarter_key'], 'type' => IncentivePayout::RECOVERY,
                    'amount' => round($fromHeld, 2), 'reference' => 'NB revision recovery from held',
                    'notes' => $reason, 'created_by_user_id' => $actorUserId,
                ]);
                $adjustments['recovered'] = round($fromHeld, 2);
                $currentHeld -= $fromHeld;
            }
        }

        if ($target['held'] > $currentHeld + 0.01) {
            $amount = round($target['held'] - $currentHeld, 2);
            IncentivePayout::create([
                'agent_id' => $deal->agent_id, 'deal_id' => $deal->id,
                'period_key' => $target['quarter_key'], 'type' => IncentivePayout::HELD_ACCRUAL,
                'amount' => $amount, 'reference' => 'NB revision held reconciliation',
                'notes' => $reason, 'created_by_user_id' => $actorUserId,
            ]);
            $adjustments['held_added'] = $amount;
        }

        return $adjustments + ['target' => $target];
    }

    public function balances(Agent $agent, ?string $periodKey = null): array
    {
        $query = IncentivePayout::query()->where('agent_id', $agent->id);
        if ($periodKey) $query->where('period_key', $periodKey);
        $rows = $query->get();

        $paid = (float) $rows->whereIn('type', [IncentivePayout::PROVISIONAL_PAID, IncentivePayout::HELD_RELEASE, IncentivePayout::TRUE_UP_PAYMENT])->sum('amount');
        $provisional = (float) $rows->where('type', IncentivePayout::PROVISIONAL_PAID)->sum('amount');
        $heldAccrued = (float) $rows->where('type', IncentivePayout::HELD_ACCRUAL)->sum('amount');
        $heldReleased = (float) $rows->where('type', IncentivePayout::HELD_RELEASE)->sum('amount');
        $recovered = (float) $rows->whereIn('type', [IncentivePayout::RECOVERY, IncentivePayout::TRUE_UP_RECOVERY])->sum('amount');
        $recoveryCarry = (float) $rows->where('type', IncentivePayout::RECOVERY_CARRY)->sum('amount');
        $writtenOff = (float) $rows->where('type', IncentivePayout::WRITE_OFF)->sum('amount');

        return [
            'paid' => $paid,
            'provisional_paid' => $provisional,
            'held_accrued' => $heldAccrued,
            'held_released' => $heldReleased,
            'held_balance' => max(0, $heldAccrued - $heldReleased - $recovered - (float) $rows->where('type', IncentivePayout::HELD_CLOSURE)->sum('amount')),
            'recovered' => $recovered,
            'written_off' => $writtenOff,
            'recovery_carry' => $recoveryCarry,
        ];
    }

    public function annualTrueUp(Agent $agent, int $year, int $actorUserId): array
    {
        $annual = $this->annual($agent, $year);
        $balances = $this->balances($agent);
        $paidBefore = $balances['paid'];
        $held = $balances['held_balance'];
        $provisionalTotal = $paidBefore + $held;
        $delta = round($annual['annual_incentive'] - $provisionalTotal, 2);
        $released = 0.0;
        $extraPayment = 0.0;
        $recovered = 0.0;
        $carry = 0.0;
        $closedHeld = 0.0;

        if ($delta >= -0.01) {
            $released = $held;
            if ($released > 0.01) {
                IncentivePayout::create([
                    'agent_id' => $agent->id, 'period_key' => (string) $year,
                    'type' => IncentivePayout::HELD_RELEASE, 'amount' => $released,
                    'reference' => 'Annual true-up ' . $year,
                    'notes' => 'Held released at annual true-up.', 'created_by_user_id' => $actorUserId,
                ]);
            }
            $extraPayment = max(0, $delta);
            if ($extraPayment > 0.01) {
                IncentivePayout::create([
                    'agent_id' => $agent->id, 'period_key' => (string) $year,
                    'type' => IncentivePayout::TRUE_UP_PAYMENT, 'amount' => $extraPayment,
                    'reference' => 'Annual true-up ' . $year,
                    'notes' => 'Additional amount payable after held settlement.', 'created_by_user_id' => $actorUserId,
                ]);
            }
        } else {
            $overpaid = abs($delta);
            $recovered = min($held, $overpaid);
            if ($recovered > 0.01) {
                IncentivePayout::create([
                    'agent_id' => $agent->id, 'period_key' => (string) $year,
                    'type' => IncentivePayout::TRUE_UP_RECOVERY, 'amount' => $recovered,
                    'reference' => 'Annual true-up recovery ' . $year,
                    'notes' => 'Recovered from held amount only.', 'created_by_user_id' => $actorUserId,
                ]);
            }
            $released = max(0, $held - $recovered);
            if ($released > 0.01) {
                IncentivePayout::create([
                    'agent_id' => $agent->id, 'period_key' => (string) $year,
                    'type' => IncentivePayout::HELD_RELEASE, 'amount' => $released,
                    'reference' => 'Annual true-up remaining held release ' . $year,
                    'notes' => 'Remaining held released after overpayment recovery.', 'created_by_user_id' => $actorUserId,
                ]);
            }
            $carry = max(0, $overpaid - $recovered);
            if ($carry > 0.01) {
                IncentivePayout::create([
                    'agent_id' => $agent->id, 'period_key' => (string) $year,
                    'type' => IncentivePayout::RECOVERY_CARRY, 'amount' => $carry,
                    'reference' => 'Annual true-up recovery carry ' . $year,
                    'notes' => 'Unrecoverable from held; carried for up to 60 days. Never recover from salary.', 'created_by_user_id' => $actorUserId,
                ]);
            }
        }

        return [
            'annual' => $annual,
            'balances' => $balances,
            'provisional_total' => $provisionalTotal,
            'delta' => $delta,
            'released' => $released,
            'extraPayment' => $extraPayment,
            'recovered' => $recovered,
            'carry' => $carry,
            'closedHeld' => $closedHeld,
        ];
    }

    public function simulate(array $params = []): array
    {
        $cfg = $this->config($params);
        $from = Carbon::parse($params['from'] ?? now()->startOfYear());
        $to = Carbon::parse($params['to'] ?? now()->endOfYear());
        $agents = Agent::query()->with('user')
            ->where('status', 'active')
            ->whereHas('user', fn ($q) => $q->where('is_on_payroll', true));
        if (! empty($params['agent_ids'])) {
            $agents->whereIn('agents.id', array_map('intval', (array) $params['agent_ids']));
        }
        $agents = $agents->get();

        $rows = $agents->map(function (Agent $agent) use ($from, $to, $cfg) {
            $calc = $this->calculatePeriod($agent, $from, $to, $cfg);
            $balance = $this->balances($agent);
            $projectedPaid = $calc['incentive'] * ($cfg['paid_percent'] / 100);
            $projectedHeld = $calc['incentive'] * ($cfg['held_percent'] / 100);
            return [
                'agent' => $agent,
                'calc' => $calc,
                'current_paid' => $balance['paid'],
                'current_held' => $balance['held_balance'],
                'projected_paid' => $projectedPaid,
                'projected_held' => $projectedHeld,
            ];
        });

        return ['from' => $from, 'to' => $to, 'config' => $cfg, 'rows' => $rows];
    }
}
