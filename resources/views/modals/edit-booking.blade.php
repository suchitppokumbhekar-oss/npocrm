@php
    $defaultBrokeragePct = $defaultBrokeragePct ?? 2;

    $bookingDate  = $lead->booking_date?->format('Y-m-d') ?? now()->toDateString();
    $brokeragePct = $lead->brokerage_percentage ?? $defaultBrokeragePct;
    $brokerageAmt = $lead->brokerage_amount;
    $expectedAt   = $lead->brokerage_expected_at?->format('Y-m-d') ?? now()->addDays(30)->toDateString();
@endphp

<form method="POST" action="/leads/update-booking" enctype="multipart/form-data" data-ajax>
    @csrf
    <input type="hidden" name="lead_id" value="{{ $lead->id }}">

    <p class="muted" style="margin-bottom:12px;">
        Editing booking for <strong>{{ $lead->customer_name }}</strong>
    </p>

    {{-- ============================================================ --}}
    {{-- AREA × RATE = PROPERTY VALUE --}}
    {{-- ============================================================ --}}
    <p style="font-weight:700;font-size:13px;margin-bottom:var(--s-2);">📐 Property</p>

    <div class="flex">
        <div class="flex-item">
            <label>Area (sq ft)</label>
            <input type="number" name="property_area_sqft" class="input"
                   id="eb-area" min="0" step="0.01"
                   value="{{ old('property_area_sqft', $lead->property_area_sqft) }}"
                   placeholder="e.g. 1200">
        </div>
        <div class="flex-item">
            <label>Rate (₹ / sq ft)</label>
            <input type="number" name="rate_per_sqft" class="input"
                   id="eb-rate" min="0" step="0.01"
                   value="{{ old('rate_per_sqft', $lead->rate_per_sqft) }}"
                   placeholder="e.g. 8000">
        </div>
    </div>

    <div class="field">
        <label>Property Value (₹) * <span class="muted" style="font-weight:400;">— auto from area × rate, editable</span></label>
        <input type="number" name="booking_amount" class="input"
               id="eb-value" min="0" step="0.01"
               value="{{ old('booking_amount', $lead->booking_amount) }}"
               required placeholder="e.g. 9600000">
    </div>

    <div class="flex">
        <div class="flex-item">
            <label>Unit / Flat No.</label>
            <input type="text" name="booking_unit" class="input" maxlength="100"
                   value="{{ old('booking_unit', $lead->booking_unit) }}"
                   placeholder="e.g. A-1204">
        </div>
        <div class="flex-item">
            <label>Payment Mode</label>
            <select name="booking_payment_mode" class="input">
                <option value="">—</option>
                @foreach (['Cheque','NEFT','RTGS','UPI','Cash'] as $mode)
                    <option value="{{ $mode }}"
                            @selected(old('booking_payment_mode', $lead->booking_payment_mode) === $mode)>
                        {{ $mode }}
                    </option>
                @endforeach
            </select>
        </div>
    </div>

    <div class="field">
        <label>Booking Date</label>
        <input type="date" name="booking_date" class="input"
               value="{{ old('booking_date', $bookingDate) }}">
    </div>

    {{-- ============================================================ --}}
    {{-- BROKERAGE --}}
    {{-- ============================================================ --}}
    <hr style="border:0;border-top:1px dashed var(--c-border);margin:var(--s-3) 0;">

    <p style="font-weight:700;font-size:13px;margin-bottom:var(--s-2);">💰 Brokerage</p>

    <div class="flex">
        <div class="flex-item">
            <label>Brokerage %</label>
            <input type="number" name="brokerage_percentage" class="input"
                   id="eb-pct" min="0" max="100" step="0.01"
                   value="{{ old('brokerage_percentage', $brokeragePct) }}">
        </div>
        <div class="flex-item">
            <label>Brokerage Amount (₹)</label>
            <input type="number" name="brokerage_amount" class="input"
                   id="eb-brok" min="0" step="0.01"
                   value="{{ old('brokerage_amount', $brokerageAmt) }}">
        </div>
    </div>

    <div class="field">
        <label>Brokerage Status</label>
        <select name="brokerage_status" class="input">
            @foreach (['pending','invoiced','received','disputed'] as $bs)
                <option value="{{ $bs }}"
                        @selected(old('brokerage_status', $lead->brokerage_status ?? 'pending') === $bs)>
                    {{ ucfirst($bs) }}
                </option>
            @endforeach
        </select>
    </div>

    <div class="field">
        <label>Expected receipt date</label>
        <input type="date" name="brokerage_expected_at" class="input"
               value="{{ old('brokerage_expected_at', $expectedAt) }}">
    </div>

    <div class="field">
        <label>Co-broker (optional)</label>
        <input type="text" name="co_broker_name" class="input" maxlength="100"
               value="{{ old('co_broker_name', $lead->co_broker_name) }}"
               placeholder="If commission is shared">
    </div>

    <div class="field">
        <label>Booking Evidence <span class="muted">(optional)</span></label>
        <input type="file" name="booking_evidence" class="input" accept="image/jpeg,image/png,image/webp,application/pdf,.doc,.docx,.xls,.xlsx">
        <div class="muted" style="font-size:11px;margin-top:4px;">Booking form, payment proof, allotment/confirmation, KYC or related booking document. Max 25 MB.</div>
    </div>

    <button type="submit" class="btn btn-block">💾 Save Booking Details</button>
</form>

<script>
(function () {
    var areaIn  = document.getElementById('eb-area');
    var rateIn  = document.getElementById('eb-rate');
    var valueIn = document.getElementById('eb-value');
    var pctIn   = document.getElementById('eb-pct');
    var brokIn  = document.getElementById('eb-brok');

    function calcValue() {
        var a = parseFloat(areaIn.value || '0');
        var r = parseFloat(rateIn.value || '0');
        if (a && r) valueIn.value = Math.round(a * r * 100) / 100;
        calcBrokerage();
    }

    function calcBrokerage() {
        var v = parseFloat(valueIn.value || '0');
        var p = parseFloat(pctIn.value || '0');
        if (v && p) brokIn.value = Math.round(v * p / 100 * 100) / 100;
    }

    if (areaIn)  areaIn.addEventListener('input', calcValue);
    if (rateIn)  rateIn.addEventListener('input', calcValue);
    if (valueIn) valueIn.addEventListener('input', calcBrokerage);
    if (pctIn)   pctIn.addEventListener('input', calcBrokerage);
})();
</script>