@php
    $settings = app(\App\Services\SettingsService::class);

    $activityTypes = $settings->activityTypes(true, true);
    $allOutcomes   = $settings->callOutcomes(true);
    $presentedOutcomes = app(\App\Services\WorkflowPresentationService::class)
        ->presentOutcomes($allOutcomes, (int) session("user_id"));
    $allStatuses   = $settings->statuses();

    // System-generated activity types must not appear in the manual
    // "What did you do?" dropdown for normal tasks.
    // BUT: when this task is a check-back, we force the correct type
    // below — so we keep those in the list and filter at render time.
        $systemTypes = [
        'lead_shared',
        'lead_reassigned',
        'status_change',
        'shared_agent_report',
        'site_team_report',
        'external_share',
    ];

    $requiresOutcomeMap = $activityTypes->pluck('requires_outcome', 'key')->toArray();

    $leadStatusKey         = $lead->statusKey();
    $allowedTransitionKeys = $settings->allowedTransitionsFrom($leadStatusKey);

    $statusesById        = $allStatuses->pluck('key',   'id')->toArray();
    $statusesByKey       = $allStatuses->pluck('label', 'key')->toArray();
    $requiresDateById    = $allStatuses->pluck('requires_datetime', 'id')->toArray();
    $requiresBookingById = $allStatuses->pluck('requires_booking_details', 'id')->toArray();

    /*
     * All outcomes are sent to the browser. The JS filters them
     * by activity type (channel) AND by lead stage, so agents only
     * see outcomes that make sense right now.
     */
    $outcomesJson = $presentedOutcomes->map(function (array $o) {
        return [
            'key'              => $o['key'],
            'label'            => $o['display_label'],
            'category'         => $o['category'],
            'activity_filter'  => $o['activity_type_filter'],
            'next'             => $o['next_action_label'],
            'delay'            => $o['next_action_delay_hours'],
            'suggested_id'     => $o['suggested_status_id'],
            'prompts_wa'       => $o['prompts_whatsapp_send'],
        ];
    })->values();

    // Default activity type for this task
    $taskAction  = $followup->action_type ?? '';
    $defaultType = 'call';
    if (str_contains($taskAction, 'whatsapp'))       $defaultType = 'whatsapp';
    elseif (str_contains($taskAction, 'email'))      $defaultType = 'email';
    elseif (str_contains($taskAction, 'meeting'))    $defaultType = 'meeting';
    elseif (str_contains($taskAction, 'site_visit')) $defaultType = 'site_visit';
    elseif (str_contains($taskAction, 'note'))       $defaultType = 'note';

    // ----- Share check-back tasks: force the activity type -----
    $isCheckBack = in_array($taskAction, ['check_shared_agent', 'check_site_team'], true);
    $forcedType  = null;
    if ($taskAction === 'check_shared_agent') $forcedType = 'shared_agent_report';
    if ($taskAction === 'check_site_team')    $forcedType = 'site_team_report';

    if ($forcedType) {
        // Check-back tasks: force the report type — no user choice.
        $activityTypes = $activityTypes->where('key', $forcedType);
        $defaultType   = $forcedType;
    } else {
        // Normal tasks: hide every system-only type from the dropdown.
        $activityTypes = $activityTypes->reject(
            fn ($t) => in_array($t->key, $systemTypes, true)
        )->values();
    }

    $actionModel = $settings->actionTypeByKey($taskAction);
    $actionLabel = $actionModel?->label ?? $taskAction;
    $isOverdue   = $followup->scheduled_for && now()->greaterThan($followup->scheduled_for);

    $defaultBrokeragePct = $settings->get('default_brokerage_percentage', 2);
    $defaultBrokerageExpected = now()->addDays(30)->toDateString();

    // Default visit datetime → tomorrow 11:00 AM (app timezone)
    $defaultVisitAt = now()->addDay()->setTime(11, 0)->format('Y-m-d\TH:i');

    // Earliest allowed visit datetime → 15 minutes from now (form min attribute)
    $minVisitAt = now()->addMinutes(15)->format('Y-m-d\TH:i');

    $lostReasonOptions = app(\App\Services\WorkflowPresentationService::class)->presentLostReasons((int) session("user_id"));
@endphp


<form method="POST" action="/followups/complete" data-ajax id="ct-form" class="ct-mobile-form">
    @csrf
    <input type="hidden" name="followup_id" value="{{ $followup->id }}">
    <input type="hidden" name="type" id="ct-activity-type" value="{{ $defaultType }}">
    @if (request()->boolean('allow_early'))
        <input type="hidden" name="allow_early" value="1">
    @endif
    @if (request()->filled('return_to'))
        <input type="hidden" name="return_to" value="{{ request('return_to') }}">
    @endif

    <div class="ct-task-strip">
        <div class="ct-task-strip-main">
            <span class="ct-task-label">{{ $actionLabel }}</span>
            <span class="ct-task-time {{ $isOverdue ? 'is-overdue' : '' }}">
                {{ $isOverdue ? 'Overdue · ' . $followup->scheduled_for?->diffForHumans(null, true) : ($followup->scheduled_for ? $followup->scheduled_for->diffForHumans() : 'Due now') }}
            </span>
        </div>
        <span class="ct-task-state">{{ $isOverdue ? 'ACT NOW' : 'COMPLETE' }}</span>
    </div>

    @if ($activityTypes->count() > 0)
        <div class="ct-step">
            <div class="ct-label-row"><label for="ct-activity-picker">How did you contact them?</label><span>Required</span></div>
            <select id="ct-activity-picker" class="input ct-select" aria-label="Contact method">
                @foreach ($activityTypes as $t)
                    <option value="{{ $t->key }}" @selected($t->key === $defaultType)>{{ $t->label }}</option>
                @endforeach
            </select>
        </div>
    @endif

    <div class="ct-step" id="ct-outcome-field" style="display:{{ in_array($defaultType, ['call','whatsapp','email','site_visit'], true) ? 'block' : 'none' }};" data-default-required="{{ !empty($requiresOutcomeMap[$defaultType]) ? '1' : '0' }}">
        <div class="ct-label-row"><label for="ct-outcome-select">Outcome</label><span>Required</span></div>
        <select name="outcome_key" id="ct-outcome-select" class="input ct-select">
            <option value="">Select outcome…</option>
        </select>
        <div id="ct-outcome-preview" class="ct-next-preview" hidden></div>
        <div id="ct-outcome-toggle-wrap" hidden>
            <button type="button" id="ct-outcome-toggle" class="ct-text-button">Show other outcomes</button>
        </div>
    </div>

    <div class="ct-step" id="ct-visit-field" style="display:none;">
        <div class="ct-label-row"><label for="ct-visit-at">Visit date &amp; time</label><span>Required</span></div>
        <input type="datetime-local" name="visit_scheduled_at" id="ct-visit-at" class="input" value="{{ $defaultVisitAt }}" min="{{ $minVisitAt }}">
    </div>

    <div class="ct-step" id="ct-lost-reason-field" style="display:none;">
        <div class="ct-label-row"><label for="ct-lost-reason">Lost reason</label><span>Required</span></div>
        <select name="lost_reason_key" id="ct-lost-reason" class="input ct-select">
            <option value="">Select reason…</option>
            @foreach ($lostReasonOptions as $groupKey => $groupOptions)
                <optgroup label="{{ $groupKey === 'nurture' ? 'Can be reactivated later' : 'Permanently closed' }}">
                    @foreach ($groupOptions as $reason)
                        <option value="{{ $reason['key'] }}">{{ $reason['display_label'] }}</option>
                    @endforeach
                </optgroup>
            @endforeach
        </select>
    </div>

    <div class="ct-step ct-booking-step" id="ct-booking-fields" style="display:none;">
        <div class="ct-section-title">Booking</div>
        <div class="ct-grid-2">
            <div><label for="ct-booking-amount">Value (₹)</label><input type="number" name="booking_amount" class="input" id="ct-booking-amount" min="0" step="0.01" inputmode="decimal"></div>
            <div><label for="ct-area">Area (sq ft)</label><input type="number" name="property_area_sqft" class="input" id="ct-area" min="0" step="0.01" inputmode="decimal"></div>
        </div>
        <div class="ct-grid-2">
            <div><label for="ct-rate">Rate / sq ft</label><input type="number" name="rate_per_sqft" class="input" id="ct-rate" min="0" step="0.01" inputmode="decimal"></div>
            <div><label for="ct-booking-unit">Unit / Flat</label><input type="text" name="booking_unit" class="input" id="ct-booking-unit" maxlength="100"></div>
        </div>
        <div class="ct-grid-2">
            <div><label for="ct-payment-mode">Payment</label>
                <select name="booking_payment_mode" id="ct-payment-mode" class="input ct-select">
                    <option value="">Select…</option><option value="Cheque">Cheque</option><option value="NEFT">NEFT</option><option value="RTGS">RTGS</option><option value="UPI">UPI</option><option value="Cash">Cash</option>
                </select>
            </div>
            <div><label for="ct-booking-date">Booking date</label><input type="date" name="booking_date" id="ct-booking-date" class="input" value="{{ now()->toDateString() }}"></div>
        </div>
        <details class="ct-optional-details">
            <summary>Brokerage details <span>Optional</span></summary>
            <div class="ct-grid-2">
                <div><label for="ct-pct">Brokerage %</label><input type="number" name="brokerage_percentage" class="input" id="ct-pct" min="0" max="100" step="0.01" value="{{ $defaultBrokeragePct }}" inputmode="decimal"></div>
                <div><label for="ct-brok">Brokerage ₹</label><input type="number" name="brokerage_amount" class="input" id="ct-brok" min="0" step="0.01" inputmode="decimal"></div>
            </div>
            <div class="ct-grid-2">
                <div><label for="ct-brok-date">Expected receipt</label><input type="date" name="brokerage_expected_at" id="ct-brok-date" class="input" value="{{ $defaultBrokerageExpected }}"></div>
                <div><label for="ct-co-broker">Co-broker</label><input type="text" name="co_broker_name" id="ct-co-broker" class="input" maxlength="100"></div>
            </div>
        </details>
    </div>

    <div class="ct-step" id="ct-wa-field" style="display:none;">
        <label class="ct-check-row"><input type="checkbox" name="also_whatsapp" id="ct-wa-cb" value="1" checked><span>Send details on WhatsApp too</span></label>
    </div>

    <div class="ct-step" id="ct-custom-time-wrap">
        <div class="ct-label-row"><label for="ct-custom-next-at">Next action date &amp; time</label><span class="ct-optional">Optional — override the automatic follow-up time</span></div>
        <input type="datetime-local" name="custom_next_at" id="ct-custom-next-at" class="input" min="{{ $minVisitAt }}">
    </div>

    <div class="ct-step ct-notes-step">
        <label for="ct-notes">Note <span class="ct-optional">Optional</span></label>
        <textarea name="notes" id="ct-notes" rows="3" class="input" maxlength="2000" placeholder="Key point from the conversation…"></textarea>
    </div>

    <div class="ct-save-bar">
        <button type="submit" class="btn btn-block ct-save-btn" id="ct-save-btn">✓ Complete task</button>
    </div>
</form>

<style>
.ct-mobile-form{padding-bottom:4px}.ct-task-strip{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:10px 12px;margin:0 0 12px;background:var(--c-surface-2);border:1px solid var(--c-border-2);border-radius:10px}.ct-task-strip-main{min-width:0}.ct-task-label{display:block;font-weight:800;font-size:14px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.ct-task-time{display:block;color:var(--c-muted);font-size:12px;margin-top:2px}.ct-task-time.is-overdue{color:var(--c-danger);font-weight:700}.ct-task-state{font-size:10px;font-weight:800;letter-spacing:.06em;white-space:nowrap}.ct-step{margin-bottom:13px}.ct-label-row{display:flex;justify-content:space-between;gap:8px;align-items:center;margin-bottom:6px}.ct-label-row label,.ct-step>label,.ct-grid-2 label{font-size:12px;font-weight:750}.ct-label-row span,.ct-optional{font-size:10px;color:var(--c-muted);font-weight:600}.ct-channel-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:7px}.ct-channel{min-height:42px;border:1px solid var(--c-border);border-radius:9px;background:var(--c-surface);padding:8px 10px;display:flex;align-items:center;gap:7px;text-align:left;font:inherit;color:var(--c-text);cursor:pointer}.ct-channel.is-selected{border-color:var(--c-primary);box-shadow:0 0 0 2px color-mix(in srgb,var(--c-primary) 15%,transparent);background:var(--c-surface-2)}.ct-channel strong{font-size:12px}.ct-select,.ct-mobile-form input,.ct-mobile-form textarea{font-size:16px;min-height:44px}.ct-select{width:100%}.ct-next-preview{margin-top:6px;padding:7px 9px;border-radius:7px;background:var(--c-surface-2);font-size:11px;color:var(--c-muted)}.ct-text-button{border:0;background:none;padding:4px 0;color:var(--c-primary);font:600 11px inherit;cursor:pointer}.ct-grid-2{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:9px}.ct-grid-2>div{min-width:0}.ct-grid-2 label{display:block;margin-bottom:5px}.ct-section-title{font-size:13px;font-weight:800;margin-bottom:8px}.ct-optional-details{border-top:1px dashed var(--c-border);margin-top:3px;padding-top:9px}.ct-optional-details summary{font-size:12px;font-weight:750;cursor:pointer;display:flex;justify-content:space-between}.ct-optional-details summary span{font-size:10px;color:var(--c-muted);font-weight:600}.ct-check-row{display:flex!important;align-items:center;gap:8px;min-height:42px;padding:8px 10px;border:1px solid var(--c-border-2);border-radius:9px;background:var(--c-surface-2);cursor:pointer}.ct-check-row input{width:18px;min-height:18px}.ct-notes-step textarea{resize:vertical;min-height:74px}.ct-save-bar{position:sticky;bottom:0;padding:10px 0 calc(4px + env(safe-area-inset-bottom));background:linear-gradient(var(--c-surface),var(--c-surface));z-index:3}.ct-save-btn{min-height:48px;font-size:15px;font-weight:800}.modal-root-task-open .modal-body{scroll-padding-bottom:140px}.modal-root-task-open .modal-body input,.modal-root-task-open .modal-body select,.modal-root-task-open .modal-body textarea{scroll-margin-top:70px;scroll-margin-bottom:150px}@media(max-width:420px){.ct-channel-grid{grid-template-columns:1fr}.ct-grid-2{grid-template-columns:1fr 1fr;gap:6px}.ct-task-strip{padding:9px 10px}.ct-mobile-form .input{padding-left:10px;padding-right:10px}}
</style>

<script>
(function(){
    'use strict';
    var requiresMap=@json($requiresOutcomeMap), statusesById=@json($statusesById), statusesByKey=@json($statusesByKey), requiresDateById=@json($requiresDateById), requiresBookingById=@json($requiresBookingById), allowedKeys=@json($allowedTransitionKeys), ALL_OUTCOMES=@json($outcomesJson), LEAD_STATUS=@json($leadStatusKey);
    var form=document.getElementById('ct-form'), typeSel=document.getElementById('ct-activity-type'), activityPicker=document.getElementById('ct-activity-picker'), outcomeF=document.getElementById('ct-outcome-field'), outcomeSel=document.getElementById('ct-outcome-select'), preview=document.getElementById('ct-outcome-preview'), visitF=document.getElementById('ct-visit-field'), visitAt=document.getElementById('ct-visit-at'), lostF=document.getElementById('ct-lost-reason-field'), lostSel=document.getElementById('ct-lost-reason'), bookingF=document.getElementById('ct-booking-fields'), bookingAmt=document.getElementById('ct-booking-amount'), waF=document.getElementById('ct-wa-field'), waCb=document.getElementById('ct-wa-cb'), customWrap=document.getElementById('ct-custom-time-wrap'), customAt=document.getElementById('ct-custom-next-at'), areaIn=document.getElementById('ct-area'), rateIn=document.getElementById('ct-rate'), pctIn=document.getElementById('ct-pct'), brokIn=document.getElementById('ct-brok'), toggleWrap=document.getElementById('ct-outcome-toggle-wrap'), toggle=document.getElementById('ct-outcome-toggle');
    var UNIVERSAL=['not_answered','busy','call_later','not_reachable','switched_off','wrong_number','invalid_number','network_issue','customer_hung_up','family_member_answered','language_barrier','not_interested','already_bought','deal_closed_elsewhere','wants_rental','wants_resale','wants_ready_possession','not_ready_to_buy','consult_family','budget_issue','location_issue','interested','asked_details','wa_not_interested','wa_blocked','thanks_referral_promised','already_visited_project'];
    var STATUS_OUTCOMES={'new':['customer_will_call_back','requested_whatsapp_only','site_visit_scheduled'],'attempted':['customer_will_call_back','requested_whatsapp_only','site_visit_scheduled','wants_second_visit','wa_sent_details','wa_delivered_awaiting','wa_read_no_reply','wa_replied_positive'],'external_shared':['customer_will_call_back','requested_whatsapp_only','site_visit_scheduled','wants_second_visit','wa_sent_details','wa_delivered_awaiting','wa_read_no_reply','wa_replied_positive','shared_agent_contacted','site_team_call_done','site_team_visit_done','site_team_negotiating','site_team_booked','site_team_lost'],'contacted':['customer_will_call_back','requested_whatsapp_only','site_visit_scheduled','wants_second_visit','wa_sent_details','wa_delivered_awaiting','wa_read_no_reply','wa_replied_positive'],'visit_scheduled':['visit_booked_spot','visit_interested','visit_needs_family','visit_wants_negotiate','visit_wants_other_project','visit_not_interested','visit_no_show','site_visit_with_family','site_visit_arrived_late','site_visit_cancelled','wants_second_visit','site_visit_scheduled','wa_delivered_awaiting','wa_read_no_reply','wa_replied_positive'],'visit_done':['visit_interested','visit_no_show','visit_booked_spot','visit_not_interested','visit_wants_negotiate','visit_wants_other_project','visit_needs_family','site_visit_with_family','site_visit_arrived_late','wants_second_visit'],'negotiation':['negotiating','booking_confirmed','booking_postponed','loan_denied','loan_in_process','site_visit_with_family','visit_wants_negotiate'],'booking':['booking_amount_paid','booking_kyc_pending','booking_registration_done','brok_received_full','thanks_happy','thanks_referral_won'],'lost':[]};
    var SITE_VISIT_OUTCOMES=['visit_booked_spot','visit_interested','visit_needs_family','visit_wants_negotiate','visit_wants_other_project','visit_not_interested','visit_no_show','site_visit_with_family','site_visit_arrived_late','site_visit_cancelled','wants_second_visit'];
    var showAll=false;
    function relevant(){var a=UNIVERSAL.slice(), s=STATUS_OUTCOMES[LEAD_STATUS]||[]; if(typeSel.value==='site_visit')a=a.concat(SITE_VISIT_OUTCOMES); return a.concat(s)}
    function reset(){preview.hidden=true;preview.textContent='';visitF.style.display='none';visitAt.required=false;lostF.style.display='none';lostSel.required=false;bookingF.style.display='none';bookingAmt.required=false;waF.style.display='none';waCb.checked=true;customWrap.hidden=false;customAt.required=false}
    function option(o){var x=document.createElement('option');x.value=o.key;x.textContent=o.label;x.dataset.next=o.next||'';x.dataset.delay=o.delay||0;x.dataset.suggestedId=o.suggested_id||'';x.dataset.promptsWa=o.prompts_wa?'1':'0';return x}
    function rebuild(){var candidates=ALL_OUTCOMES.filter(function(o){var f=String(o.activity_filter||'').trim(); if(typeSel.value==='site_visit'&&SITE_VISIT_OUTCOMES.indexOf(o.key)!==-1)return true; if(!f)return true; return f.split(',').map(function(s){return s.trim()}).indexOf(typeSel.value)!==-1});var rel=[],other=[], keys=relevant();candidates.forEach(function(o){(showAll||keys.indexOf(o.key)!==-1?rel:other).push(o)});if(!rel.length&&other.length){rel=other;other=[]}outcomeSel.innerHTML='<option value="">Select outcome…</option>';var groups={positive:[],neutral:[],negative:[]};rel.forEach(function(o){if(groups[o.category])groups[o.category].push(o)});['positive','neutral','negative'].forEach(function(c){if(!groups[c].length)return;var g=document.createElement('optgroup');g.label=c.charAt(0).toUpperCase()+c.slice(1);groups[c].forEach(function(o){g.appendChild(option(o))});outcomeSel.appendChild(g)});toggleWrap.hidden=other.length===0;if(toggle)toggle.textContent=showAll?'Show relevant outcomes':'Show other outcomes'}
    function onType(){reset();rebuild();var req=!!requiresMap[typeSel.value] || ['call','whatsapp','email','site_visit'].indexOf(typeSel.value)!==-1;outcomeF.style.display=req?'block':'none'; if(!req){preview.hidden=true} syncChannels(); if(req && outcomeSel.options.length<=1){outcomeF.style.display='block'}}
    function onOutcome(){
        reset();
        var o=outcomeSel.options[outcomeSel.selectedIndex];
        if(!o||!o.value)return;
        var next=o.dataset.next||'';
        var delay=parseInt(o.dataset.delay||'0',10);
        var human=delay<24?delay+'h':Math.round(delay/24)+'d';
        var sid=o.dataset.suggestedId||'';
        var wa=o.dataset.promptsWa==='1';
        var txt=next?'Next: '+next+(delay?' · '+human:''):'';
        if(sid&&statusesById[sid]){
            var key=statusesById[sid], can=allowedKeys.indexOf(key)!==-1;
            if(key==='lost'){
                lostF.style.display='block';
                lostSel.required=true;
            }
            if(can){
                if(requiresDateById[sid]){
                    visitF.style.display='block';
                    visitAt.required=true;
                }
                if(requiresBookingById[sid]){
                    bookingF.style.display='block';
                    bookingAmt.required=false;
                }
                if(key!=='lost'&&key!=='booking'&&key!=='visit_scheduled'&&next){
                    customWrap.hidden=false;
                }
            }
        } else if(next){
            customWrap.hidden=false;
        }
        if(wa){
            waF.style.display='block';
            waCb.checked=true;
        }
        if(txt){
            preview.textContent=txt;
            preview.hidden=false;
        }
    }
    function syncChannels(){if(activityPicker && activityPicker.value!==typeSel.value)activityPicker.value=typeSel.value}
    function calc(){var a=parseFloat(areaIn?.value||'0'),r=parseFloat(rateIn?.value||'0');if(a&&r)bookingAmt.value=Math.round(a*r*100)/100;var v=parseFloat(bookingAmt?.value||'0'),p=parseFloat(pctIn?.value||'0');if(v&&p)brokIn.value=Math.round(v*p/100*100)/100}
    if(activityPicker)activityPicker.addEventListener('change',function(){typeSel.value=activityPicker.value;onType();var target=outcomeF.style.display!=='none'?outcomeSel:activityPicker;target?.scrollIntoView({behavior:'smooth',block:'center'})});
    typeSel.addEventListener('change',onType);outcomeSel.addEventListener('change',function(){onOutcome();if(outcomeSel.value)window.setTimeout(function(){var n=lostF.style.display!=='none'?lostSel:(visitF.style.display!=='none'?visitAt:(bookingF.style.display!=='none'?bookingAmt:document.getElementById('ct-notes')));if(n)n.scrollIntoView({behavior:'smooth',block:'center'})},80)});
    if(toggle)toggle.addEventListener('click',function(){showAll=!showAll;rebuild()});
    [areaIn,rateIn,bookingAmt,pctIn].forEach(function(x){if(x)x.addEventListener('input',calc)});
    if(activityPicker)syncChannels();
    if(form)form.addEventListener('submit',function(e){
        if(visitF.style.display!=='none'&&!visitAt.value){e.preventDefault();visitAt.focus();return false}
        if(bookingF.style.display!=='none' && !bookingAmt.value && !(areaIn.value && rateIn.value)){
            e.preventDefault();
            bookingAmt.classList.add('has-error');
            bookingAmt.scrollIntoView({behavior:'smooth',block:'center'});
            setTimeout(function(){bookingAmt.focus({preventScroll:true})},120);
            return false;
        }
    },true);
    onType();
})();
</script>
