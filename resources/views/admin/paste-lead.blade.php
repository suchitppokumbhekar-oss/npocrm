@extends('layouts.app')

@section('title', 'Paste Lead — NPO CRM')

@section('content')

    <a href="{{ url('/leads') }}" class="back-link">← Back to Leads</a>

    <div class="card">
        <div class="section-head">
            <h2 style="margin:0;">📋 Paste Lead from External Text</h2>
        </div>
        <p class="muted" style="font-size:13px;margin-bottom:var(--s-3);">
            Paste the raw Facebook / Google / Instagram / website lead text below.
            We'll parse it, you review, and create the lead.
        </p>

        <div class="field">
            <label>Raw lead text</label>
            <textarea id="paste-input" rows="12" class="input paste-textarea"
                      placeholder="Facebook Lead via New Projects Online&#10;Campaign: Kashi-New Leads campaign&#10;Adset: Kasi-New Leads ad set&#10;Ad: Kashi-New Leads ad&#10;&#10;Full Name: Atul Singh Nagvanshi&#10;Which Configuration Are You Interested In?: 2_bhk&#10;Phone Number: +918542030404&#10;Would You Like To Schedule A Site Visit?: call_me_to_discuss&#10;Email: atulnagvanshi@gmail.com"></textarea>
        </div>

        <div class="paste-actions">
            <button type="button" class="btn" id="parse-btn">🪄 Parse &amp; Preview</button>
            <button type="button" class="btn btn-ghost" id="clear-btn">✕ Clear</button>
        </div>

        <div id="parse-error" class="alert alert-error" hidden></div>
    </div>

    {{-- ============================================================ --}}
    {{-- PARSED LEAD FORM (hidden until parse) --}}
    {{-- ============================================================ --}}
    <form method="POST" action="{{ url('/admin/paste-lead') }}" id="paste-form" hidden>
        @csrf
        <input type="hidden" name="raw_text" id="raw_text_hidden">
        <input type="hidden" name="campaign" id="campaign_hidden">
        <input type="hidden" name="adset"    id="adset_hidden">
        <input type="hidden" name="ad"       id="ad_hidden">
        <input type="hidden" name="visit_pref" id="visit_pref_hidden">

        <div class="card">
            <div class="section-head" style="margin-bottom:var(--s-3);">
                <h3 style="margin:0;">✅ Parsed Lead — Review &amp; Edit</h3>
                <span class="badge green" id="parse-status">Parsed</span>
            </div>

            {{-- ---------------- Row 1: Basic ---------------- --}}
            <div class="flex">
                <div class="flex-item">
                    <label>👤 Full Name <span class="req">*</span></label>
                    <input type="text" name="customer_name" id="f_customer_name"
                           class="input" required maxlength="255">
                </div>
                <div class="flex-item">
                    <label>📱 Phone <span class="req">*</span></label>
                    <input type="text" name="phone" id="f_phone"
                           class="input" required maxlength="20">
                </div>
                <div class="flex-item">
                    <label>✉️ Email</label>
                    <input type="email" name="email" id="f_email"
                           class="input" maxlength="255">
                </div>
            </div>

            {{-- ---------------- Row 2: Source + Project + Agent ---------------- --}}
            <div class="flex" style="margin-top:var(--s-2);">
                <div class="flex-item">
                    <label>📥 Source <span class="req">*</span></label>
                    <select name="source" id="f_source" class="input" required>
                        @php
                            $sources = app(\App\Services\SettingsService::class)->sources();
                        @endphp
                        @foreach ($sources as $s)
                            <option value="{{ $s->key }}">{{ $s->label }}</option>
                        @endforeach
                    </select>
                </div>
                                <div class="flex-item">
                    <label>🏗️ Project <span class="req">*</span></label>

                    {{-- Hidden native select — real form field --}}
                    <select name="project_id" id="f_project_id" class="hidden-select" required>
                        <option value="">— Select project —</option>
                        @foreach ($projects as $p)
                            <option value="{{ $p->id }}">{{ $p->name }}</option>
                        @endforeach
                    </select>

                    {{-- Searchable combobox on top --}}
                    <div class="proj-picker" id="proj-picker">
                        <div class="proj-input-wrap">
                            <span class="proj-icon">🔍</span>
                            <input type="text"
                                   id="f_project_search"
                                   class="input proj-search"
                                   placeholder="Type to search projects…"
                                   autocomplete="off"
                                   spellcheck="false"
                                   readonly>
                            <button type="button" class="proj-clear" id="f_project_clear" hidden aria-label="Clear">✕</button>
                        </div>
                        <div class="proj-results" id="f_project_results" hidden></div>
                    </div>
                </div>
                <div class="flex-item">
                    <label>👤 Assign to agent</label>
                    <select name="agent_id" id="f_agent_id" class="input">
                        <option value="">— Auto-assign by routing —</option>
                        @foreach ($agents as $a)
                            <option value="{{ $a->id }}">
                                {{ $a->user?->name ?? 'Agent #' . $a->id }}
                            </option>
                        @endforeach
                    </select>
                </div>
            </div>

            {{-- ---------------- Row 3: Tag + Budget ---------------- --}}
            <div class="flex" style="margin-top:var(--s-2);">
                <div class="flex-item">
                    <label>🏷️ Tag</label>
                    <select name="tag_id" id="f_tag_id" class="input">
                        <option value="">— No tag —</option>
                        @foreach ($tags as $t)
                            <option value="{{ $t->id }}">
                                {{ $t->icon }} {{ $t->label }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="flex-item">
                    <label>💰 Budget (₹)</label>
                    <input type="number" name="budget" id="f_budget"
                           class="input" min="0" step="1000">
                </div>
                <div class="flex-item">
                    <label>🏠 Visit preference</label>
                    <input type="text" id="f_visit_display" class="input" readonly
                           placeholder="—">
                </div>
            </div>

            {{-- ---------------- Labels ---------------- --}}
            <div class="field" style="margin-top:var(--s-3);">
                <label>📌 Labels <span class="muted" style="font-weight:400;">— auto-selected from configuration, edit as needed</span></label>

                @php
                    $grouped = $labels->groupBy('group_key');
                @endphp

                @foreach ($grouped as $groupKey => $groupLabels)
                    <details class="label-group" @if ($groupKey === 'property_type') open @endif>
                        <summary class="label-group-head">
                            <span class="lg-arrow">▸</span>
                            <span class="lg-title">{{ $groupLabels->first()->group_label }}</span>
                        </summary>
                        <div class="label-group-body">
                            @foreach ($groupLabels as $lbl)
                                <label class="label-option">
                                    <input type="checkbox" name="label_ids[]"
                                           value="{{ $lbl->id }}"
                                           data-label-key="{{ $lbl->key }}">
                                    <span>{{ $lbl->label }}</span>
                                </label>
                            @endforeach
                        </div>
                    </details>
                @endforeach
            </div>

            {{-- ---------------- Campaign details (from paste) ---------------- --}}
            <div class="field" style="margin-top:var(--s-3);">
                <label>📢 Campaign details <span class="muted" style="font-weight:400;">— from the pasted text, edit if needed</span></label>
                <div class="flex">
                    <div class="flex-item">
                        <label style="font-size:11px;">Campaign</label>
                        <input type="text" id="f_campaign_display" class="input" readonly>
                    </div>
                    <div class="flex-item">
                        <label style="font-size:11px;">Adset</label>
                        <input type="text" id="f_adset_display" class="input" readonly>
                    </div>
                    <div class="flex-item">
                        <label style="font-size:11px;">Ad</label>
                        <input type="text" id="f_ad_display" class="input" readonly>
                    </div>
                </div>
            </div>

            {{-- ---------------- Notes ---------------- --}}
            <div class="field" style="margin-top:var(--s-3);">
                <label>📝 Notes <span class="muted" style="font-weight:400;">— anything else to add</span></label>
                <textarea name="notes" id="f_notes" rows="3" class="input"></textarea>
            </div>

            {{-- ---------------- Save ---------------- --}}
            <div style="margin-top:var(--s-4);display:flex;gap:var(--s-2);flex-wrap:wrap;">
                <button type="submit" class="btn">💾 Create Lead &amp; Schedule Follow-up</button>
                <button type="button" class="btn btn-ghost" id="reparse-btn">🪄 Re-parse</button>
            </div>

            <p class="muted" style="font-size:11px;margin-top:var(--s-2);">
                The pasted raw text will be stored on the lead for audit. A first-contact follow-up will be created for the assigned agent.
            </p>
        </div>
    </form>

@endsection

@push('scripts')
<script>
(function () {
    'use strict';

    var input       = document.getElementById('paste-input');
    var parseBtn    = document.getElementById('parse-btn');
    var clearBtn    = document.getElementById('clear-btn');
    var reparseBtn  = document.getElementById('reparse-btn');
    var errorBox    = document.getElementById('parse-error');
    var form        = document.getElementById('paste-form');
    if (!input || !parseBtn || !form) return;

    // ---------------------------
    // Parser
    // ---------------------------
    var SOURCE_PATTERNS = [
        { rx: /facebook/i,  key: 'facebook_ads' },
        { rx: /instagram/i, key: 'instagram_ads' },
        { rx: /google/i,    key: 'google_ads' },
        { rx: /linkedin/i,  key: 'linkedin_ads' },
        { rx: /website/i,   key: 'website' },
        { rx: /walk.?in/i,  key: 'walk_in' },
    ];

    function normalizePhone(raw) {
        if (!raw) return '';
        var digits = String(raw).replace(/\D/g, '');
        // Strip leading 91 if 12 digits
        if (digits.length === 12 && digits.startsWith('91')) digits = digits.slice(2);
        // Strip leading 0 if 11 digits
        if (digits.length === 11 && digits.startsWith('0')) digits = digits.slice(1);
        return digits;
    }

    function configToLabelKey(config) {
        if (!config) return null;
        var c = String(config).toLowerCase().trim().replace(/[^a-z0-9]/g, '');
        if (c === '1bhk')                 return '1bhk';
        if (c === '2bhk')                 return '2bhk';
        if (c === '3bhk')                 return '3bhk';
        if (c === '4bhk' || c === '4bhkplus' || c === '4bhk+') return '4bhk_plus';
        if (c === 'studio')               return 'studio';
        if (c === 'penthouse')            return 'penthouse';
        if (c === 'plot')                 return 'plot';
        if (c === 'shop')                 return 'shop';
        if (c === 'villa')                return 'villa';
        if (c === 'rowhouse')             return 'row_house';
        if (c === 'officespace')          return 'office_space';
        if (c === 'commercial')           return 'commercial';
        return null;
    }

    function parseText(text) {
        var lines = String(text || '').split(/\r?\n/).map(function (l) { return l.trim(); }).filter(Boolean);
        var out = {
            customer_name: '',
            phone: '',
            email: '',
            source: 'facebook_ads',   // default
            campaign: '',
            adset: '',
            ad: '',
            configuration: '',
            visit_preference: '',
            project_hint: '',
        };

        // Detect source from the first line
        var firstLine = lines[0] || '';
        for (var i = 0; i < SOURCE_PATTERNS.length; i++) {
            if (SOURCE_PATTERNS[i].rx.test(firstLine)) {
                out.source = SOURCE_PATTERNS[i].key;
                break;
            }
        }

        // Extract "via Project Name" from first line
        var via = firstLine.match(/\bvia\s+(.+)$/i);
        if (via) out.project_hint = via[1].replace(/\s+lead$/i, '').trim();

        // Parse "Key: Value" lines
        for (var j = 0; j < lines.length; j++) {
            var line = lines[j];
            var m = line.match(/^([^:]{1,80}):\s*(.+)$/);
            if (!m) continue;
            var key = m[1].trim().toLowerCase();
            var val = m[2].trim();

            if (key === 'campaign')                            out.campaign = val;
            else if (key === 'adset' || key === 'ad set')      out.adset = val;
            else if (key === 'ad')                             out.ad = val;
            else if (key === 'full name' || key === 'name')    out.customer_name = val;
            else if (/phone|mobile|contact number|whatsapp/.test(key)) out.phone = val;
            else if (/email/.test(key))                        out.email = val;
            else if (/configuration|interested|bhk|property type/.test(key)) out.configuration = val;
            else if (/site visit|schedule|visit/.test(key))    out.visit_preference = val;
        }

        return out;
    }

    // ---------------------------
    // Form filling
    // ---------------------------
    function setSelect(id, value) {
        var el = document.getElementById(id);
        if (!el) return;
        for (var i = 0; i < el.options.length; i++) {
            if (el.options[i].value === String(value)) { el.selectedIndex = i; return; }
        }
    }
    /* ---------- Searchable project picker ---------- */
    var projSelect  = document.getElementById('f_project_id');
    var projSearch  = document.getElementById('f_project_search');
    var projResults = document.getElementById('f_project_results');
    var projClear   = document.getElementById('f_project_clear');

    function projCloseResults() {
        if (projResults) projResults.hidden = true;
    }

    function projSetValue(value, label) {
        if (!projSelect || !projSearch) return;
        projSelect.value = value || '';
        projSearch.value = label || '';
        if (projClear) projClear.hidden = !value;
        projCloseResults();
    }

    function projRenderResults(filter) {
        if (!projSelect || !projResults) return;

        var q = (filter || '').toLowerCase().trim();
        projResults.innerHTML = '';
        var found = 0;

        for (var i = 0; i < projSelect.options.length; i++) {
            var opt = projSelect.options[i];
            if (!opt.value) continue;   // skip placeholder
            var label = (opt.text || '').trim();
            if (q && label.toLowerCase().indexOf(q) === -1) continue;

            var btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'proj-item';
            btn.dataset.value = opt.value;
            btn.dataset.label = label;

            var nameSpan = document.createElement('span');
            nameSpan.className = 'proj-item-name';
            nameSpan.textContent = label;

            btn.appendChild(nameSpan);
            projResults.appendChild(btn);
            found++;
        }

        if (found === 0) {
            var empty = document.createElement('div');
            empty.className = 'proj-empty';
            empty.textContent = q ? 'No matching projects' : 'No projects available';
            projResults.appendChild(empty);
        }

        projResults.hidden = false;
    }

    if (projSearch && projSelect) {
        projSearch.addEventListener('focus', function () {
            projSearch.removeAttribute('readonly');
            projRenderResults('');
        });
        projSearch.addEventListener('blur', function () {
            setTimeout(function () { projSearch.setAttribute('readonly', 'readonly'); }, 200);
        });
        projSearch.addEventListener('input', function () {
            if (projClear) projClear.hidden = projSearch.value === '';
            projRenderResults(projSearch.value);
        });
        projSearch.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') {
                projCloseResults();
                projSearch.blur();
            }
        });

        // Click on a result
        projResults.addEventListener('click', function (e) {
            var item = e.target.closest('.proj-item');
            if (!item) return;
            projSetValue(item.dataset.value, item.dataset.label);
        });

        // Click outside closes results
        document.addEventListener('click', function (e) {
            var picker = document.getElementById('proj-picker');
            if (picker && !picker.contains(e.target)) projCloseResults();
        });

        if (projClear) {
            projClear.addEventListener('click', function (e) {
                e.preventDefault();
                projSetValue('', '');
                projSearch.focus();
            });
        }
    }
    function findLabelByKey(key) {
        var cb = form.querySelector('input[type="checkbox"][data-label-key="' + key + '"]');
        return cb;
    }

        function findProjectByName(name) {
        var sel = document.getElementById('f_project_id');
        if (!sel || !name) return null;
        var needle = name.toLowerCase().trim();

        // Split into words and try all of them — "New Projects Online" won't match
        // "Pinnacle Innovative Crystal Kharghar" exactly, but "Projects" might.
        var words = needle.split(/\s+/).filter(function (w) { return w.length > 2; });

        // First pass: full-string contains
        for (var i = 0; i < sel.options.length; i++) {
            var optText = (sel.options[i].text || '').toLowerCase();
            if (optText.indexOf(needle) !== -1) {
                return { value: sel.options[i].value, label: sel.options[i].text };
            }
        }

        // Second pass: any significant word contains
        for (var w = 0; w < words.length; w++) {
            for (var j = 0; j < sel.options.length; j++) {
                var t = (sel.options[j].text || '').toLowerCase();
                if (t.indexOf(words[w]) !== -1) {
                    return { value: sel.options[j].value, label: sel.options[j].text };
                }
            }
        }

        return null;
    }

    function fillForm(data, rawText) {
        document.getElementById('f_customer_name').value = data.customer_name || '';
        document.getElementById('f_phone').value = normalizePhone(data.phone) || '';
        document.getElementById('f_email').value = data.email || '';

        // Source
        setSelect('f_source', data.source);

        // Project — try to auto-select from "via X"
                // Project — try to auto-select from "via X"
        if (data.project_hint) {
            var match = findProjectByName(data.project_hint);
            if (match) {
                projSetValue(match.value, match.label);
            } else {
                // Reset picker so admin picks manually
                projSetValue('', '');
                projSearch.placeholder = 'No auto-match for "' + data.project_hint + '" — search manually';
            }
        } else {
            projSetValue('', '');
        }

        // Tag: auto-set Hot if config mentions BHK? — no, leave empty. Admin decides.
        // But we do auto-set a Hot tag if the source is a paid ad and configuration exists.
        // Leave that policy decision to admin — for now, no auto-tag.

        // Labels — from configuration
        document.querySelectorAll('input[name="label_ids[]"]').forEach(function (cb) {
            cb.checked = false;
        });
        var labelKey = configToLabelKey(data.configuration);
        if (labelKey) {
            var cb = findLabelByKey(labelKey);
            if (cb) {
                cb.checked = true;
                // Auto-open its group
                var details = cb.closest('details');
                if (details) details.open = true;
            }
        }

        // Hidden fields
        document.getElementById('raw_text_hidden').value  = rawText || '';
        document.getElementById('campaign_hidden').value  = data.campaign || '';
        document.getElementById('adset_hidden').value     = data.adset || '';
        document.getElementById('ad_hidden').value        = data.ad || '';
        document.getElementById('visit_pref_hidden').value = data.visit_preference || '';

        // Display-only campaign fields
        document.getElementById('f_campaign_display').value = data.campaign || '—';
        document.getElementById('f_adset_display').value    = data.adset || '—';
        document.getElementById('f_ad_display').value       = data.ad || '—';
        document.getElementById('f_visit_display').value    = data.visit_preference || '—';

        // Notes prefilled with the full paste for context
        var notesEl = document.getElementById('f_notes');
        if (!notesEl.value) {
            // Add a small audit trail note if we detected a configuration mismatch
            var extra = '';
            if (data.configuration && !labelKey) {
                extra = 'Configuration: ' + data.configuration
                      + ' (no matching label in CRM — pick manually)\n';
            }
            notesEl.value = extra;
        }
    }

    // ---------------------------
    // Button handlers
    // ---------------------------
    function doParse() {
        var text = input.value;
        if (!text || text.trim().length < 10) {
            errorBox.textContent = 'Paste some text first.';
            errorBox.hidden = false;
            return;
        }

        errorBox.hidden = true;
        var parsed = parseText(text);

        if (!parsed.customer_name && !parsed.phone) {
            errorBox.textContent = 'Could not find a name or phone in the pasted text. Check the format.';
            errorBox.hidden = false;
            return;
        }

        fillForm(parsed, text);
        form.hidden = false;
        form.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }

    parseBtn.addEventListener('click', doParse);

    if (reparseBtn) {
        reparseBtn.addEventListener('click', function () {
            form.hidden = true;
            input.scrollIntoView({ behavior: 'smooth', block: 'start' });
            input.focus();
        });
    }

    clearBtn.addEventListener('click', function () {
        input.value = '';
        form.hidden = true;
        errorBox.hidden = true;
        input.focus();
    });

})();
</script>
@endpush

@push('head')
<style>
.paste-textarea {
    font-family: ui-monospace, Menlo, Consolas, monospace;
    font-size: 13px;
    line-height: 1.5;
    min-height: 220px;
}
.paste-actions {
    display: flex;
    gap: 8px;
    margin-top: var(--s-3);
    flex-wrap: wrap;
}
@media (max-width: 767px) {
    .paste-actions .btn,
    .paste-actions .btn-ghost {
        flex: 1;
        min-width: 130px;
    }
}
/* ---------- Searchable project picker ---------- */
.hidden-select {
    display: none;
}
.proj-picker {
    position: relative;
}
.proj-input-wrap {
    position: relative;
}
.proj-search {
    padding-left: 34px;
    padding-right: 40px;
    cursor: pointer;
    font-size: 15px;
}
.proj-search:focus {
    cursor: text;
}
.proj-icon {
    position: absolute;
    left: 12px;
    top: 50%;
    transform: translateY(-50%);
    font-size: 13px;
    pointer-events: none;
    line-height: 1;
}
.proj-clear {
    position: absolute;
    top: 50%;
    right: 8px;
    transform: translateY(-50%);
    background: transparent;
    border: 0;
    color: var(--c-muted);
    font-size: 14px;
    cursor: pointer;
    padding: 4px 10px;
    border-radius: 6px;
    line-height: 1;
}
.proj-clear:hover {
    background: var(--c-surface-2);
    color: var(--c-danger);
}
.proj-results {
    position: absolute;
    top: calc(100% + 4px);
    left: 0;
    right: 0;
    background: var(--c-surface);
    border: 1.5px solid var(--c-border);
    border-radius: 8px;
    box-shadow: var(--shadow-lg);
    max-height: 300px;
    overflow-y: auto;
    z-index: 80;
    -webkit-overflow-scrolling: touch;
}
.proj-item {
    display: block;
    width: 100%;
    text-align: left;
    padding: 11px 14px;
    border: 0;
    background: transparent;
    cursor: pointer;
    font-family: inherit;
    font-size: 14px;
    color: var(--c-text);
    border-bottom: 1px solid var(--c-border-2);
    transition: background .1s ease;
}
.proj-item:last-child {
    border-bottom: 0;
}
.proj-item:hover,
.proj-item.active {
    background: var(--c-surface-2);
}
.proj-item-name {
    display: block;
    font-weight: 600;
}
.proj-item-loc {
    display: block;
    font-size: 12px;
    color: var(--c-muted);
    margin-top: 2px;
}
.proj-empty {
    padding: 16px 14px;
    text-align: center;
    color: var(--c-muted);
    font-size: 13px;
}
@media (max-width: 767px) {
    .proj-item { padding: 13px 14px; font-size: 15px; }
    .proj-results { max-height: 260px; }
}
</style>
@endpush