@php
    $settings = app(\App\Services\SettingsService::class);

    $activityTypes = $settings->activityTypes();
    $allOutcomes   = $settings->callOutcomes(true);
    $presentedOutcomes = app(\App\Services\WorkflowPresentationService::class)
        ->presentOutcomes($allOutcomes, (int) session("user_id"));
    $allStatuses   = $settings->statuses();

    // System-generated activity types must NOT appear in the manual
    // Log Activity dropdown. They are written only by backend code:
    //   - lead_shared / lead_reassigned → assignment + share services
    //   - status_change                 → LeadStatusService::change()
    //   - shared_agent_report / site_team_report → check-back task flow
        $systemTypes = [
        'lead_shared',
        'lead_reassigned',
        'status_change',
        'shared_agent_report',
        'site_team_report',
        'external_share',
    ];

    $activityTypes = $activityTypes->reject(
        fn ($t) => in_array($t->key, $systemTypes, true)
    )->values();

    $requiresOutcomeMap = $activityTypes->pluck('requires_outcome', 'key')->toArray();

    $leadStatusKey         = $lead->statusKey();
    $allowedTransitionKeys = $settings->allowedTransitionsFrom($leadStatusKey);
    $isLost                = $settings->statusIsLost($leadStatusKey);
    $isWon                 = $settings->statusIsWon($leadStatusKey);

    $statusesById        = $allStatuses->pluck('key',   'id')->toArray();
    $statusesByKey       = $allStatuses->pluck('label', 'key')->toArray();
    $requiresDateById    = $allStatuses->pluck('requires_datetime', 'id')->toArray();
    $requiresBookingById = $allStatuses->pluck('requires_booking_details', 'id')->toArray();

    // Build a JSON payload of every outcome with its metadata
    $outcomesJson = $presentedOutcomes->map(function (array $o) {
        return [
            'key'              => $o['key'],
            'label'            => $o['display_label'],
            'category'         => $o['category'],
            'context'          => $o['context_action_key'],
            'activity_filter'  => $o['activity_type_filter'],
            'next'             => $o['next_action_label'],
            'delay'            => $o['next_action_delay_hours'],
            'suggested_id'     => $o['suggested_status_id'],
            'prompts_wa'       => $o['prompts_whatsapp_send'],
        ];
    })->values();

    $lostReasonOptions = app(\App\Services\WorkflowPresentationService::class)->presentLostReasons((int) session("user_id"));
    $defaultVisit = now()->addDay()->setTime(11, 0)->format('Y-m-d\TH:i');
    $defaultBrokeragePct = $settings->get('default_brokerage_percentage', 2);
    $defaultBrokerageExpected = now()->addDays(30)->toDateString();
@endphp

<form method="POST" action="/activities" data-ajax>
    @csrf
    <input type="hidden" name="lead_id" value="{{ $lead->id }}">

    <p class="muted" style="margin-bottom:12px;">
        Logging for <strong>{{ $lead->customer_name }}</strong>
        @if ($isLost)
            <br><em style="color:var(--c-danger);">
                ⚠️ This lead is Lost. Revive it first from the lead page.
            </em>
        @endif
    </p>

    <div class="field">
        <label>Type *</label>
        <select name="type" class="input" id="la-type">
            @foreach ($activityTypes as $t)
                <option value="{{ $t->key }}">{{ $t->icon }} {{ $t->label }}</option>
            @endforeach
        </select>
    </div>

        <div class="field" id="la-outcome-field">
        <label>Outcome *</label>

        <select name="outcome_key" id="la-outcome-select" style="display:none;">
            <option value="">— Select outcome —</option>
        </select>

        <div class="op-picker" id="la-op-picker">
            <div class="op-input-wrap">
                <input type="text" class="input op-search" id="la-op-search"
                       placeholder="🔍 Search or select outcome…"
                       autocomplete="off" spellcheck="false" readonly>
                <button type="button" class="op-clear" id="la-op-clear" hidden aria-label="Clear">✕</button>
            </div>
            <div class="op-results" id="la-op-results" hidden></div>
        </div>
        <p class="muted op-hint">🔍 Type to search · ↑↓ to navigate · Enter to select</p>
        <div id="la-preview" class="muted"
             style="margin-top:6px;font-size:12px;min-height:18px;"></div>
    </div>

    {{-- Send WhatsApp now --}}
    <div class="field" id="la-wa-field" style="display:none;">
        <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-weight:600;">
            <input type="checkbox" name="also_whatsapp" id="la-wa-cb"
                   value="1" checked style="width:auto;min-height:auto;">
            <span>📤 Also send details on WhatsApp now</span>
        </label>
        <p class="muted" style="font-size:11px;margin-top:4px;margin-left:26px;">
            Logs a WhatsApp activity + schedules a WhatsApp Follow-up to check the response.
        </p>
    </div>

    <div class="field" id="la-visit-field" style="display:none;">
        <label>Visit Date &amp; Time *</label>
        <input type="datetime-local" name="visit_scheduled_at" class="input"
               id="la-visit-input" value="{{ $defaultVisit }}">
    </div>
    <div class="field" id="la-lost-field" style="display:none;">
        <label>Lost reason *</label>
        <select name="lost_reason_key" id="la-lost-reason" class="input">
            <option value="">— Select reason —</option>
            @foreach ($lostReasonOptions as $groupKey => $groupOptions)
                <optgroup label="{{ $groupKey === 'nurture' ? 'Can be reactivated later' : 'Permanently closed' }}">
                    @foreach ($groupOptions as $reason)
                        <option value="{{ $reason['key'] }}">{{ $reason['display_label'] }}</option>
                    @endforeach
                </optgroup>
            @endforeach
        </select>
        <label style="margin-top:8px;">Lost notes <span class="muted">(optional)</span></label>
        <textarea name="lost_reason" rows="2" class="input" id="la-lost-input" placeholder="Optional context about why the lead was lost"></textarea>
    </div>

    <div id="la-booking-fields" style="display:none;">
        <p style="font-weight:700;font-size:13px;margin-bottom:var(--s-2);">📐 Property</p>

        <div class="flex">
            <div class="flex-item">
                <label>Area (sq ft)</label>
                <input type="number" name="property_area_sqft" class="input"
                       id="la-area" min="0" step="0.01" placeholder="e.g. 1200">
            </div>
            <div class="flex-item">
                <label>Rate (₹ / sq ft)</label>
                <input type="number" name="rate_per_sqft" class="input"
                       id="la-rate" min="0" step="0.01" placeholder="e.g. 8000">
            </div>
        </div>

        <div class="field">
            <label>Property Value (₹) *</label>
            <input type="number" name="booking_amount" class="input"
                   id="la-booking-amount" min="0" step="0.01" placeholder="e.g. 9600000">
        </div>

        <div class="flex">
            <div class="flex-item">
                <label>Unit / Flat No.</label>
                <input type="text" name="booking_unit" class="input" maxlength="100">
            </div>
            <div class="flex-item">
                <label>Payment Mode</label>
                <select name="booking_payment_mode" class="input">
                    <option value="">—</option>
                    <option value="Cheque">Cheque</option>
                    <option value="NEFT">NEFT</option>
                    <option value="RTGS">RTGS</option>
                    <option value="UPI">UPI</option>
                    <option value="Cash">Cash</option>
                </select>
            </div>
        </div>

        <div class="field">
            <label>Booking Date</label>
            <input type="date" name="booking_date" class="input" value="{{ now()->toDateString() }}">
        </div>

        <hr style="border:0;border-top:1px dashed var(--c-border);margin:var(--s-3) 0;">

        <p style="font-weight:700;font-size:13px;margin-bottom:var(--s-2);">💰 Brokerage</p>

        <div class="flex">
            <div class="flex-item">
                <label>Brokerage %</label>
                <input type="number" name="brokerage_percentage" class="input"
                       id="la-pct" min="0" max="100" step="0.01" value="{{ $defaultBrokeragePct }}">
            </div>
            <div class="flex-item">
                <label>Brokerage Amount (₹)</label>
                <input type="number" name="brokerage_amount" class="input"
                       id="la-brok" min="0" step="0.01">
            </div>
        </div>

        <div class="field">
            <label>Expected receipt date</label>
            <input type="date" name="brokerage_expected_at" class="input"
                   value="{{ $defaultBrokerageExpected }}">
        </div>

        <div class="field">
            <label>Co-broker (optional)</label>
            <input type="text" name="co_broker_name" class="input" maxlength="100">
        </div>
    </div>

    <div class="field">
        <label>Notes (optional)</label>
        <textarea name="notes" rows="3" class="input"
                  placeholder="Any additional context..."></textarea>
    </div>

    <button type="submit" class="btn btn-block">Save Activity</button>
</form>

<script>
(function () {
    var requiresMap         = @json($requiresOutcomeMap);
    var statusesById        = @json($statusesById);
    var statusesByKey       = @json($statusesByKey);
    var requiresDateById    = @json($requiresDateById);
    var requiresBookingById = @json($requiresBookingById);
    var allowedKeys         = @json($allowedTransitionKeys);
    var isLost              = @json($isLost);
    var isWon               = @json($isWon);
    var ALL_OUTCOMES        = @json($outcomesJson);

    var typeSel    = document.getElementById('la-type');
    var outcomeF   = document.getElementById('la-outcome-field');
    var outcomeSel = document.getElementById('la-outcome-select');
    var preview    = document.getElementById('la-preview');
    var waF        = document.getElementById('la-wa-field');
    var waCb       = document.getElementById('la-wa-cb');
    var visitF     = document.getElementById('la-visit-field');
    var visitInput = document.getElementById('la-visit-input');
    var lostF      = document.getElementById('la-lost-field');
    var lostInput  = document.getElementById('la-lost-input');
    var bookingF   = document.getElementById('la-booking-fields');
    var bookingAmt = document.getElementById('la-booking-amount');

    var areaIn = document.getElementById('la-area');
    var rateIn = document.getElementById('la-rate');
    var pctIn  = document.getElementById('la-pct');
    var brokIn = document.getElementById('la-brok');

    function resetConditionals() {
        preview.textContent = '';
        visitF.style.display = 'none';      visitInput.required = false;
        lostF.style.display = 'none';       lostInput.required = false;
        bookingF.style.display = 'none';    bookingAmt.required = false;
        waF.style.display = 'none';         waCb.checked = true;
    }

    function rebuildOutcomes() {
        var activityType = typeSel.value;

        // Comma-separated filter support — matches the Complete Task modal.
        // Example: activity_type_filter = 'call,whatsapp' matches both
        // Call and WhatsApp activity types.
        var filtered = ALL_OUTCOMES.filter(function (o) {
            var filterStr = String(o.activity_filter || '').trim();
            if (! filterStr) return true;
            var types = filterStr.split(',').map(function (s) { return s.trim(); });
            return types.indexOf(activityType) !== -1;
        });

        outcomeSel.innerHTML = '<option value="">— Select outcome —</option>';

        var groups = { positive: [], neutral: [], negative: [] };
        filtered.forEach(function (o) {
            if (groups[o.category]) groups[o.category].push(o);
        });

        ['positive', 'neutral', 'negative'].forEach(function (cat) {
            if (! groups[cat].length) return;
            var grp = document.createElement('optgroup');
            grp.label = cat.charAt(0).toUpperCase() + cat.slice(1);
            groups[cat].forEach(function (o) {
                var opt = document.createElement('option');
                opt.value = o.key;
                opt.textContent = o.label;
                opt.dataset.next = o.next || '';
                opt.dataset.delay = o.delay || 0;
                opt.dataset.suggestedId = o.suggested_id || '';
                opt.dataset.promptsWa = o.prompts_wa ? '1' : '0';
                grp.appendChild(opt);
            });
            outcomeSel.appendChild(grp);
        });
    }

    function onTypeChange() {
        var t = typeSel.value;
        var required = !!requiresMap[t];

        outcomeF.style.display = required ? 'block' : 'none';
        rebuildOutcomes();
        resetConditionals();
    }

    function onOutcomeChange() {
        var opt = outcomeSel.options[outcomeSel.selectedIndex];
        resetConditionals();
        if (! opt || ! opt.value) return;

        var next   = opt.dataset.next  || '';
        var delay  = parseInt(opt.dataset.delay || '0', 10);
        var suggId = opt.dataset.suggestedId || '';
        var wa     = opt.dataset.promptsWa === '1';

        var human = delay < 24
            ? (delay + ' hour' + (delay === 1 ? '' : 's'))
            : (Math.round(delay / 24) + ' day' + (delay === 24 ? '' : 's'));

        var txt = '⏭️ Next action: ' + next + ' in ' + human;

        if (suggId && statusesById[suggId]) {
            var suggestedKey = statusesById[suggId];
            var canAdvance   = allowedKeys.indexOf(suggestedKey) !== -1;

            if (canAdvance) {
                txt += '  ·  💡 Suggest: ' + (statusesByKey[suggestedKey] || suggestedKey);

                if (suggestedKey === 'lost') {
                    lostF.style.display = 'block';
                    lostInput.required = true;
                } else if (requiresDateById[suggId]) {
                    visitF.style.display = 'block';
                    visitInput.required = true;
                } else if (requiresBookingById[suggId]) {
                    bookingF.style.display = 'block';
                    bookingAmt.required = true;
                }
            }
        }

        if (wa) {
            waF.style.display = 'block';
            waCb.checked = true;
            txt += '  ·  📤 option to send WhatsApp';
        }

        preview.textContent = txt;
    }

    function calcValue() {
        var a = parseFloat(areaIn.value || '0');
        var r = parseFloat(rateIn.value || '0');
        if (a && r) bookingAmt.value = Math.round(a * r * 100) / 100;
        calcBrokerage();
    }
    function calcBrokerage() {
        var v = parseFloat(bookingAmt.value || '0');
        var p = parseFloat(pctIn.value || '0');
        if (v && p) brokIn.value = Math.round(v * p / 100 * 100) / 100;
    }

    typeSel.addEventListener('change', onTypeChange);
    outcomeSel.addEventListener('change', onOutcomeChange);

    if (areaIn)  areaIn.addEventListener('input', calcValue);
    if (rateIn)  rateIn.addEventListener('input', calcValue);
    if (bookingAmt) bookingAmt.addEventListener('input', calcBrokerage);
    if (pctIn)   pctIn.addEventListener('input', calcBrokerage);

    onTypeChange();
    
        /* ============================================================
       SEARCHABLE OUTCOME PICKER (log-activity)
       ============================================================ */
    (function initOutcomePickerLA() {
        var sel      = document.getElementById('la-outcome-select');
        var picker   = document.getElementById('la-op-picker');
        var search   = document.getElementById('la-op-search');
        var results  = document.getElementById('la-op-results');
        var clearBtn = document.getElementById('la-op-clear');
        if (!sel || !picker || !search || !results) return;

        var activeIx = -1;
        var items    = [];

        function closeResults() {
            results.hidden = true;
            activeIx = -1;
            items.forEach(function (el) { el.classList.remove('active'); });
        }
        function setActive(ix) {
            items.forEach(function (el, i) { el.classList.toggle('active', i === ix); });
            activeIx = ix;
            var el = items[ix];
            if (el && el.scrollIntoView) el.scrollIntoView({ block: 'nearest' });
        }
        function pickOption(value, label) {
            sel.value = value;
            search.value = value ? label : '';
            clearBtn.hidden = !value;
            closeResults();
            sel.dispatchEvent(new Event('change', { bubbles: true }));
        }
        function buildResults(filter) {
            var q = (filter || '').toLowerCase().trim();
            results.innerHTML = '';
            items = [];
            activeIx = -1;

            var groups = {};
            Array.prototype.forEach.call(sel.options, function (opt) {
                if (! opt.value) return;
                var label = opt.textContent.trim();
                if (q && label.toLowerCase().indexOf(q) === -1) return;
                var parent = opt.parentNode;
                var groupName = (parent && parent.tagName === 'OPTGROUP')
                    ? parent.getAttribute('label') : '';
                if (! groups[groupName]) groups[groupName] = [];
                groups[groupName].push({ value: opt.value, label: label });
            });

            var any = false;
            Object.keys(groups).forEach(function (g) {
                if (! groups[g].length) return;
                any = true;
                if (g) {
                    var gl = document.createElement('div');
                    gl.className = 'op-group-label';
                    gl.textContent = g;
                    results.appendChild(gl);
                }
                groups[g].forEach(function (it) {
                    var btn = document.createElement('button');
                    btn.type = 'button';
                    btn.className = 'op-item';
                    btn.textContent = it.label;
                    btn.addEventListener('click', function () { pickOption(it.value, it.label); });
                    results.appendChild(btn);
                    items.push(btn);
                });
            });

            if (! any) {
                var empty = document.createElement('div');
                empty.className = 'op-empty';
                empty.textContent = q ? 'No matching outcomes' : 'No outcomes available';
                results.appendChild(empty);
            }
            results.hidden = false;
        }

        search.addEventListener('focus', function () {
            search.removeAttribute('readonly');
            buildResults(search.value === '— Select outcome —' ? '' : search.value);
        });
        search.addEventListener('blur', function () {
            setTimeout(function () { search.setAttribute('readonly', 'readonly'); }, 200);
        });
        search.addEventListener('input', function () {
            clearBtn.hidden = search.value === '';
            buildResults(search.value);
        });
        search.addEventListener('keydown', function (e) {
            if (e.key === 'ArrowDown') {
                e.preventDefault();
                if (results.hidden) buildResults(search.value);
                if (items.length) setActive((activeIx + 1) % items.length);
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                if (items.length) setActive((activeIx - 1 + items.length) % items.length);
            } else if (e.key === 'Enter') {
                if (activeIx >= 0 && items[activeIx]) { e.preventDefault(); items[activeIx].click(); }
            } else if (e.key === 'Escape') {
                closeResults(); search.blur();
            }
        });

        if (clearBtn) {
            clearBtn.addEventListener('click', function (e) {
                e.preventDefault();
                pickOption('', '');
                search.value = '';
                clearBtn.hidden = true;
                search.focus();
            });
        }
        document.addEventListener('click', function (e) {
            if (! picker.contains(e.target)) closeResults();
        });
        var mo = new MutationObserver(function () {
            if (! sel.value) {
                search.value = '';
                clearBtn.hidden = true;
            } else {
                var opt = sel.options[sel.selectedIndex];
                if (opt) { search.value = opt.textContent.trim(); clearBtn.hidden = false; }
            }
            if (! results.hidden) buildResults(search.value);
        });
        mo.observe(sel, { childList: true, subtree: true });
    })();
})();
</script>