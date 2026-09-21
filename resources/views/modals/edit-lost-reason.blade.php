@php
    $options = $lostReasonOptions;
    $currentKey = $lead->lost_reason_key;
    $pendingAt = $pendingNurture?->scheduled_for;
@endphp

<form method="POST" action="{{ url('/leads/lost-reason') }}" data-ajax>
    @csrf
    <input type="hidden" name="lead_id" value="{{ $lead->id }}">

    <div style="padding:12px;border:1px solid var(--c-border);border-radius:10px;background:var(--c-surface-2);margin-bottom:14px;">
        <strong style="display:block;">Lead: {{ $lead->customer_name }}</strong>
        <span class="muted" style="font-size:12px;">Correct the Lost classification and control future re-contact.</span>
    </div>

    <div class="field">
        <label>Lost reason *</label>
        <select name="lost_reason_key" id="elr-reason" class="input" required>
            <option value="">— Select a reason —</option>
            <optgroup label="🔄 Nurture possible — customer may buy later">
                @foreach ($options['nurture'] as $opt)
                    <option value="{{ $opt['key'] }}" @selected($currentKey === $opt['key'])>{{ $opt['label'] }}</option>
                @endforeach
            </optgroup>
            <optgroup label="🚫 Permanently closed — do not reactivate">
                @foreach ($options['closed'] as $opt)
                    <option value="{{ $opt['key'] }}" @selected($currentKey === $opt['key'])>{{ $opt['label'] }}</option>
                @endforeach
            </optgroup>
        </select>
    </div>

    <div id="elr-permanent" style="display:none;padding:10px 12px;border-radius:10px;background:#fff1f2;border:1px solid #fecdd3;color:#9f1239;font-size:12px;margin-bottom:12px;">
        🚫 This is a permanently closed reason. Any pending future reactivation will be cancelled.
    </div>

    <div id="elr-nurture" style="display:none;padding:12px;border:1px solid var(--c-success);border-radius:10px;background:#f0fdf4;margin-bottom:12px;">
        <label style="display:flex;gap:8px;align-items:flex-start;font-weight:700;cursor:pointer;">
            <input type="checkbox" name="nurture_enabled" id="elr-enabled" value="1" @checked($pendingNurture)>
            <span>{{ $pendingNurture ? '🔄 Keep / schedule Nurture' : '🔄 Add to Nurture / Reactivation' }}</span>
        </label>
        <p class="muted" style="margin:6px 0 10px;font-size:12px;">Only select this when future re-contact is intentionally wanted.</p>
        <div id="elr-date-wrap">
            <label>Re-contact date &amp; time *</label>
            <input type="datetime-local" name="nurture_scheduled_at" id="elr-date" class="input"
                   value="{{ $pendingAt ? $pendingAt->format('Y-m-d\TH:i') : now()->addDays(90)->setTime(10,0)->format('Y-m-d\TH:i') }}">
        </div>
    </div>

    <div class="field">
        <label>Additional note <span class="muted" style="font-weight:400;">(optional)</span></label>
        <textarea name="lost_reason" class="input" rows="3" maxlength="2000" placeholder="Useful context for the team...">{{ $lead->lost_reason }}</textarea>
    </div>

    @if ($pendingNurture)
        <p class="muted" style="font-size:12px;margin-top:6px;">Current reactivation: <strong>{{ $pendingAt->format('d M Y, h:i A') }}</strong></p>
    @endif

    <button type="submit" class="btn btn-block">Save Lost Reason</button>
</form>

<script>
(function(){
    var sel=document.getElementById('elr-reason');
    var nurture=document.getElementById('elr-nurture');
    var permanent=document.getElementById('elr-permanent');
    var enabled=document.getElementById('elr-enabled');
    var date=document.getElementById('elr-date');
    var nurtureKeys=@json(array_column($options['nurture'],'key'));
    function sync(){
        var eligible=nurtureKeys.indexOf(sel.value)!==-1;
        nurture.style.display=eligible?'block':'none';
        permanent.style.display=(sel.value&&!eligible)?'block':'none';
        enabled.disabled=!eligible;
        if(!eligible) enabled.checked=false;
        date.disabled=!eligible || !enabled.checked;
        date.required=eligible && enabled.checked;
    }
    sel.addEventListener('change',sync);
    enabled.addEventListener('change',sync);
    sync();
})();
</script>
