@php
    $activeWork = $workFollowups->first();
    $action = $activeWork ? app(\App\Services\SettingsService::class)->actionTypeByKey($activeWork->action_type) : null;
    $actionKey = $activeWork?->action_type ?? '';
    $actionLabel = $action?->label ?? ucfirst(str_replace('_', ' ', $actionKey ?: 'Next action'));
    $isCallAction = in_array($actionKey, ['call','followup_call','retry_call','post_visit_call','negotiation_followup','confirm_site_visit','visit_reminder','visit_feedback_call','visit_outcome_call','booking_confirmation','thank_you_call','reactivation_call','verify_contact'], true);
    $isWhatsAppAction = str_contains($actionKey, 'whatsapp');
    $isEmailAction = str_contains($actionKey, 'email');
    $isSendAction = str_contains($actionKey, 'send_') || $isWhatsAppAction || $isEmailAction;
@endphp

<div class="card contact-work-console" style="margin-top:var(--s-3);border:2px solid var(--c-primary);">
    <div class="contact-work-head">
        <div>
            <div class="eyebrow">CALLER WORK</div>
            <h3 style="margin:2px 0 4px;">{{ $activeWork ? 'Do this next' : 'Work status' }}</h3>
            <div class="muted" style="font-size:12px;">The CRM keeps the next required action here until this Contact is converted or reaches a state needing no immediate caller work.</div>
        </div>
        @if ($activeWork)
            <span class="badge {{ $activeWork->isOverdue() ? 'red' : 'orange' }}">{{ $activeWork->isOverdue() ? 'OVERDUE' : ($activeWork->scheduled_for?->isToday() ? 'DUE TODAY' : 'SCHEDULED') }}</span>
        @endif
    </div>

    @if (!$activeWork)
        @if ($contact->isPromoted())
            <div class="contact-work-clear"><strong>✓ Contact work is complete.</strong><span class="muted">This Contact is now a Lead. Continue work from the Lead / My Work system.</span><a class="btn-small btn-info" href="{{ url('/leads/'.$contact->promoted_to_lead_id) }}">Open Lead</a></div>
        @elseif (in_array($contact->status, ['dnc','invalid','not_interested'], true))
            <div class="contact-work-clear"><strong>✓ No immediate caller action.</strong><span class="muted">Status: {{ ucfirst(str_replace('_',' ', $contact->status)) }}. The Contact is intentionally out of the active calling queue unless a later reactivation action is scheduled.</span></div>
        @else
            <div class="contact-work-clear"><strong>Start the work.</strong><span class="muted">No next action is scheduled yet. Make the next call and record its outcome.</span><a class="btn-small btn-info" href="{{ route('contacts.dialer', ['contact_id' => $contact->id, 'fresh' => 1]) }}">📞 Start Call</a></div>
        @endif
    @else
        <div class="contact-work-action-row">
            <div class="contact-work-action-main">
                <div class="contact-work-action-title">{{ $actionLabel }}</div>
                <div class="muted" style="font-size:12px;">Due {{ $activeWork->scheduled_for?->format('d M Y, g:i A') ?? 'now' }} · Priority: {{ ucfirst($activeWork->priority ?: 'normal') }}</div>
                @if ($activeWork->notes)<div class="contact-work-note">{{ $activeWork->notes }}</div>@endif
            </div>
            @if ($isCallAction)
                <a class="btn-small btn-info contact-work-primary" href="{{ route('contacts.dialer', ['contact_id' => $contact->id]) }}">📞 Work this call</a>
            @else
                <div class="contact-work-tools">
                    @if ($isWhatsAppAction || $isSendAction)
                        <a id="contact-work-external-action" class="btn-small" target="_blank" rel="noopener" href="https://wa.me/{{ phone_wa($contact->phone) }}?text={{ urlencode('Hi '.$contact->name.', as discussed, I am sharing the requested details. Please let me know if you need anything else.') }}">💬 Open WhatsApp</a>
                    @endif
                    @if ($isEmailAction || $isSendAction)
                        @if ($contact->email)<a id="contact-work-email-action" class="btn-small" href="mailto:{{ $contact->email }}">✉️ Open Email</a>@endif
                    @endif
                </div>
                @if ($isWhatsAppAction || $isEmailAction || $isSendAction)
                    <div id="contact-work-confirm" class="contact-work-confirm" hidden>
                        <strong>When you return, confirm what actually happened.</strong>
                        <span class="muted">Opening WhatsApp or Email does not prove the message was sent.</span>
                        <div class="contact-work-confirm-actions">
                            <button type="button" id="contact-work-done" class="btn-small btn-info">✓ I completed the action</button>
                            <button type="button" id="contact-work-not-done" class="btn-small">I did not complete it</button>
                        </div>
                    </div>
                @endif
            @endif
        </div>

        @if (!$isCallAction)
            <form method="POST" action="{{ route('contactWork.complete', $activeWork->id) }}" class="contact-work-complete" id="contact-work-result-form" hidden>
                @csrf
                <div class="contact-work-complete-head"><strong>Record what you did and what happened</strong><span class="muted">This closes this action and creates the next action automatically.</span></div>
                <div class="contact-work-grid">
                    <div>
                        <label>Action outcome *</label>
                        <select name="outcome_key" class="input" required>
                            <option value="">— Select result —</option>
                            @foreach ($workOutcomes as $outcome)
                                <option value="{{ $outcome['key'] }}" data-requires-visit="{{ ($outcome['requires_site_visit_datetime'] ?? false) || $outcome['key'] === 'site_visit_scheduled' ? '1' : '0' }}" data-next="{{ $outcome['next_action_label'] ?? '' }}" data-delay="{{ (int) $outcome['next_action_delay_hours'] }}">{{ $outcome['display_label'] }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label>What happened / important details</label>
                        <textarea name="notes" class="input" rows="2" maxlength="2000" placeholder="Record the useful detail so the next caller knows what happened."></textarea>
                    </div>
                </div>
                <div id="contact-site-visit-fields" class="contact-site-visit-fields" hidden>
                    <strong>🏠 Site visit details</strong>
                    <span class="muted">Enter the customer’s actual appointment. This is separate from the next caller reminder.</span>
                    <label>Site visit date & time *</label>
                    <input type="datetime-local" name="site_visit_scheduled_at" class="input" min="{{ now()->addMinutes(15)->format('Y-m-d\TH:i') }}">
                </div>
                <button type="submit" class="btn-small btn-info">✅ Complete action & set next step</button>
            </form>
        @else
            <div class="contact-work-hint">After the call, <strong>do not just leave the Contact on “called.”</strong> Pick the actual call outcome in the dialer. The configured outcome will set the Contact state and create the next required action automatically.</div>
        @endif
    @endif

    @if ($workFollowups->count() > 1)
        <details style="margin-top:12px;"><summary style="cursor:pointer;font-weight:700;">View scheduled work ({{ $workFollowups->count() }})</summary>
            <div class="table-wrap" style="margin-top:8px;"><table><tr><th>When</th><th>Action</th><th>Priority</th></tr>
            @foreach ($workFollowups as $task)
                <tr><td>{{ $task->scheduled_for?->format('d M Y, g:i A') ?? '—' }}</td><td>{{ app(\App\Services\SettingsService::class)->actionTypeByKey($task->action_type)?->label ?? $task->action_type }}</td><td>{{ ucfirst($task->priority ?: 'normal') }}</td></tr>
            @endforeach
            </table></div>
        </details>
    @endif
</div>

<style>
.contact-work-console{background:var(--c-surface)}
.contact-site-visit-fields{margin-top:10px;padding:11px 12px;border:1px solid var(--c-border);border-radius:9px;background:var(--c-surface-2);display:grid;gap:6px}.contact-site-visit-fields[hidden]{display:none!important}.contact-site-visit-fields strong{font-size:13px}.contact-site-visit-fields label{font-size:12px;font-weight:700}
.contact-work-head{display:flex;justify-content:space-between;align-items:flex-start;gap:12px}
.contact-work-action-row{display:flex;justify-content:space-between;align-items:center;gap:14px;margin-top:14px;padding:14px;border-radius:10px;background:var(--c-surface-2)}
.contact-work-action-main{min-width:0;flex:1}.contact-work-action-title{font-size:18px;font-weight:800}.contact-work-note{margin-top:6px;padding:7px 9px;border-left:3px solid var(--c-primary);font-size:12px}
.contact-work-tools{display:flex;gap:7px;flex-wrap:wrap;justify-content:flex-end}.contact-work-confirm{margin-top:10px;padding:10px 12px;border:1px solid var(--c-border);border-radius:9px;background:var(--c-surface-2);font-size:12px}.contact-work-confirm-actions{display:flex;gap:7px;flex-wrap:wrap;margin-top:9px}.contact-work-confirm[hidden],.contact-work-result-form[hidden]{display:none!important}.contact-work-complete{margin-top:12px;padding:13px;border:1px solid var(--c-border);border-radius:10px}.contact-work-complete-head{display:flex;flex-direction:column;gap:3px;margin-bottom:10px}.contact-work-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:10px}.contact-work-clear{display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-top:12px;padding:14px;background:var(--c-surface-2);border-radius:10px}.contact-work-hint{margin-top:12px;padding:10px 12px;border-left:3px solid var(--c-primary);background:var(--c-surface-2);font-size:12px}
@media(max-width:767px){.contact-work-confirm-actions{display:grid;grid-template-columns:1fr}.contact-work-confirm-actions .btn-small{width:100%;text-align:center}.contact-work-head{display:block}.contact-work-head .badge{display:inline-block;margin-top:8px}.contact-work-action-row{display:block}.contact-work-primary{display:block;text-align:center;margin-top:12px}.contact-work-tools{justify-content:flex-start;margin-top:10px}.contact-work-tools .btn-small{flex:1;text-align:center}.contact-work-grid{grid-template-columns:1fr}.contact-work-clear{display:block}.contact-work-clear .btn-small{display:block;text-align:center;margin-top:10px}}
</style>

<script>
(function(){
    const openAction=document.getElementById('contact-work-external-action');
    const emailAction=document.getElementById('contact-work-email-action');
    const confirmBox=document.getElementById('contact-work-confirm');
    const resultForm=document.getElementById('contact-work-result-form');
    const outcomeSelect=resultForm?.querySelector('select[name=outcome_key]');
    const visitFields=document.getElementById('contact-site-visit-fields');
    const visitInput=visitFields?.querySelector('input[name=site_visit_scheduled_at]');
    function syncVisit(){
        const opt=outcomeSelect?.options[outcomeSelect.selectedIndex];
        const needs=opt?.dataset.requiresVisit==='1';
        if(visitFields) visitFields.hidden=!needs;
        if(visitInput) visitInput.required=needs;
    }
    outcomeSelect?.addEventListener('change',syncVisit);
    syncVisit();
    const doneBtn=document.getElementById('contact-work-done');
    const notDoneBtn=document.getElementById('contact-work-not-done');
    if(!confirmBox || !resultForm) return;
    let opened=false;
    const returnKey='crm_contact_external_action_return_pending_{{ $contact->id }}';
    const refreshKey='crm_contact_external_action_last_refresh_{{ $contact->id }}';
    function showConfirm(){
        if(!opened) return;
        confirmBox.hidden=false;
        resultForm.hidden=true;
        confirmBox.scrollIntoView({behavior:'smooth',block:'nearest'});
    }
    function markOpened(){
        opened=true;
        sessionStorage.setItem(returnKey,'1');
        showConfirm();
    }
    [openAction,emailAction].filter(Boolean).forEach(function(el){
        el.addEventListener('click',function(){
            opened=true;
            sessionStorage.setItem(returnKey,'1');
            setTimeout(showConfirm,350);
        });
    });
    doneBtn?.addEventListener('click',function(){
        sessionStorage.removeItem(returnKey);
        confirmBox.hidden=true;
        resultForm.hidden=false;
        resultForm.scrollIntoView({behavior:'smooth',block:'nearest'});
    });
    notDoneBtn?.addEventListener('click',function(){
        sessionStorage.removeItem(returnKey);
        confirmBox.hidden=true;
        resultForm.hidden=true;
        const note=document.createElement('div');
        note.className='contact-work-hint';
        note.textContent='The action remains open. Nothing was recorded as completed, so this Contact will stay in your work until you complete it.';
        confirmBox.parentNode.insertBefore(note,confirmBox.nextSibling);
    });
    function refreshAfterExternalReturn(){
        if(document.visibilityState!=='visible') return;
        if(sessionStorage.getItem(returnKey)!=='1') return;
        const lastRefresh=parseInt(sessionStorage.getItem(refreshKey)||'0',10);
        if(Date.now()-lastRefresh<3000){
            opened=true;
            showConfirm();
            return;
        }
        sessionStorage.setItem(refreshKey,String(Date.now()));
        window.location.reload();
    }
    document.addEventListener('visibilitychange',function(){
        if(document.visibilityState==='visible' && sessionStorage.getItem(returnKey)==='1') refreshAfterExternalReturn();
    });
    window.addEventListener('focus',refreshAfterExternalReturn);
    if(sessionStorage.getItem(returnKey)==='1'){
        opened=true;
        showConfirm();
    }
})();
</script>
