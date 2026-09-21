@php
    $settings = app(\App\Services\SettingsService::class);
    $statuses = $settings->statuses();
    $leadKey  = $lead->statusKey();
    $allowedStatusKeys = $settings->allowedTransitionsFrom($leadKey);

    // Conditional field maps — which status needs what
    $requiresDateTimeMap  = $statuses->pluck('requires_datetime', 'key')->toArray();
    $requiresBookingMap   = $statuses->pluck('requires_booking_details', 'key')->toArray();

    // Defaults
    $defaultVisit = $lead->visit_scheduled_at
        ? $lead->visit_scheduled_at->format('Y-m-d\TH:i')
        : now()->addDay()->setTime(11, 0)->format('Y-m-d\TH:i');

    $defaultBookingDate  = now()->toDateString();
    $defaultBrokeragePct = $settings->get('default_brokerage_percentage', 2);
    $defaultBrokerageExpected = now()->addDays(30)->toDateString();
@endphp

<form method="POST" action="/leads/status" data-ajax>
    @csrf
    <input type="hidden" name="lead_id" value="{{ $lead->id }}">

    <p style="margin-bottom:12px;">
        Current status:
        <x-status-badge :status="$leadKey" />
    </p>

    {{-- ============================================================ --}}
    {{-- NEW STATUS --}}
    {{-- ============================================================ --}}
    <div class="field">
        <label>New Status *</label>
        <select name="status" class="input" id="cs-status-select">
            <option value="">— Select allowed next status —</option>
            @foreach ($statuses->whereIn('key', $allowedStatusKeys) as $s)
                <option value="{{ $s->key }}">
                    {{ $s->label }}
                </option>
            @endforeach
        </select>
        @if (empty($allowedStatusKeys))
            <p class="muted" style="margin-top:6px;font-size:12px;">No direct status transition is available from this stage.</p>
        @endif
        <p class="muted" style="margin-top:6px;font-size:12px;">
            🔒 Status change requires an activity logged within 15 minutes.
        </p>
    </div>

    {{-- ============================================================ --}}
    {{-- VISIT DATE & TIME (when status needs datetime) --}}
    {{-- ============================================================ --}}
    <div class="field" id="cs-visit-field" style="display:none;">
        <label>Visit Date &amp; Time *</label>
        <input type="datetime-local" name="visit_scheduled_at" class="input"
               id="cs-visit-input" value="{{ $defaultVisit }}">
        <p class="muted" style="margin-top:6px;font-size:12px;">
            🕒 Reminders will auto-schedule: 2h before · 3h after · 1 day after.
        </p>
    </div>

    {{-- ============================================================ --}}
    {{-- BOOKING + BROKERAGE (when status requires booking details) --}}
    {{-- ============================================================ --}}
    <div id="cs-booking-fields" style="display:none;">

        {{-- Property: area × rate = value --}}
        <p style="font-weight:700;font-size:13px;margin-bottom:var(--s-2);">📐 Property</p>

        <div class="flex">
            <div class="flex-item">
                <label>Area (sq ft)</label>
                <input type="number" name="property_area_sqft" class="input"
                       id="cs-area" min="0" step="0.01"
                       placeholder="e.g. 1200">
            </div>
            <div class="flex-item">
                <label>Rate (₹ / sq ft)</label>
                <input type="number" name="rate_per_sqft" class="input"
                       id="cs-rate" min="0" step="0.01"
                       placeholder="e.g. 8000">
            </div>
        </div>

        <div class="field">
            <label>Property Value (₹) * <span class="muted" style="font-weight:400;">— auto from area × rate, editable</span></label>
            <input type="number" name="booking_amount" class="input"
                   id="cs-booking-amount" min="0" step="0.01"
                   placeholder="e.g. 9600000">
        </div>

        <div class="flex">
            <div class="flex-item">
                <label>Unit / Flat No.</label>
                <input type="text" name="booking_unit" class="input" maxlength="100"
                       placeholder="e.g. A-1204">
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
            <input type="date" name="booking_date" class="input"
                   value="{{ $defaultBookingDate }}">
        </div>

        {{-- Brokerage --}}
        <hr style="border:0;border-top:1px dashed var(--c-border);margin:var(--s-3) 0;">

        <p style="font-weight:700;font-size:13px;margin-bottom:var(--s-2);">💰 Brokerage</p>

        <div class="flex">
            <div class="flex-item">
                <label>Brokerage %</label>
                <input type="number" name="brokerage_percentage" class="input"
                       id="cs-pct" min="0" max="100" step="0.01"
                       value="{{ $defaultBrokeragePct }}">
            </div>
            <div class="flex-item">
                <label>Brokerage Amount (₹)</label>
                <input type="number" name="brokerage_amount" class="input"
                       id="cs-brok" min="0" step="0.01">
            </div>
        </div>

        <div class="field">
            <label>Expected receipt date</label>
            <input type="date" name="brokerage_expected_at" class="input"
                   value="{{ $defaultBrokerageExpected }}">
        </div>

        <div class="field">
            <label>Co-broker (optional)</label>
            <input type="text" name="co_broker_name" class="input" maxlength="100"
                   placeholder="If commission is shared">
        </div>

        <p class="muted" style="margin-top:6px;font-size:12px;">
            🎉 This will mark the lead as <strong>Booking</strong> (final). Other tasks cancelled.
            Two new tasks scheduled: Thank You Call (+3d) and Brokerage Follow-up (+30d).
        </p>
    </div>

    {{-- ============================================================ --}}
    {{-- LOST / NURTURE CONTROL --}}
    {{-- ============================================================ --}}
    @php
        $lostReasonOptions = \App\Services\LeadStatusService::lostReasonOptions();
        $currentLostKey = $lead->lost_reason_key;
    @endphp
    <div id="cs-lost-field" style="display:none;">
        <div style="padding:12px;border:1px solid var(--c-border);border-radius:10px;background:var(--c-surface-2);margin-bottom:12px;">
            <strong style="display:block;margin-bottom:4px;">🚫 Why is this lead being lost?</strong>
            <p class="muted" style="margin:0;font-size:12px;">Select the reason. This controls whether the CRM may ever create a future reactivation.</p>
        </div>

        <div class="field">
            <label>Lost reason *</label>
            <select name="lost_reason_key" id="cs-lost-reason" class="input">
                <option value="">— Select a reason —</option>
                <optgroup label="🔄 Nurture possible — customer may buy later">
                    @foreach ($lostReasonOptions['nurture'] as $opt)
                        <option value="{{ $opt['key'] }}" @selected($currentLostKey === $opt['key'])>{{ $opt['label'] }}</option>
                    @endforeach
                </optgroup>
                <optgroup label="🚫 Permanently closed — never reactivate automatically">
                    @foreach ($lostReasonOptions['closed'] as $opt)
                        <option value="{{ $opt['key'] }}" @selected($currentLostKey === $opt['key'])>{{ $opt['label'] }}</option>
                    @endforeach
                </optgroup>
            </select>
        </div>

        <div id="cs-nurture-box" style="display:none;padding:12px;border:1px solid var(--c-success);border-radius:10px;background:#f0fdf4;margin-bottom:12px;">
            <label style="display:flex;gap:8px;align-items:flex-start;font-weight:700;cursor:pointer;">
                <input type="checkbox" name="nurture_enabled" id="cs-nurture-enabled" value="1" style="margin-top:3px;">
                <span>🔄 Add to Nurture / Reactivation</span>
            </label>
            <p class="muted" style="margin:6px 0 10px;font-size:12px;">Only tick this when you intentionally want the customer contacted again later.</p>
            <div id="cs-nurture-date-wrap" style="display:none;">
                <label>Re-contact date &amp; time *</label>
                <input type="datetime-local" name="nurture_scheduled_at" class="input" id="cs-nurture-date" value="{{ now()->addDays(90)->setTime(10,0)->format('Y-m-d\TH:i') }}">
            </div>
        </div>

        <div id="cs-permanent-box" style="display:none;padding:10px 12px;border-radius:10px;background:#fff1f2;border:1px solid #fecdd3;color:#9f1239;font-size:12px;margin-bottom:12px;">
            🚫 <strong>No future reactivation will be created.</strong> This reason is treated as permanently closed.
        </div>

        <div class="field">
            <label>Additional note <span class="muted" style="font-weight:400;">(optional)</span></label>
            <textarea name="lost_reason" rows="3" class="input" maxlength="2000" placeholder="Add useful details for the team...">{{ $lead->lost_reason }}</textarea>
        </div>
    </div>

    <button type="submit" class="btn btn-block">Update Status</button>
</form>

<script>
(function () {
    var requiresDateTimeMap = @json($requiresDateTimeMap);
    var requiresBookingMap  = @json($requiresBookingMap);

    var statusSel   = document.getElementById('cs-status-select');
    var visitField  = document.getElementById('cs-visit-field');
    var visitInput  = document.getElementById('cs-visit-input');
    var bookingBox  = document.getElementById('cs-booking-fields');
    var bookingAmt  = document.getElementById('cs-booking-amount');
    var lostField   = document.getElementById('cs-lost-field');
    var lostReasonSel = document.getElementById('cs-lost-reason');
    var nurtureBox = document.getElementById('cs-nurture-box');
    var nurtureEnabled = document.getElementById('cs-nurture-enabled');
    var nurtureDateWrap = document.getElementById('cs-nurture-date-wrap');
    var nurtureDate = document.getElementById('cs-nurture-date');
    var permanentBox = document.getElementById('cs-permanent-box');
    var nurtureKeys = @json(array_column($lostReasonOptions['nurture'], 'key'));

    var areaIn  = document.getElementById('cs-area');
    var rateIn  = document.getElementById('cs-rate');
    var valueIn = document.getElementById('cs-booking-amount');
    var pctIn   = document.getElementById('cs-pct');
    var brokIn  = document.getElementById('cs-brok');

    function updateConditionalFields() {
        var key = statusSel.value;

        var showVisit   = !!requiresDateTimeMap[key];
        var showBooking = !!requiresBookingMap[key];
        var showLost    = (key === 'lost');

        visitField.style.display  = showVisit   ? 'block' : 'none';
        bookingBox.style.display  = showBooking ? 'block' : 'none';
        lostField.style.display   = showLost    ? 'block' : 'none';
        if (!showLost) {
            if (lostReasonSel) lostReasonSel.required = false;
        } else {
            if (lostReasonSel) lostReasonSel.required = true;
            updateLostReason();
        }

        visitInput.required = showVisit;
        bookingAmt.required = showBooking;
    }

    function updateLostReason() {
        var key = lostReasonSel ? lostReasonSel.value : '';
        var eligible = nurtureKeys.indexOf(key) !== -1;
        if (nurtureBox) nurtureBox.style.display = eligible ? 'block' : 'none';
        if (permanentBox) permanentBox.style.display = (key && !eligible) ? 'block' : 'none';
        if (nurtureEnabled) {
            nurtureEnabled.checked = false;
            nurtureEnabled.disabled = !eligible;
        }
        if (nurtureDateWrap) nurtureDateWrap.style.display = 'none';
        if (nurtureDate) { nurtureDate.required = false; nurtureDate.disabled = true; }
    }

    function calcValue() {
        var a = parseFloat(areaIn.value || '0');
        var r = parseFloat(rateIn.value || '0');
        if (a && r) {
            valueIn.value = Math.round(a * r * 100) / 100;
        }
        calcBrokerage();
    }

    function calcBrokerage() {
        var v = parseFloat(valueIn.value || '0');
        var p = parseFloat(pctIn.value || '0');
        if (v && p) {
            brokIn.value = Math.round(v * p / 100 * 100) / 100;
        }
    }

    statusSel.addEventListener('change', updateConditionalFields);
    if (lostReasonSel) lostReasonSel.addEventListener('change', updateLostReason);
    if (nurtureEnabled) nurtureEnabled.addEventListener('change', function () {
        var on = this.checked && !this.disabled;
        if (nurtureDateWrap) nurtureDateWrap.style.display = on ? 'block' : 'none';
        if (nurtureDate) { nurtureDate.required = on; nurtureDate.disabled = !on; }
    });
    if (areaIn)  areaIn.addEventListener('input', calcValue);
    if (rateIn)  rateIn.addEventListener('input', calcValue);
    if (valueIn) valueIn.addEventListener('input', calcBrokerage);
    if (pctIn)   pctIn.addEventListener('input', calcBrokerage);

    updateConditionalFields();
})();
</script>