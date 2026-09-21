@extends('layouts.app')

@section('title', 'Incentive Control Center — NPO CRM')

@section('content')
@php
    $money = fn($v) => inr($v, 0);
    $activeTab = $tab;
    // Keep the view's management controls aligned with the same server-side
    // delegated permission used by IncentiveController/AccessService.
    $canManage = app(\App\Services\AccessService::class)->can('incentives.manage');
@endphp
<style>
    .inc-page{max-width:1500px;margin:0 auto;padding:16px}.inc-head{display:flex;justify-content:space-between;gap:12px;align-items:flex-start;margin-bottom:14px}.inc-head h1{margin:0}.inc-sub{color:var(--c-muted,#667085);font-size:13px;margin-top:4px}.inc-tabs{display:flex;gap:7px;overflow:auto;margin:0 0 10px;position:sticky;top:0;z-index:30;padding:5px 0;background:#fff}.inc-quick{display:flex;gap:8px;flex-wrap:wrap;background:#f8fafc;border:1px solid #e2e8f0;border-radius:12px;padding:10px;margin:0 0 14px}.inc-quick a{font-size:12px;text-decoration:none;border:1px solid #cbd5e1;border-radius:999px;padding:7px 10px;color:inherit;background:#fff}.inc-context{display:flex;gap:8px;flex-wrap:wrap;margin:0 0 12px}.inc-context a{font-size:12px;text-decoration:none;padding:7px 10px;border-radius:8px;background:#eef5ff;border:1px solid #cfe0ff;color:inherit}.inc-tabs a{white-space:nowrap;padding:9px 12px;border:1px solid var(--c-border,#ddd);border-radius:9px;text-decoration:none;color:inherit;background:var(--c-card,#fff)}.inc-tabs a.active{font-weight:700;border-color:var(--c-primary,#2563eb);background:#eef5ff}.inc-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px}.inc-card{background:var(--c-card,#fff);border:1px solid var(--c-border,#ddd);border-radius:12px;padding:14px;margin-bottom:12px}.inc-kpi{font-size:24px;font-weight:800}.inc-label{font-size:12px;color:var(--c-muted,#667085)}.inc-table{width:100%;border-collapse:collapse}.inc-table th,.inc-table td{padding:8px;border-bottom:1px solid var(--c-border,#eee);text-align:left;font-size:12px;vertical-align:top}.inc-table th{white-space:nowrap}.inc-table td.num,.inc-table th.num{text-align:right}.inc-form{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px}.inc-form .full{grid-column:1/-1}.inc-form label{font-size:12px;font-weight:700;display:block;margin-bottom:4px}.inc-form input,.inc-form select,.inc-form textarea{width:100%;box-sizing:border-box;padding:8px;border:1px solid var(--c-border,#d0d5dd);border-radius:8px;background:#fff}.inc-actions{display:flex;gap:7px;flex-wrap:wrap;align-items:center}.inc-btn{border:0;border-radius:8px;padding:8px 11px;cursor:pointer;background:#2563eb;color:#fff}.inc-btn.secondary{background:#667085}.inc-btn.warn{background:#b54708}.inc-btn.danger{background:#b42318}.inc-note{font-size:12px;color:var(--c-muted,#667085);line-height:1.5}.inc-error{background:#fff3f2;border:1px solid #fecdca;padding:10px;border-radius:9px;margin-bottom:12px}.inc-success{background:#ecfdf3;border:1px solid #abefc6;padding:10px;border-radius:9px;margin-bottom:12px}.inc-scroll{overflow:auto}.inc-band{display:grid;grid-template-columns:repeat(5,1fr);gap:8px}.inc-band .inc-card{margin:0}.inc-side{display:grid;grid-template-columns:1.1fr .9fr;gap:12px}@media(max-width:1000px){.inc-grid{grid-template-columns:repeat(2,1fr)}.inc-form{grid-template-columns:repeat(2,1fr)}.inc-side{grid-template-columns:1fr}}@media(max-width:600px){.inc-page{padding:10px}.inc-grid{grid-template-columns:1fr 1fr}.inc-form{grid-template-columns:1fr}.inc-form .full{grid-column:auto}.inc-head{display:block}.inc-band{grid-template-columns:1fr}.inc-table th,.inc-table td{font-size:11px}}
</style>

<div class="inc-page">
    <div class="inc-head">
        <div>
            <h1>💰 Incentive Control Center</h1>
            <div class="inc-sub">Super Admin only · Net Brokerage incentive ledger · policy-controlled and audited</div>
        <div class="inc-actions"><a class="inc-btn secondary" href="/admin/booking-finance">💼 Booking Financials</a></div></div>
        <form method="GET" action="/admin/incentives" class="inc-actions">
            <input type="hidden" name="tab" value="{{ $activeTab }}">
            <label style="font-size:12px;font-weight:700">Year
                <select name="year" onchange="this.form.submit()" style="padding:7px;border:1px solid var(--c-border,#ddd);border-radius:8px">
                    @for($y=now()->year-2;$y<=now()->year+1;$y++) <option value="{{ $y }}" @selected($year===$y)>{{ $y }}</option> @endfor
                </select>
            </label>
        </form>
    </div>

    @if(session('success')) <div class="inc-success">{{ session('success') }}</div> @endif
    @if(session('error')) <div class="inc-error">{{ session('error') }}</div> @endif
    @if($errors->any()) <div class="inc-error"><strong>Please correct:</strong><ul style="margin:5px 0 0 18px">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div> @endif

    <nav class="inc-tabs">
        @foreach(['dashboard'=>'📊 Overview','config'=>'⚙️ Policy & Rules','agents'=>'👤 Employees & Salary','deals'=>'🏷️ Deals & Collections','simulation'=>'🧪 What-if Simulation','reports'=>'📑 Statements & Releases'] as $key=>$label)
            <a class="{{ $activeTab===$key?'active':'' }}" href="/admin/incentives?tab={{ $key }}&year={{ $year }}">{{ $label }}</a>
        @endforeach
    </nav>
    <div class="inc-quick"><strong style="font-size:12px;padding:7px 4px">What do you want to do?</strong><a href="/admin/incentives?tab=agents&year={{$year}}">Add/change salary history</a><a href="/admin/incentives?tab=deals&year={{$year}}">Record a deal or collection</a><a href="/admin/incentives?tab=config&year={{$year}}">Change incentive policy</a><a href="/admin/incentives?tab=simulation&year={{$year}}">Test an incentive</a><a href="/admin/incentives?tab=reports&year={{$year}}">See eligible incentive</a><a href="/admin/booking-finance">💼 Booking Financials</a><a href="/incentive-guide.html">📘 Search Help</a></div>

    @if($activeTab==='dashboard')
        <div class="inc-context"><a href="#position">Agent position</a><a href="#policy">Current policy</a><a href="/admin/incentives?tab=reports&year={{$year}}">Open statements</a></div>
        <div class="inc-grid">
            <div class="inc-card"><div class="inc-label">{{ $year }} {{ $dashboard->first()['annual']['period_label'] ?? 'Period' }} incentive</div><div class="inc-kpi">{{ $money($totals['annual_incentive']) }}</div></div>
            <div class="inc-card"><div class="inc-label">Provisional release ledger</div><div class="inc-kpi">{{ $money($totals['paid']) }}</div></div>
            <div class="inc-card"><div class="inc-label">Held reserve</div><div class="inc-kpi">{{ $money($totals['held']) }}</div></div>
            <div class="inc-card"><div class="inc-label">Recovered</div><div class="inc-kpi">{{ $money($totals['recovered']) }}</div></div>
        </div>

        <div class="inc-side"><div class="inc-card"><h3>Top performers by {{ strtolower($dashboard->first()['annual']['period_label'] ?? 'period') }} incentive</h3><ol style="margin:8px 0 0 20px">@foreach($dashboard->sortByDesc(fn($r)=>$r['annual']['annual_incentive'])->take(5) as $r)<li><strong>{{ $r['agent']->user?->name }}</strong> — {{ $money($r['annual']['annual_incentive']) }}</li>@endforeach</ol></div><div class="inc-card"><h3>Slab distribution</h3>@php $slabCounts=$dashboard->groupBy(fn($r)=>$r['annual']['slab']['label'])->map->count(); @endphp@foreach($slabCounts as $label=>$count)<div style="display:flex;justify-content:space-between;padding:5px 0;border-bottom:1px solid #eee"><span>{{ $label }}</span><strong>{{ $count }}</strong></div>@endforeach</div></div>

        <div class="inc-card">
            <div class="inc-head"><div><h3 id="position" style="margin:0">Agent incentive position</h3><div class="inc-sub">Annual calculation uses settled NB where available and excludes probation months.</div></div></div>
            <div class="inc-scroll"><table class="inc-table"><tr><th>Agent</th><th>Months</th><th class="num">Avg Monthly NB</th><th class="num">Multiple</th><th>Slab</th><th class="num">{{ $dashboard->first()['annual']['period_label'] ?? 'Period' }} Incentive</th><th class="num">Provisional release</th><th class="num">Held reserve</th></tr>
            @foreach($dashboard as $r)<tr><td><strong>{{ $r['agent']->user?->name }}</strong><br><span class="inc-note">{{ $r['agent']->employee_id ?: 'No employee ID' }}</span></td><td>{{ $r['annual']['months'] }}</td><td class="num">{{ $money($r['annual']['average_monthly_nb']) }}</td><td class="num">{{ number_format($r['annual']['multiple'],2) }}x</td><td>{{ $r['annual']['slab']['label'] }} · {{ number_format($r['annual']['slab']['rate'],2) }}%</td><td class="num"><strong>{{ $money($r['annual']['annual_incentive']) }}</strong></td><td class="num">{{ $money($r['balances']['paid']) }}</td><td class="num">{{ $money($r['balances']['held_balance']) }}</td></tr>@endforeach
            </table></div>
        </div>

        <div class="inc-side">
            <div class="inc-card"><h3 id="policy">Current policy</h3><div class="inc-band">@foreach($cfg['slabs'] as $i=>$s)<div class="inc-card"><strong>{{ $i===0?'Band 0':'Slab '.$i }}</strong><div>{{ $s['min'] }}x → {{ $s['max']===null?'∞':$s['max'].'x' }}</div><div style="font-size:20px;font-weight:800">{{ $s['rate'] }}%</div></div>@endforeach</div></div>
            <div class="inc-card"><h3>Annual true-up</h3><p class="inc-note">Policy month: <strong>{{ Carbon\Carbon::create()->month($cfg['annual_true_up_month'])->format('F') }}</strong>. Run only after confirming the annual data is final. The ledger blocks duplicate runs.</p><form method="POST" action="/admin/incentives/annual-true-up" onsubmit="return confirm('Run annual true-up for all agents? This creates immutable ledger entries.');">@csrf<input type="hidden" name="year" value="{{ $year }}"><button class="inc-btn warn">Run {{ $year }} True-Up</button></form></div>
        </div>
    @endif

    @if($activeTab==='config')
        <div class="inc-context"><a href="#policy-form">Policy settings</a><a href="#slabs">Slabs</a><a href="/admin/incentives?tab=simulation&year={{$year}}">Test before changing</a></div>
        <div class="inc-card">
            <h3 id="policy-form">Policy configuration</h3>
            <p class="inc-note">These values are global policy inputs. Every save is written to the Super Admin audit ledger. Existing calculations are not silently rewritten by a configuration change; use Simulation first.</p>
            <form method="POST" action="/admin/incentives/config" class="inc-form">@csrf
                <div><label>Threshold multiple</label><input type="number" step="0.01" name="threshold_multiple" value="{{ $cfg['threshold_multiple'] }}"></div>
                <div><label>Provisional release %</label><input type="number" step="0.01" name="paid_percent" value="{{ $cfg['paid_percent'] }}"></div>
                <div><label>Held %</label><input type="number" step="0.01" name="held_percent" value="{{ $cfg['held_percent'] }}"></div>
                <div><label>Probation months</label><input type="number" name="probation_months" value="{{ $cfg['probation_months'] }}"></div>
                <div><label>Annual true-up month</label><select name="annual_true_up_month">@for($m=1;$m<=12;$m++)<option value="{{ $m }}" @selected($cfg['annual_true_up_month']===$m)>{{ Carbon\Carbon::create()->month($m)->format('F') }}</option>@endfor</select></div>
                <div><label>NB revision limit (months)</label><input type="number" name="nb_revision_months" value="{{ $cfg['nb_revision_months'] }}"></div>
                <div><label>Currency code</label><input name="currency" value="{{ $cfg['currency'] }}"></div>
                <div><label>Currency symbol</label><input name="currency_symbol" value="{{ $cfg['currency_symbol'] }}"></div>
                <div class="full"><h4 style="margin:8px 0">Performance slabs</h4><div class="inc-scroll"><table class="inc-table"><tr><th>Band</th><th>Min multiple</th><th>Max multiple (blank = open)</th><th>Rate %</th></tr>@foreach($cfg['slabs'] as $i=>$s)<tr><td>{{ $i===0?'Band 0':'Slab '.$i }}</td><td><input type="number" step="0.01" name="slab_min[{{ $i }}]" value="{{ $s['min'] }}"></td><td><input type="number" step="0.01" name="slab_max[{{ $i }}]" value="{{ $s['max'] }}"></td><td><input type="number" step="0.01" name="slab_rate[{{ $i }}]" value="{{ $s['rate'] }}"></td></tr>@endforeach</table></div></div>
                <div class="full"><button class="inc-btn">Save policy</button></div>
            </form>
        </div>
    @endif

    @if($activeTab==='agents')
        <div class="inc-context"><a href="#employees">Payroll employees</a><a href="#salary-history">Salary history</a><a href="/incentive-guide.html#salary">How salary affects incentive</a></div>
        <div class="inc-card">
            <h3 id="employees">Payroll Employee Incentive Records</h3>
            <p class="inc-note">Only CRM users marked <strong>On Payroll</strong> appear here. Employee names are fetched from the payroll roster; salary and incentive dates are maintained from this screen only. Delegated access scope is enforced.</p>
            <div class="inc-scroll">
                <table class="inc-table">
                    <tr><th>Agent</th><th>Employee ID</th><th>Current Salary</th><th>Salary History</th><th>Joining</th><th>Confirmation</th><th>Exit</th><th>Status / Role</th><th>Action</th></tr>
                    @foreach($agents as $a)
                        <tr>
                            <td><strong>{{ $a->user?->name }}</strong><br><span class="inc-note">{{ $a->user?->email }}</span></td>
                            <td>{{ $a->employee_id ?: '—' }}</td>
                            <td><strong>{{ $money($a->salary) }}</strong><br><span class="inc-note">Today / Agent Master</span></td>
                            <td><details><summary>{{ $a->salaryHistory->count() }} effective version(s)</summary><div style="min-width:300px;margin-top:7px">@forelse($a->salaryHistory as $sh)<div style="padding:7px 0;border-bottom:1px solid #eee"><strong>{{ $money($sh->monthly_salary) }}</strong> · {{ $sh->effective_from?->format('d M Y') }} → {{ $sh->effective_to?->format('d M Y') ?: 'Open' }}<br><span class="inc-note">{{ $sh->reason ?: 'No reason recorded' }}</span></div>@empty<span class="inc-note">No salary history configured; calculations fall back to Agent Master salary.</span>@endforelse</div></details></td>
                            <td>{{ $a->joining_date?->format('d M Y') ?: '—' }}</td>
                            <td>{{ $a->confirmation_date?->format('d M Y') ?: 'Auto' }}</td>
                            <td>{{ $a->exit_date?->format('d M Y') ?: '—' }}</td>
                            <td>{{ $a->status }} / {{ $a->user?->role?->value ?: $a->user?->role }}<br><span class="inc-note">Payroll employee</span></td>
                            <td>
                                <div class="inc-actions">
                                    @if($canManage)
                                        <a class="inc-btn secondary" href="/admin/incentives?tab=agents&edit_agent={{ $a->id }}&year={{ $year }}">Edit</a>
                                        @if($a->exit_date)
                                            <form method="POST" action="/admin/incentives/agents/{{ $a->id }}/exit-settlement" onsubmit="return confirm('Run exit settlement for this payroll employee?');">
                                                @csrf
                                                <button class="inc-btn warn">Exit settle</button>
                                            </form>
                                        @endif
                                    @else
                                        <span class="inc-note">View only</span>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </table>
            </div>
        </div>
        @php $editAgent = request('edit_agent') ? $agents->firstWhere('id',(int)request('edit_agent')) : null; @endphp
        @if($editAgent && $canManage)
            <div class="inc-card">
                <h3>Edit {{ $editAgent->user?->name }}</h3>
                <div class="inc-note" style="margin-bottom:10px"><strong>Salary rule:</strong> Agent Master salary is today's operational salary. Effective-dated history is authoritative for past/future incentive periods. Never overwrite an old period just to record a revision.</div>
                <form method="POST" action="/admin/incentives/agents/{{ $editAgent->id }}" class="inc-form">
                    @csrf
                    <div><label>Employee ID</label><input name="employee_id" value="{{ $editAgent->employee_id }}"></div>
                    <div><label>Today's monthly salary</label><input type="number" step="0.01" name="salary" value="{{ $editAgent->salary }}"></div><div><label>Salary effective from</label><input type="date" name="salary_effective_from" value="{{ now()->format('Y-m-d') }}"></div><div><label>Salary change reason</label><input name="salary_change_reason" placeholder="e.g. Annual revision / promotion"></div>
                    <div><label>Joining date</label><input type="date" name="joining_date" value="{{ optional($editAgent->joining_date)->format('Y-m-d') }}"></div>
                    <div><label>Confirmation date</label><input type="date" name="confirmation_date" value="{{ optional($editAgent->confirmation_date)->format('Y-m-d') }}"></div>
                    <div><label>Exit date</label><input type="date" name="exit_date" value="{{ optional($editAgent->exit_date)->format('Y-m-d') }}"></div>
                    <div><label>CRM status</label><div class="inc-note" style="padding:10px;border:1px solid #ddd;border-radius:8px;background:#f7f7f7">{{ $editAgent->status }}</div></div>
                    <div class="full"><button class="inc-btn">Save employee master</button></div>
                </form>
                <div style="margin-top:16px"><h4 style="margin:0 0 8px">Add / revise salary history</h4>
                    <p class="inc-note">Use this for a salary that belongs to a specific effective date. A later effective date automatically closes the previous period the day before it starts. The change is audited.</p>
                    <form method="POST" action="/admin/incentives/agents/{{ $editAgent->id }}/salary-history" class="inc-form">
                        @csrf
                        <div><label>Monthly salary</label><input type="number" step="0.01" min="0" name="monthly_salary" required></div>
                        <div><label>Effective from</label><input type="date" name="effective_from" value="{{ now()->format('Y-m-d') }}" required></div>
                        <div class="full"><label>Reason</label><input name="reason" required placeholder="e.g. Annual revision / promotion / correction"></div>
                        <div class="full"><button class="inc-btn">Save salary history</button></div>
                    </form>
                </div>
                <div style="margin-top:16px" class="inc-scroll"><table class="inc-table"><tr><th>Salary</th><th>Effective from</th><th>Effective to</th><th>Reason</th></tr>@forelse($editAgent->salaryHistory as $sh)<tr><td class="num">{{ $money($sh->monthly_salary) }}</td><td>{{ $sh->effective_from?->format('d M Y') }}</td><td>{{ $sh->effective_to?->format('d M Y') ?: 'Open' }}</td><td>{{ $sh->reason ?: '—' }}</td></tr>@empty<tr><td colspan="4" class="inc-note">No salary history configured yet. Existing Agent Master salary remains the fallback.</td></tr>@endforelse</table></div>
            </div>
        @endif
    @endif

    @if($activeTab==='deals')
        <div class="inc-context"><a href="#deal-master">Deals</a><a href="#collections">Actual collections</a><a href="#reconciliation">Reconcile releases</a></div>
        @if($canManage)
            <div class="inc-card">
                <h3>{{ $editDeal ? 'Edit Deal / NB' : 'Add Deal' }}</h3>
                <p class="inc-note">Booked NB is the estimate at entry. Settled NB is authoritative once final brokerage is confirmed. Developer adjustment, passback, sub-broker and referral fields document the components behind the net amount; they are <strong>not subtracted a second time</strong>. NB revisions require a reason and are restricted by the configured revision window.</p>
                <form method="POST" action="/admin/incentives/deals{{ $editDeal ? '/'.$editDeal->id : '' }}" class="inc-form">
                    @csrf
                    <div><label>Agent</label><select name="agent_id" required>@foreach($agents as $a)<option value="{{ $a->id }}" @selected(($editDeal?->agent_id ?? '')==$a->id)>{{ $a->user?->name }}</option>@endforeach</select></div>
                    <div><label>CRM Lead ID (optional)</label><input type="number" name="lead_id" value="{{ $editDeal?->lead_id }}"></div>
                    <div><label>Booking date</label><input type="date" name="booking_date" required value="{{ optional($editDeal?->booking_date)->format('Y-m-d') ?: now()->format('Y-m-d') }}"></div>
                    <div><label>Booked NB</label><input type="number" step="0.01" name="booked_nb" required value="{{ $editDeal?->booked_nb }}"></div>
                    <div><label>Settled NB</label><input type="number" step="0.01" name="settled_nb" value="{{ $editDeal?->settled_nb }}"></div>
                    <div><label>Developer adjustment</label><input type="number" step="0.01" name="developer_adjustment" value="{{ $editDeal?->developer_adjustment }}"></div>
                    <div><label>Client passback</label><input type="number" step="0.01" name="client_passback" value="{{ $editDeal?->client_passback }}"></div>
                    <div><label>Sub-broker share</label><input type="number" step="0.01" name="sub_broker_share" value="{{ $editDeal?->sub_broker_share }}"></div>
                    <div><label>Referral fee</label><input type="number" step="0.01" name="referral_fee" value="{{ $editDeal?->referral_fee }}"></div>
                    <div><label>Status</label><select name="status"><option value="booked" @selected(($editDeal?->status ?? '')==='booked')>Booked</option><option value="partially_settled" @selected(($editDeal?->status ?? '')==='partially_settled')>Partially Settled</option><option value="settled" @selected(($editDeal?->status ?? '')==='settled')>Settled</option></select></div>
                    @if($editDeal)
                        <div><label>NB revision reason</label><input name="revision_reason" required></div>
                        <div><label>MD/legal authorization reference if outside limit</label><input name="authorization_note"></div>
                    @endif
                    <div class="full"><label>Notes</label><textarea name="notes" rows="3">{{ $editDeal?->notes }}</textarea></div>
                    <div class="full">
                        <button class="inc-btn">{{ $editDeal ? 'Save Deal / Reconcile' : 'Create Deal' }}</button>
                        @if($editDeal)
                            <a class="inc-btn secondary" href="/admin/incentives?tab=deals&year={{ $year }}">Cancel</a>
                        @endif
                    </div>
                </form>
            </div>
        @endif
        <div class="inc-card">
            <h3>Deals</h3>
            <div class="inc-scroll">
                <table class="inc-table">
                    <tr><th>ID</th><th>Agent</th><th>Booking</th><th class="num">Booked NB</th><th class="num">Settled NB</th><th>Status</th><th>Collections</th><th>Action</th></tr>
                    @foreach($deals as $d)
                        @php
                            $collected = (float) $d->collections->sum('amount');
                            $receivable = max(0, (float) $d->effectiveNb() - $collected);
                        @endphp
                        <tr>
                            <td>#{{ $d->id }} @if($d->lead_id)<br><span class="inc-note">Lead #{{ $d->lead_id }}</span>@endif</td>
                            <td>{{ $d->agent?->user?->name }}</td>
                            <td>{{ $d->booking_date?->format('d M Y') }}</td>
                            <td class="num">{{ $money($d->booked_nb) }}</td>
                            <td class="num">{{ $d->settled_nb !== null ? $money($d->settled_nb) : '—' }}</td>
                            <td>{{ ucwords(str_replace('_',' ',$d->status)) }}</td>
                            <td>
                                @if($d->collections->isNotEmpty())
                                    <div class="inc-note" style="margin-bottom:4px">
                                        @foreach($d->collections as $c)
                                            {{ $c->collection_date?->format('d M') }}: {{ $money($c->amount) }}@if(!$loop->last), @endif
                                        @endforeach
                                    </div>
                                    <div class="inc-note" style="margin-bottom:4px">Received: <strong>{{ $money($collected) }}</strong> · Remaining: <strong>{{ $money($receivable) }}</strong></div>
                                @else
                                    <span class="inc-note">No collections</span>
                                @endif
                                @if($canManage)
                                    <form method="POST" action="/admin/incentives/deals/{{ $d->id }}/collections" class="inc-actions">
                                        @csrf
                                        <input type="date" name="collection_date" required value="{{ now()->format('Y-m-d') }}" style="padding:6px;border:1px solid #ddd;border-radius:7px">
                                        <input type="number" step="0.01" min="0.01" name="amount" required placeholder="Amount" style="width:100px;padding:6px;border:1px solid #ddd;border-radius:7px">
                                        <button class="inc-btn">Add</button>
                                    </form>
                                @endif
                            </td>
                            <td>
                                @if($canManage)
                                    <div class="inc-actions">
                                        <a class="inc-btn secondary" href="/admin/incentives?tab=deals&edit_deal={{ $d->id }}&year={{ $year }}">Edit</a>
                                        @if($d->collections->isNotEmpty())
                                            <form method="POST" action="/admin/incentives/deals/{{ $d->id }}/reconcile" onsubmit="return confirm('Reconcile the payout ledger for Deal #{{ $d->id }}? Existing ledger rows are preserved and only the missing/recovery delta is added.');">
                                                @csrf
                                                <button class="inc-btn" type="submit">Reconcile payouts</button>
                                            </form>
                                        @endif
                                    </div>
                                @else
                                    <span class="inc-note">View only</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </table>
            </div>
            {{ $deals->links() }}
        </div>
    @endif

    @if($activeTab==='simulation')
        <div class="inc-context"><a href="#run-simulation">Run simulation</a><a href="/admin/incentives?tab=config&year={{$year}}">Policy rules</a><a href="/admin/incentives?tab=reports&year={{$year}}">Compare with statement</a></div>
        <div class="inc-card"><h3>What-if simulation</h3><p class="inc-note">Simulation does not save anything. Change threshold, payment split and slab parameters, then compare the projected results with the current policy.</p>
            <form method="GET" action="/admin/incentives" class="inc-form"><input type="hidden" name="tab" value="simulation"><input type="hidden" name="year" value="{{ $year }}"><input type="hidden" name="run" value="1"><div><label>From</label><input type="date" name="from" value="{{ request('from',$year.'-01-01') }}"></div><div><label>To</label><input type="date" name="to" value="{{ request('to',$year.'-12-31') }}"></div><div><label>Threshold</label><input type="number" step="0.01" name="threshold_multiple" value="{{ request('threshold_multiple',$cfg['threshold_multiple']) }}"></div><div><label>Provisional release %</label><input type="number" step="0.01" name="paid_percent" value="{{ request('paid_percent',$cfg['paid_percent']) }}"></div><div><label>Held %</label><input type="number" step="0.01" name="held_percent" value="{{ request('held_percent',$cfg['held_percent']) }}"></div>@foreach($cfg['slabs'] as $i=>$s)<div><label>Slab {{ $i }} rate %</label><input type="number" step="0.01" name="slab_rate[{{ $i }}]" value="{{ request('slab_rate.'.$i,$s['rate']) }}"><input type="hidden" name="slab_min[{{ $i }}]" value="{{ $s['min'] }}"><input type="hidden" name="slab_max[{{ $i }}]" value="{{ $s['max'] }}"></div>@endforeach<div class="full"><button class="inc-btn">Run simulation</button></div></form>
        </div>
        @if($simulation)<div class="inc-grid"><div class="inc-card"><div class="inc-label">Current projected incentive</div><div class="inc-kpi">{{ $money($dashboard->sum(fn($r)=>$r['annual']['annual_incentive'])) }}</div></div><div class="inc-card"><div class="inc-label">Proposed projected incentive</div><div class="inc-kpi">{{ $money($simulation['rows']->sum(fn($r)=>$r['calc']['incentive'])) }}</div></div><div class="inc-card"><div class="inc-label">Proposed paid</div><div class="inc-kpi">{{ $money($simulation['rows']->sum(fn($r)=>$r['projected_paid'])) }}</div></div><div class="inc-card"><div class="inc-label">Proposed held</div><div class="inc-kpi">{{ $money($simulation['rows']->sum(fn($r)=>$r['projected_held'])) }}</div></div></div><div class="inc-card"><div class="inc-scroll"><table class="inc-table"><tr><th>Agent</th><th class="num">Current incentive</th><th class="num">Proposed incentive</th><th class="num">Current paid</th><th class="num">Proposed paid</th><th class="num">Proposed held</th></tr>@foreach($simulation['rows'] as $r)<tr><td>{{ $r['agent']->user?->name }}</td><td class="num">@php $currentRow = $dashboard->first(fn($x) => $x['agent']->id === $r['agent']->id); @endphp{{ $money($currentRow['annual']['annual_incentive'] ?? 0) }}</td><td class="num">{{ $money($r['calc']['incentive']) }}</td><td class="num">{{ $money($r['current_paid']) }}</td><td class="num">{{ $money($r['projected_paid']) }}</td><td class="num">{{ $money($r['projected_held']) }}</td></tr>@endforeach</table></div></div>@endif
    @endif

    @if($activeTab==='reports')
        <div class="inc-context"><a href="#eligible">Eligible incentive</a><a href="#salary-used">Salary used</a><a href="#release-ledger">Release / reserve ledger</a><a href="/incentive-guide.html#statements">How to read this</a></div>
        <div class="inc-card"><form method="GET" action="/admin/incentives" class="inc-form"><input type="hidden" name="tab" value="reports"><input type="hidden" name="year" value="{{ $year }}"><div><label>Agent</label><select name="report_agent_id" onchange="this.form.submit()">@foreach($agents as $a)<option value="{{ $a->id }}" @selected($reportAgent?->id===$a->id)>{{ $a->user?->name }}</option>@endforeach</select></div><div><label>Quarter</label><select name="report_quarter" onchange="this.form.submit()">@for($q=1;$q<=4;$q++)<option value="{{ $q }}" @selected($reportQuarter===$q)>Q{{ $q }}</option>@endfor</select></div></form></div>
        @if($reportAgent)
            <div class="inc-grid">
                <div class="inc-card"><div class="inc-label">Eligible incentive — Q{{ $reportQuarter }}</div><div class="inc-kpi">{{ $money($reportQuarterCalc['incentive']) }}</div><div class="inc-note">Surplus × slab rate × 3 eligible months.</div></div>
                <div class="inc-card"><div class="inc-label">{{ $reportAnnualCalc['period_label'] ?? 'Year' }} eligible incentive</div><div class="inc-kpi">{{ $money($reportAnnualCalc['annual_incentive']) }}</div><div class="inc-note">{{ ($reportAnnualCalc['is_ytd'] ?? false) ? 'Uses only completed months through today; not a full-year forecast.' : 'Completed-year calculation.' }}</div></div>
                <div class="inc-card"><div class="inc-label">Provisional release entitlement</div><div class="inc-kpi">{{ $money($reportBalances['paid']) }}</div><div class="inc-note">Ledger entitlement released from collections; not proof of bank/payroll transfer.</div></div>
                <div class="inc-card"><div class="inc-label">Held reserve balance</div><div class="inc-kpi">{{ $money($reportBalances['held_balance']) }}</div><div class="inc-note">25% retained until true-up/authorized settlement.</div></div>
            </div>
            <div class="inc-card" id="eligible">
                <h3>{{ $reportAgent->user?->name }} — Statement</h3>
                <table class="inc-table">
                    <tr><th>Metric</th><th>Quarter</th><th>{{ $reportAnnualCalc['period_label'] ?? 'Year' }}</th></tr>
                    <tr><td>Settled/Booked NB</td><td>{{ $money($reportQuarterCalc['nb']) }}</td><td>{{ $money($reportAnnualCalc['nb']) }}</td></tr>
                    <tr><td>Confirmed months</td><td>{{ $reportQuarterCalc['months'] }}</td><td>{{ $reportAnnualCalc['months'] }}</td></tr>
                    <tr><td>Avg monthly NB</td><td>{{ $money($reportQuarterCalc['average_monthly_nb']) }}</td><td>{{ $money($reportAnnualCalc['average_monthly_nb']) }}</td></tr>
                    <tr id="salary-used"><td>Avg monthly salary used</td><td>{{ $money($reportQuarterCalc['salary']) }}</td><td>{{ $money($reportAnnualCalc['salary']) }}</td></tr>
                    <tr><td>Performance multiple</td><td>{{ number_format($reportQuarterCalc['multiple'],2) }}x</td><td>{{ number_format($reportAnnualCalc['multiple'],2) }}x</td></tr>
                    <tr><td>Threshold ({{ $cfg['threshold_multiple'] }}× salary)</td><td>{{ $money($reportQuarterCalc['threshold']) }}</td><td>{{ $money($reportAnnualCalc['threshold']) }}</td></tr>
                    <tr><td>Slab</td><td>{{ $reportQuarterCalc['slab']['label'] }} / {{ $reportQuarterCalc['slab']['rate'] }}%</td><td>{{ $reportAnnualCalc['slab']['label'] }} / {{ $reportAnnualCalc['slab']['rate'] }}%</td></tr>
                    <tr><td><strong>Surplus</strong></td><td><strong>{{ $money($reportQuarterCalc['surplus']) }}</strong></td><td><strong>{{ $money($reportAnnualCalc['surplus']) }}</strong></td></tr>
                    <tr><td><strong>Eligible incentive</strong></td><td><strong>{{ $money($reportQuarterCalc['incentive']) }}</strong></td><td><strong>{{ $money($reportAnnualCalc['annual_incentive']) }}</strong></td></tr>
                </table>
            </div>
            <div class="inc-card" id="release-ledger">
                <h3>How the payout ledger works</h3>
                <p class="inc-note">A collection creates an incentive entitlement. The current policy splits that earned amount into <strong>75% provisional</strong> and <strong>25% held</strong>. The CRM ledger records the entitlement/reconciliation; it does <strong>not</strong> prove that money was transferred through payroll or bank.</p>
                <div class="inc-grid">
                    <div><strong>Current quarter eligible</strong><br>{{ $money($reportQuarterCalc['incentive']) }}</div>
                    <div><strong>Ledger accrued for {{ $reportQuarterKey }}</strong><br>{{ $money($reportLedgerAccrued) }}</div>
                    <div><strong>Provisional release 75%</strong><br>{{ $money($reportLedgerProvisional) }}</div>
                    <div><strong>Held reserve 25%</strong><br>{{ $money($reportLedgerHeld) }}</div>
                </div>
                @if(abs($reportLedgerVariance) > 0.01)
                    <div style="margin-top:12px;padding:12px;border:1px solid #e0a100;border-radius:8px;background:#fff8e6"><strong>Reconciliation needed:</strong> current calculation differs from the existing ledger by {{ $money(abs($reportLedgerVariance)) }} ({{ $reportLedgerVariance > 0 ? 'current eligible amount is higher' : 'existing ledger is higher' }}). Existing ledger rows are historical audit entries and are not silently rewritten.</div>
                @else
                    <div style="margin-top:12px;padding:12px;border:1px solid #8abf8a;border-radius:8px;background:#f2fff2"><strong>Ledger aligned:</strong> current quarter eligible incentive and accrued ledger agree.</div>
                @endif
            </div>
            <div class="inc-card" id="salary-used"><h3>Incentive release / recovery ledger</h3><div class="inc-note" style="margin-bottom:10px">These entries track incentive entitlement released from collections and the amount held for true-up. They are <strong>not proof of an employee bank/payroll payment</strong>. Actual payment should be recorded separately when that workflow is introduced.</div><div class="inc-scroll"><table class="inc-table"><tr><th>Date</th><th>Period</th><th>Entry</th><th class="num">Amount</th><th>Meaning</th><th>Notes</th></tr>
                @foreach($reportPayouts as $p)
                    @php
                        $labels = [
                            'provisional_paid' => 'Provisional release (75%)',
                            'held_accrual' => 'Held reserve (25%)',
                            'held_release' => 'Held reserve released',
                            'true_up_payment' => 'True-up additional payment',
                            'true_up_recovery' => 'True-up recovery',
                            'recovery' => 'Recovery from held',
                            'recovery_carry' => 'Recovery carried forward',
                            'write_off' => 'Recovery write-off',
                        ];
                        $meaning = [
                            'provisional_paid' => 'Earned entitlement released against collections; payroll transfer is separate.',
                            'held_accrual' => 'Retained until authorized true-up/settlement.',
                            'held_release' => 'Previously held entitlement released at settlement.',
                            'true_up_payment' => 'Additional amount due after final reconciliation.',
                            'true_up_recovery' => 'Final reconciliation recovery.',
                            'recovery' => 'Recovered from held entitlement only.',
                            'recovery_carry' => 'Unrecovered amount carried; never salary deduction.',
                            'write_off' => 'Authorized write-off of recovery carry.',
                        ];
                    @endphp
                    <tr><td>{{ $p->created_at?->format('d M Y H:i') }}</td><td>{{ $p->period_key }}</td><td>{{ $labels[$p->type] ?? ucwords(str_replace('_',' ',$p->type)) }}</td><td class="num">{{ $money($p->amount) }}</td><td>{{ $meaning[$p->type] ?? 'Financial ledger entry.' }}</td><td>{{ $p->notes }}</td></tr>
                @endforeach
            </table></div>{{ method_exists($reportPayouts,'links') ? $reportPayouts->links() : '' }}</div>
        @endif
    @endif
</div>
@endsection
