@php
    $reasons = \App\Http\Controllers\ExternalShareController::REASONS;

    // Known group names from past shares, for autocomplete
    $knownGroups = \App\Models\LeadExternalShare::query()
        ->whereNotNull('group_name')
        ->where('group_name', '!=', '')
        ->distinct()
        ->orderBy('group_name')
        ->pluck('group_name')
        ->take(50);

    // Pending tasks on this lead — drives the "close them" checkbox
    $pendingCount = \App\Models\Followup::where('lead_id', $lead->id)
        ->where('status', 'pending')
        ->count();
@endphp

<form method="POST" action="{{ url('/leads/external-share') }}" data-ajax>
    @csrf
    <input type="hidden" name="lead_id" value="{{ $lead->id }}">

    <p class="muted" style="margin-bottom:12px;">
        Record sharing <strong>{{ $lead->customer_name }}</strong> with someone outside the CRM —
        a WhatsApp group, site team, outside agent, etc.
    </p>

    {{-- ---------------- Group name (autocomplete) ---------------- --}}
    <div class="field">
        <label>👥 Group / team name (optional)</label>

        <div class="proj-picker" id="es-group-picker">
            <div class="proj-input-wrap">
                <span class="proj-icon">🔍</span>
                <input type="text"
                       name="group_name"
                       id="es-group-name"
                       class="input proj-search"
                       placeholder="e.g. Kashi Site Sales"
                       autocomplete="off"
                       spellcheck="false"
                       maxlength="150">
                <button type="button" class="proj-clear" id="es-group-clear" hidden aria-label="Clear">✕</button>
            </div>
            @if ($knownGroups->isNotEmpty())
                <div class="proj-results" id="es-group-results" hidden>
                    @foreach ($knownGroups as $g)
                        <button type="button" class="proj-item" data-value="{{ $g }}">
                            <span class="proj-item-name">{{ $g }}</span>
                        </button>
                    @endforeach
                </div>
            @endif
        </div>

        <p class="muted" style="font-size:11px;margin-top:4px;">
            Leave blank if you're not sure which group. Autocompletes from past shares.
        </p>
    </div>

    {{-- ---------------- Reason (preset dropdown) ---------------- --}}
    <div class="field">
        <label>💬 Why are you sharing it?</label>
        <select name="reason_key" class="input" required>
            @foreach ($reasons as $key => $label)
                <option value="{{ $key }}" @selected($key === 'follow_up')>{{ $label }}</option>
            @endforeach
        </select>
    </div>

    {{-- ---------------- Extra notes ---------------- --}}
    <div class="field">
        <label>📝 Extra notes (optional)</label>
        <textarea name="extra_notes" rows="3" class="input" maxlength="2000"
                  placeholder="Anything specific — 'customer coming at 3 PM', 'asked for 2BHK pricing', etc."></textarea>
    </div>

    {{-- ---------------- Close pending tasks ---------------- --}}
    @if ($pendingCount > 0)
        <div class="field" style="background:#fff8e6;border-left:3px solid #f39c12;padding:10px 12px;border-radius:6px;">
            <label style="display:flex;align-items:flex-start;gap:8px;cursor:pointer;font-weight:600;margin:0;">
                <input type="checkbox" name="complete_pending" id="es-complete-pending"
                       value="1" checked
                       style="width:auto;min-height:auto;margin-top:3px;accent-color:var(--c-primary);">
                <span>
                    ✅ Close {{ $pendingCount }} pending task{{ $pendingCount === 1 ? '' : 's' }}
                    <em class="muted" style="display:block;font-size:11px;font-weight:400;margin-top:2px;font-style:normal;">
                        You're handing this lead off, so the previous follow-up no longer applies.
                        Uncheck if you want the task to stay on the board.
                    </em>
                </span>
            </label>
        </div>
    @endif

    {{-- ---------------- Info banner ---------------- --}}
    <div class="field" style="background:#e8f4ff;border-left:3px solid #3498db;padding:10px 12px;border-radius:6px;font-size:13px;">
        💡 A check-back task will be created for <strong>you</strong> in <strong>2 days</strong>.
        When it fires, log what the group or person reported.
    </div>

    <button type="submit" class="btn btn-block">📤 Record External Share</button>
</form>

<script>
(function () {
    'use strict';

    var input    = document.getElementById('es-group-name');
    var results  = document.getElementById('es-group-results');
    var clearBtn = document.getElementById('es-group-clear');
    var picker   = document.getElementById('es-group-picker');
    if (!input || !results || !picker) return;

    var items = Array.prototype.slice.call(results.querySelectorAll('.proj-item'));
    if (!items.length) return;

    function showResults(filter) {
        var q = (filter || '').toLowerCase().trim();
        var found = 0;

        items.forEach(function (el) {
            var label = (el.dataset.value || '').toLowerCase();
            var match = !q || label.indexOf(q) !== -1;
            el.style.display = match ? '' : 'none';
            if (match) found++;
        });

        results.hidden = found === 0;
    }

    input.addEventListener('focus', function () { showResults(input.value); });
    input.addEventListener('input', function () {
        clearBtn.hidden = input.value === '';
        showResults(input.value);
    });
    input.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') { results.hidden = true; input.blur(); }
    });

    results.addEventListener('click', function (e) {
        var item = e.target.closest('.proj-item');
        if (!item) return;
        input.value = item.dataset.value || '';
        clearBtn.hidden = input.value === '';
        results.hidden = true;
    });

    if (clearBtn) {
        clearBtn.addEventListener('click', function (e) {
            e.preventDefault();
            input.value = '';
            clearBtn.hidden = true;
            results.hidden = true;
            input.focus();
        });
    }

    document.addEventListener('click', function (e) {
        if (!picker.contains(e.target)) results.hidden = true;
    });
})();
</script>