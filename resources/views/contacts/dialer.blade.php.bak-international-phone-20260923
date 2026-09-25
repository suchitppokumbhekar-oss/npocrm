@extends('layouts.app')

@section('title', 'Calling Work — NPO CRM')

@section('content')

<a href="{{ url('/contacts') }}" class="back-link">← Back to contacts</a>

@if (!$selectedContactId)
    <div class="calling-hero">
        <div>
            <div class="eyebrow">CALLER WORK</div>
            <h1>📞 Today’s calling work</h1>
            <p>Do the work that is already due first. When it is finished, start fresh contacts.</p>
        </div>
        <a href="{{ url('/contacts/dialer?fresh=1') }}" class="btn-small btn-info">▶ Start fresh calls</a>
    </div>

    @if ($unfinishedCalls->isNotEmpty() || $dueFollowups->isNotEmpty())
        <div class="card calling-work-queue">
            <div class="section-head" style="margin-bottom:var(--s-2);">
                <div>
                    <h2 style="margin:0;">⚠️ Needs attention now</h2>
                    <p class="muted" style="margin:4px 0 0;font-size:12px;">Finish these before taking a new contact.</p>
                </div>
                <span class="badge orange">{{ $unfinishedCalls->count() + $dueFollowups->count() }} items</span>
            </div>

            @foreach ($unfinishedCalls as $session)
                @if ($session->contact)
                    <a class="calling-work-item urgent" href="{{ url('/contacts/dialer?contact_id='.$session->contact->id) }}">
                        <span class="calling-work-icon">☎</span>
                        <span class="calling-work-main">
                            <strong>{{ $session->contact->name }}</strong>
                            <span>{{ $session->contact->phone }} · Call started {{ $session->started_at?->diffForHumans() }}</span>
                        </span>
                        <span class="calling-work-action">Finish call →</span>
                    </a>
                @endif
            @endforeach

            @foreach ($dueFollowups as $followup)
                @if ($followup->contact)
                    <a class="calling-work-item" href="{{ url('/contacts/dialer?contact_id='.$followup->contact->id) }}">
                        <span class="calling-work-icon">🔁</span>
                        <span class="calling-work-main">
                            <strong>{{ $followup->contact->name }}</strong>
                            <span>{{ $followup->contact->phone }} · {{ $followup->isOverdue() ? 'Overdue' : 'Due today' }} · {{ $followup->contact->project?->name ?? 'No project' }}</span>
                        </span>
                        <span class="calling-work-action">Call →</span>
                    </a>
                @endif
            @endforeach
        </div>
    @else
        <div class="card calling-clear-state">
            <strong>✅ Nothing is waiting from an earlier call.</strong>
            <span class="muted">You can start working the fresh contact list.</span>
            <a href="{{ url('/contacts/dialer?fresh=1') }}" class="btn-small btn-info">Start calling</a>
        </div>
    @endif

    <div class="card calling-secondary">
        <div class="section-head">
            <div>
                <h3 style="margin:0;">Fresh calling list</h3>
                <p class="muted" style="margin:4px 0 0;font-size:12px;">Fresh contacts are available after today’s existing work is cleared.</p>
            </div>
            <a href="{{ url('/contacts/dialer?fresh=1') }}" class="btn-small btn-info">Open list</a>
        </div>
    </div>
@else
    @php $contact = $queue->first(); @endphp
    <div class="card calling-current-head">
        <a href="{{ url('/contacts/dialer') }}" class="back-link" style="margin-bottom:8px;">← Back to today’s work</a>
        <div class="calling-current-meta">
            <div>
                <div class="eyebrow">{{ $contact?->isPromoted() ? 'CONVERTED' : 'CALL NOW' }}</div>
                <h1 style="margin:2px 0 4px;">{{ $contact?->name ?? 'Contact unavailable' }}</h1>
                <div class="muted">{{ $contact?->project?->name ?? 'No pitch project' }} · {{ $contact?->attempts ?? 0 }} previous attempt{{ ($contact?->attempts ?? 0) === 1 ? '' : 's' }}</div>
            </div>
            @if ($contact)
                <span class="badge {{ $contact->status === 'interested' ? 'green' : 'blue' }}">{{ ucfirst(str_replace('_',' ', $contact->status)) }}</span>
            @endif
        </div>
    </div>

    @if (!$contact)
        <div class="card"><p class="muted">This contact is no longer available in your calling scope. Return to today’s work.</p></div>
    @else
        @php
            $queueJson = collect([$contact])->map(fn ($c) => [
                'id' => $c->id,
                'name' => $c->name,
                'phone' => $c->phone,
                'project' => $c->project?->name,
                'attempts' => $c->attempts,
            ])->toJson();
            $connectedOutcomes = $outcomes->filter(fn ($o) => (bool) $o['is_connected'])->values();
            $noConnectionOutcomes = $outcomes->filter(fn ($o) => ! (bool) $o['is_connected'])->values();
            $outcomesJson = $outcomes->map(fn ($o) => [
                'key' => $o['key'],
                'label' => $o['display_label'],
                'connected' => (bool) $o['is_connected'],
                'requires_visit_datetime' => (bool) ($o['requires_site_visit_datetime'] ?? false) || $o['key'] === 'site_visit_scheduled' || in_array((string) ($o['next_action_anchor'] ?? 'now'), ['visit_before', 'visit_after'], true),
                'next_action_anchor' => (string) ($o['next_action_anchor'] ?? 'now'),
            ])->values()->toJson();
        @endphp

        <div id="dialer-root" class="card" data-outcomes="{{ $outcomesJson }}" data-start-url="{{ url('/contacts/'.$contact->id.'/call-start') }}">
            <div id="current-contact" data-queue="{{ $queueJson }}">
                <div class="calling-phone-row">
                    <div>
                        <div class="muted" style="font-size:12px;">Customer phone</div>
                        <a href="tel:{{ $contact->phone }}" id="contact-phone-link" class="calling-phone">{{ $contact->phone }}</a>
                    </div>
                    <button type="button" id="dial-button" class="btn-small btn-info calling-dial-button">📞 Call customer</button>
                </div>

                <div id="unfinished-warning" class="alert alert-warning" style="display:{{ $selectedUnfinishedCall ? 'block' : 'none' }};margin-top:var(--s-3);">
                    ⚠️ This call was started earlier but no outcome was recorded. Record the result now so it does not remain on your work list.
                </div>

                <hr style="border:0;border-top:1px dashed var(--c-border);margin:var(--s-4) 0;">

                <div id="call-confirm" class="call-confirm" hidden>
                    <strong>When you return, tell the CRM what actually happened.</strong>
                    <span class="muted">Opening the phone app proves only that you tried to start the call. It does not prove that you spoke to the customer.</span>
                    <div class="call-confirm-actions call-confirm-actions-3">
                        <button type="button" id="call-connected" class="btn-small btn-info">✓ I spoke to the customer</button>
                        <button type="button" id="call-attempted" class="btn-small">☎ I attempted, but could not reach them</button>
                        <button type="button" id="call-not-attempted" class="btn-small">I did not actually attempt the call</button>
                    </div>
                </div>

                <div id="call-connected-area" class="call-result-area" hidden>
                    <h2 style="margin-bottom:6px;">You spoke to the customer</h2>
                    <p class="muted" style="font-size:12px;margin-bottom:var(--s-3);">Now record the conversation outcome. Only connected calls use these outcomes.</p>
                    <form class="call-form" method="POST" action="{{ url('/calls/log') }}">
                        @csrf
                        <input type="hidden" name="contact_id" value="{{ $contact->id }}">
                        <input type="hidden" name="call_session_id" value="{{ $selectedUnfinishedCall?->id ?? '' }}">
                        <input type="hidden" name="execution_state" value="connected">
                        <div class="calling-outcome-grid">
                            <div>
                                <label>Conversation outcome *</label>
                                <select name="outcome_key" class="input outcome-select" required>
                                    <option value="">— Pick conversation outcome —</option>
                                    @foreach ($connectedOutcomes as $o)
                                        <option value="{{ $o['key'] }}">{{ $o['display_label'] }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label>Talk duration</label>
                                <input type="number" name="duration_seconds" class="input" min="0" max="86400" value="0" placeholder="seconds">
                            </div>
                        </div>
                        <div class="site-visit-datetime" hidden style="margin-top:var(--s-3);">
                            <label>Actual site visit date & time <span class="muted">(change only if needed)</span></label>
                            <input type="datetime-local" name="site_visit_scheduled_at" class="input site-visit-datetime-input" value="{{ optional($contact->site_visit_scheduled_at)->format('Y-m-d\TH:i') }}">
                            <div class="muted site-visit-existing-note" style="font-size:12px;margin-top:4px;">{{ $contact->site_visit_scheduled_at ? 'The CRM already has this customer appointment. Leave it unchanged unless the customer has changed it.' : 'Enter the customer’s actual appointment time once. The CRM will reuse it for later visit-related actions.' }}</div>
                        </div>
                        <div style="margin-top:var(--s-3);">
                            <label>What did they say / what matters next? <span class="muted">(optional)</span></label>
                            <textarea name="notes" class="input" rows="3" maxlength="2000" placeholder="Capture the useful customer detail for the next person."></textarea>
                        </div>
                        <div style="margin-top:var(--s-3);display:flex;gap:var(--s-2);flex-wrap:wrap;">
                            <button type="submit" class="btn-small btn-info">💾 Save conversation & next step</button>
                        </div>
                    </form>
                </div>

                <div id="call-attempted-area" class="call-result-area" hidden>
                    <h2 style="margin-bottom:6px;">Attempted — no connection</h2>
                    <p class="muted" style="font-size:12px;margin-bottom:var(--s-3);">This is a real attempt and will count toward attempts, but it will not count as a connected call. Choose why you could not reach the customer.</p>
                    <form class="call-form" method="POST" action="{{ url('/calls/log') }}">
                        @csrf
                        <input type="hidden" name="contact_id" value="{{ $contact->id }}">
                        <input type="hidden" name="call_session_id" value="{{ $selectedUnfinishedCall?->id ?? '' }}">
                        <input type="hidden" name="execution_state" value="attempted_no_connection">
                        <div>
                            <label>Why no connection? *</label>
                            <select name="outcome_key" class="input outcome-select" required>
                                <option value="">— Pick reason —</option>
                                @foreach ($noConnectionOutcomes as $o)
                                    <option value="{{ $o['key'] }}">{{ $o['display_label'] }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div style="margin-top:var(--s-3);">
                            <label>Optional note</label>
                            <textarea name="notes" class="input" rows="2" maxlength="2000" placeholder="Anything useful about this attempt?"></textarea>
                        </div>
                        <div style="margin-top:var(--s-3);display:flex;gap:var(--s-2);flex-wrap:wrap;">
                            <button type="submit" class="btn-small btn-info">💾 Record attempt & set next step</button>
                        </div>
                    </form>
                </div>

                <div id="call-status" class="muted" style="margin-top:10px;font-size:12px;"></div>
            </div>
        </div>
    @endif
@endif

<script>
(function () {
    var root = document.getElementById('dialer-root');
    if (!root) return;
    var current = document.getElementById('current-contact');
    if (!current) return;

    var queue = JSON.parse(current.getAttribute('data-queue') || '[]');
    var c = queue[0];
    var dialBtn = document.getElementById('dial-button');
    var phoneLink = document.getElementById('contact-phone-link');
    var sessionInputs = document.querySelectorAll('input[name="call_session_id"]');
    var forms = document.querySelectorAll('.call-form');
    var statusEl = document.getElementById('call-status');
    var warning = document.getElementById('unfinished-warning');
    var outcomeMeta = {};
    try { outcomeMeta = Object.fromEntries(JSON.parse(root.getAttribute('data-outcomes') || '[]').map(function(o){ return [o.key, o]; })); } catch(e) {}

    function syncSiteVisitField(form) {
        var select = form.querySelector('select[name="outcome_key"]');
        var box = form.querySelector('.site-visit-datetime');
        var input = form.querySelector('.site-visit-datetime-input');
        if (!select || !box || !input) return;
        var meta = outcomeMeta[select.value] || {};
        var needs = !!meta.requires_visit_datetime || ['visit_before','visit_after'].indexOf(meta.next_action_anchor) !== -1;
        box.hidden = !needs;
        // A previously recorded appointment is reusable. Only require entry when
        // this Contact has no appointment yet. The user can still change the
        // prefilled value when the customer actually reschedules.
        input.required = needs && !input.value;
    }
    forms.forEach(function(form){
        var select = form.querySelector('select[name="outcome_key"]');
        if (select) { select.addEventListener('change', function(){ syncSiteVisitField(form); }); syncSiteVisitField(form); }
    });
    var callConfirm = document.getElementById('call-confirm');
    var connectedArea = document.getElementById('call-connected-area');
    var attemptedArea = document.getElementById('call-attempted-area');
    var connectedBtn = document.getElementById('call-connected');
    var attemptedBtn = document.getElementById('call-attempted');
    var notAttemptedBtn = document.getElementById('call-not-attempted');
    var started = false;
    var awaitingReturn = false;

    function setSessionId(id){
        sessionInputs.forEach(function(el){ el.value = id || ''; });
    }

    function hideResults(){
        if (connectedArea) connectedArea.hidden = true;
        if (attemptedArea) attemptedArea.hidden = true;
    }

    function showCallConfirm(){
        if (!awaitingReturn) return;
        if (callConfirm) callConfirm.hidden = false;
        hideResults();
        callConfirm?.scrollIntoView({behavior:'smooth', block:'nearest'});
    }

    async function startCall() {
        if (started || !c) return;
        started = true;
        if (statusEl) statusEl.textContent = 'Starting call…';
        try {
            var csrf = document.querySelector('input[name="_token"]')?.value || '';
            var res = await fetch(root.dataset.startUrl, {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': csrf,
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'application/json'
                }
            });
            var data = await res.json();
            if (!res.ok || !data.success) throw new Error(data.message || 'Unable to start call.');
            setSessionId(data.session_id);
            if (warning) warning.style.display = 'none';
            awaitingReturn = true;
            if (statusEl) statusEl.textContent = '📞 Phone app opened. When you return, tell the CRM whether you spoke, attempted without connection, or never actually attempted it.';
            beginReturnWatch();
            window.location.href = 'tel:' + encodeURIComponent(data.phone || c.phone);
        } catch (e) {
            started = false;
            if (statusEl) statusEl.textContent = '❌ ' + (e.message || 'Could not start the call.');
        }
    }

    dialBtn?.addEventListener('click', startCall);
    phoneLink?.addEventListener('click', function(e){
        if (!started) { e.preventDefault(); startCall(); }
    });

    connectedBtn?.addEventListener('click', function(){
        awaitingReturn = false;
        if (callConfirm) callConfirm.hidden = true;
        if (connectedArea) connectedArea.hidden = false;
        if (attemptedArea) attemptedArea.hidden = true;
        if (statusEl) statusEl.textContent = '✓ Connected. Record what the customer actually said and the next business step.';
        connectedArea?.scrollIntoView({behavior:'smooth', block:'nearest'});
    });

    attemptedBtn?.addEventListener('click', function(){
        awaitingReturn = false;
        if (callConfirm) callConfirm.hidden = true;
        if (connectedArea) connectedArea.hidden = true;
        if (attemptedArea) attemptedArea.hidden = false;
        if (statusEl) statusEl.textContent = '☎ Attempt recorded. Choose the reason you could not reach the customer.';
        attemptedArea?.scrollIntoView({behavior:'smooth', block:'nearest'});
    });

    notAttemptedBtn?.addEventListener('click', function(){
        awaitingReturn = false;
        if (callConfirm) callConfirm.hidden = true;
        hideResults();
        if (statusEl) statusEl.textContent = 'No attempt recorded. This call remains open and will stay in your work.';
        if (warning) warning.style.display = 'block';
    });

    // Returning from tel:/the phone app is browser-dependent: some browsers fire
    // visibilitychange, others only restore focus/pageshow. Listen to all three and
    // briefly poll while awaiting return so the confirmation choices appear promptly
    // instead of depending on one browser event.
    function maybeShowCallConfirm(){
        if (awaitingReturn && (document.visibilityState === 'visible' || document.hasFocus())) {
            showCallConfirm();
        }
    }
    document.addEventListener('visibilitychange', maybeShowCallConfirm);
    window.addEventListener('focus', maybeShowCallConfirm);
    window.addEventListener('pageshow', maybeShowCallConfirm);
    var returnPoll = null;
    function beginReturnWatch(){
        if (returnPoll) window.clearInterval(returnPoll);
        var startedAt = Date.now();
        returnPoll = window.setInterval(function(){
            if (!awaitingReturn) { window.clearInterval(returnPoll); returnPoll = null; return; }
            maybeShowCallConfirm();
            if (Date.now() - startedAt > 30000) { window.clearInterval(returnPoll); returnPoll = null; }
        }, 300);
    }

    forms.forEach(function(form){
        form.addEventListener('submit', function(e){
            var submit = form.querySelector('button[type="submit"]');
            if (submit) { submit.disabled = true; submit.textContent = 'Saving…'; }
        });
    });
})();
</script>

<style>
.calling-hero{display:flex;align-items:center;justify-content:space-between;gap:var(--s-3);padding:18px 20px;margin-bottom:12px;background:var(--c-surface);border:1px solid var(--c-border);border-radius:14px;box-shadow:var(--shadow-sm)}
.calling-hero h1{margin:2px 0 4px;font-size:22px}.calling-hero p{margin:0;color:var(--c-muted);font-size:13px}.eyebrow{font-size:10px;font-weight:800;letter-spacing:.08em;color:var(--c-primary)}
.calling-work-queue{padding:14px}.calling-work-item{display:flex;align-items:center;gap:12px;padding:13px 4px;border-bottom:1px solid var(--c-border-2);text-decoration:none;color:inherit}.calling-work-item:last-child{border-bottom:0}.calling-work-item:hover{background:var(--c-surface-2)}.calling-work-icon{width:34px;height:34px;border-radius:10px;display:grid;place-items:center;background:var(--c-surface-2);flex:0 0 auto}.calling-work-item.urgent .calling-work-icon{background:#fff4e5}.calling-work-main{display:flex;flex-direction:column;gap:2px;min-width:0;flex:1}.calling-work-main strong{font-size:14px}.calling-work-main span{font-size:12px;color:var(--c-muted);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.calling-work-action{font-size:12px;font-weight:700;color:var(--c-primary);white-space:nowrap}.calling-clear-state{display:flex;align-items:center;gap:12px;flex-wrap:wrap}.calling-clear-state .muted{font-size:12px}.calling-secondary{margin-top:12px}.calling-current-head{padding:14px 16px}.calling-current-meta{display:flex;align-items:center;justify-content:space-between;gap:12px}.calling-phone-row{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:6px 0}.calling-phone{font-size:24px;font-weight:800;color:var(--c-primary);text-decoration:none}.calling-dial-button{font-size:16px;padding:13px 20px}.calling-outcome-grid{display:grid;grid-template-columns:minmax(0,2fr) minmax(140px,1fr);gap:12px}.call-confirm{margin:12px 0;padding:12px;border:1px solid var(--c-border);border-radius:10px;background:var(--c-surface-2);font-size:12px}.call-confirm-actions{display:flex;gap:8px;flex-wrap:wrap;margin-top:9px}.call-confirm-actions-3 .btn-small{flex:1;min-width:190px}.call-result-area{margin-top:12px;padding-top:4px}.call-result-area[hidden]{display:none!important}.call-confirm[hidden],#call-result-area[hidden]{display:none!important}
@media(max-width:767px){.call-confirm-actions{display:grid;grid-template-columns:1fr}.call-confirm-actions .btn-small{width:100%;text-align:center}.calling-hero{display:block;padding:14px}.calling-hero .btn-small{display:block;width:100%;text-align:center;margin-top:12px}.calling-work-item{align-items:flex-start}.calling-work-action{font-size:11px}.calling-current-meta{align-items:flex-start}.calling-phone-row{align-items:stretch;flex-direction:column}.calling-phone{font-size:21px}.calling-dial-button{width:100%;min-height:46px}.calling-outcome-grid{grid-template-columns:1fr}.calling-clear-state{display:block}.calling-clear-state .btn-small{display:block;text-align:center;margin-top:10px}.calling-secondary .section-head{align-items:flex-start}}
</style>

@endsection
