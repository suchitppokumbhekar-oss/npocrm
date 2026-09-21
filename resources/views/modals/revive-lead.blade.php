<form method="POST" action="/leads/revive" data-ajax>
    @csrf
    <input type="hidden" name="lead_id" value="{{ $lead->id }}">

    {{-- Context --}}
    <div class="task-context">
        <div class="task-context-row">
            <span class="label">👤 Lead</span>
            <span class="value"><strong>{{ $lead->customer_name }}</strong></span>
        </div>
        <div class="task-context-row">
            <span class="label">📌 Currently</span>
            <span class="value"><x-status-badge :status="$lead->statusKey()" /></span>
        </div>
        @if ($lead->lost_reason)
            <div class="task-context-row">
                <span class="label">🚫 Was lost because</span>
                <span class="value" style="max-width:60%;">{{ $lead->lost_reason }}</span>
            </div>
        @endif
        <div class="task-context-row">
            <span class="label">⚡ Last activity</span>
            <span class="value">{{ $lead->last_activity_at?->diffForHumans() ?? 'Never' }}</span>
        </div>
    </div>

    {{-- Reason --}}
    <div class="field">
        <label>Why is the customer back? *</label>
        <textarea name="reason" rows="3" class="input" required minlength="3"
                  placeholder="e.g. Customer called back — budget increased, wants to see 3BHK at Raheja"></textarea>
    </div>

    {{-- Resume stage --}}
    <div class="field">
        <label>Resume at stage *</label>
        @if ($resumeOptions->isEmpty())
            <p style="color:var(--c-danger);font-size:13px;">
                No valid resume stage configured. Add transitions in Settings → Statuses.
            </p>
        @else
            <select name="resume_status" class="input" id="rv-status" required>
                @foreach ($resumeOptions as $opt)
                    <option value="{{ $opt->key }}">{{ $opt->label }}</option>
                @endforeach
            </select>
        @endif
    </div>

    {{-- Visit datetime (only if a status requiring datetime is chosen) --}}
    <div class="field" id="rv-visit-field" style="display:none;">
        <label>Visit Date & Time *</label>
        <input type="datetime-local" name="visit_scheduled_at" class="input"
               id="rv-visit-input" value="{{ $defaultVisit }}">
        <p class="muted" style="margin-top:6px;font-size:12px;">
            🕒 Auto-creates 3 reminders: confirmation · feedback · outcome call.
        </p>
    </div>

    <button type="submit" class="btn btn-block" @if($resumeOptions->isEmpty()) disabled @endif>
        🔄 Revive Lead
    </button>
</form>

<style>
.task-context {
    background: var(--c-surface-2);
    border-radius: 8px;
    padding: var(--s-3);
    margin-bottom: var(--s-4);
    font-size: 13px;
}
.task-context-row {
    display: flex;
    justify-content: space-between;
    gap: var(--s-3);
    padding: 3px 0;
    align-items: flex-start;
}
.task-context-row .label { color: var(--c-muted); font-weight: 600; white-space: nowrap; }
.task-context-row .value { color: var(--c-text); text-align: right; }
</style>

<script>
(function () {
    var requiresDateMap = @json($resumeOptions->pluck('requires_datetime', 'key')->toArray() ?? []);
    var statusSel   = document.getElementById('rv-status');
    var visitF      = document.getElementById('rv-visit-field');
    var visitInput  = document.getElementById('rv-visit-input');

    function update() {
        if (!statusSel) return;
        var key = statusSel.value;
        var need = !!requiresDateMap[key];
        visitF.style.display = need ? 'block' : 'none';
        visitInput.required = need;
    }

    if (statusSel) {
        statusSel.addEventListener('change', update);
        update();
    }
})();
</script>