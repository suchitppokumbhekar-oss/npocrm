<form method="POST" action="{{ url('/leads/lost-reason/bulk') }}" data-ajax>
    @csrf
    <input type="hidden" name="lead_ids" value="{{ $leadIdsCsv }}">

    <div style="padding:12px;border:1px solid var(--c-border);border-radius:10px;background:var(--c-surface-2);margin-bottom:14px;">
        <strong>{{ $selectedCount }} Lost lead{{ $selectedCount === 1 ? '' : 's' }} selected</strong>
        <p class="muted" style="margin:5px 0 0;font-size:12px;">This changes classification only. It does not revive the leads.</p>
    </div>

    <div class="field">
        <label>Set Lost reason *</label>
        <select name="lost_reason_key" class="input" required>
            <option value="">— Select a reason —</option>
            <optgroup label="🔄 Nurture possible">
                @foreach ($lostReasonOptions['nurture'] as $opt)
                    <option value="{{ $opt['key'] }}">{{ $opt['label'] }}</option>
                @endforeach
            </optgroup>
            <optgroup label="🚫 Permanently closed">
                @foreach ($lostReasonOptions['closed'] as $opt)
                    <option value="{{ $opt['key'] }}">{{ $opt['label'] }}</option>
                @endforeach
            </optgroup>
        </select>
    </div>

    <div style="padding:10px 12px;border-radius:10px;background:#fff7ed;border:1px solid #fed7aa;color:#9a3412;font-size:12px;margin-bottom:12px;">
        ⚠️ Choosing a permanently closed reason will cancel any pending future reactivation tasks for the selected leads. Choosing a nurture reason will not create new reactivation tasks automatically.
    </div>

    <button type="submit" class="btn btn-block">Update {{ $selectedCount }} Lead{{ $selectedCount === 1 ? '' : 's' }}</button>
</form>
