@php
    $action = $row
        ? url("/settings/{$type}/save/{$row->id}")
        : url("/settings/{$type}/save");
@endphp

<form method="POST" action="{{ $action }}" data-ajax>
    @csrf

    {{-- ================= KEY ================= --}}
    <div class="field">
        <label>Key * <span class="muted" style="font-weight:400;">(lowercase, used in code — immutable after save)</span></label>
        <input type="text" name="key" class="input" required
               value="{{ $row->key ?? '' }}"
               @if($row && $type === 'activity-types' && $row->is_system) readonly @endif
               pattern="[a-z0-9_\-]+"
               placeholder="e.g., hot_lead">
    </div>

    {{-- ================= LABEL ================= --}}
    <div class="field">
        <label>{{ $type === 'outcomes' ? 'Outcome Text *' : 'Label *' }} <span class="muted" style="font-weight:400;">(shown to users)</span></label>
        <input type="text" name="label" class="input" required value="{{ $row->label ?? '' }}">
    </div>

    {{-- ================= TYPE-SPECIFIC ================= --}}

    @if ($type === 'statuses')
        <div class="field">
            <label>Color</label>
            <select name="color" class="input">
                @foreach (['teal','orange','purple','blue','amber','green','red'] as $c)
                    <option value="{{ $c }}" @selected(($row->color ?? '') === $c)>{{ ucfirst($c) }}</option>
                @endforeach
            </select>
        </div>
        <div class="field">
            <label style="display:flex;align-items:center;gap:8px;">
                <input type="checkbox" name="is_final" value="1"
                       @checked(old('is_final', $row->is_final ?? false))
                       style="width:auto;min-height:auto;">
                Final status (Booking / Lost style)
            </label>
        </div>
    @endif

    @if ($type === 'activity-types')
        <div class="field">
            <label>Icon (emoji)</label>
            <input type="text" name="icon" class="input" maxlength="20" value="{{ $row->icon ?? '' }}">
        </div>
        <div class="field">
            <label style="display:flex;align-items:center;gap:8px;">
                <input type="checkbox" name="requires_outcome" value="1"
                       @checked(old('requires_outcome', $row->requires_outcome ?? false))
                       style="width:auto;min-height:auto;">
                Requires an outcome when logging
            </label>
        </div>
    @endif

    @if ($type === 'outcomes')
        <div class="field">
            <label>Category *</label>
            <select name="category" class="input" required>
                @foreach (['positive','neutral','negative'] as $c)
                    <option value="{{ $c }}" @selected(($row->category ?? '') === $c)>{{ ucfirst($c) }}</option>
                @endforeach
            </select>
        </div>

        <div class="field">
            <label>Automatic Next Action</label>
            <select name="next_action_type_id" class="input">
                <option value="">— none —</option>
                @foreach ($actions as $a)
                    <option value="{{ $a->id }}" @selected(($row->next_action_type_id ?? null) == $a->id)>
                        {{ $a->label }}
                    </option>
                @endforeach
            </select>
        </div>

        <div class="field">
            <label>Automatic Delay (hours) *</label>
            <input type="number" name="next_action_delay_hours" class="input" required min="0" max="20000"
                   value="{{ $row->next_action_delay_hours ?? 24 }}">
            <p class="muted" style="font-size:11px;margin-top:4px;">24 = 1 day · 168 = 7 days · 720 = 30 days. This value is stored in the database and used by automatic follow-up scheduling.</p>
        </div>

        <div class="field">
            <label>Priority *</label>
            <select name="priority" class="input" required>
                @foreach (['low','normal','high'] as $p)
                    <option value="{{ $p }}" @selected(($row->priority ?? 'normal') === $p)>{{ ucfirst($p) }}</option>
                @endforeach
            </select>
        </div>

        <div class="field">
            <label>Suggest Status (optional)</label>
            <select name="suggested_status_id" class="input">
                <option value="">— none —</option>
                @foreach ($statuses as $s)
                    <option value="{{ $s->id }}" @selected(($row->suggested_status_id ?? null) == $s->id)>
                        {{ $s->label }}
                    </option>
                @endforeach
            </select>
        </div>

        <div class="field">
            <label>Work Action Context <span class="muted">(optional — makes this outcome appear only for this action)</span></label>
            <select name="context_action_key" class="input">
                <option value="">— global —</option>
                @foreach ($actions as $a)
                    <option value="{{ $a->key }}" @selected(($row->context_action_key ?? '') === $a->key)>{{ $a->label }}</option>
                @endforeach
                <option value="call" @selected(($row->context_action_key ?? '') === 'call')>📞 Call (all call actions)</option>
            </select>
        </div>

        <div class="field">
            <label>Contact Stage Context <span class="muted">(optional)</span></label>
            <input type="text" name="contact_status_key" class="input" maxlength="50" value="{{ $row->contact_status_key ?? '' }}" placeholder="e.g. interested">
        </div>

        <div class="field">
            <label>Next Action Timing Anchor</label>
            <select name="next_action_anchor" class="input">
                <option value="now" @selected(($row->next_action_anchor ?? 'now') === 'now')>From when this action is completed</option>
                <option value="visit_before" @selected(($row->next_action_anchor ?? 'now') === 'visit_before')>Before the scheduled site visit</option>
                <option value="visit_after" @selected(($row->next_action_anchor ?? 'now') === 'visit_after')>After the scheduled site visit</option>
            </select>
        </div>

        <div class="field">
            <label style="display:flex;align-items:center;gap:8px;">
                <input type="checkbox" name="requires_site_visit_datetime" value="1"
                       @checked(old('requires_site_visit_datetime', $row->requires_site_visit_datetime ?? false))
                       style="width:auto;min-height:auto;">
                Requires actual site visit date & time
            </label>
        </div>
    @endif

    {{-- ================= SHARED ================= --}}
    <div class="field">
        <label style="display:flex;align-items:center;gap:8px;">
            <input type="checkbox" name="is_active" value="1"
                   @checked(old('is_active', $row->is_active ?? true))
                   style="width:auto;min-height:auto;">
            Active
        </label>
    </div>

    <div class="field">
        <label>Sort order <span class="muted" style="font-weight:400;">(lower = earlier; leave blank for auto)</span></label>
        <input type="number" name="sort_order" class="input" min="0"
               value="{{ $row->sort_order ?? '' }}">
    </div>

    <button type="submit" class="btn btn-block">
        {{ $row ? '💾 Update' : '➕ Create' }}
    </button>
</form>