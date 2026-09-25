<input type="hidden" id="task-share-followup-id" value="{{ $followup->id }}">

<div class="task-share-options" aria-label="Share options">
    <button type="button" class="task-share-option is-site" data-share-choice="site">
        <span class="task-share-option-icon">📲</span>
        <span>
            <strong>Site WhatsApp</strong>
            <small>Send to the site team. Your current follow-up is completed and a check-back is set.</small>
        </span>
        <span class="task-share-chevron">›</span>
    </button>

    <button type="button" class="task-share-option is-agent" data-share-choice="agent" data-followup-id="{{ $followup->id }}">
        <span class="task-share-option-icon">👤</span>
        <span>
            <strong>Other project agent</strong>
            <small>Create a new lead for a company agent handling another project.</small>
        </span>
        <span class="task-share-chevron">›</span>
    </button>
</div>

<div id="task-share-site-panel" class="task-share-panel" hidden>
    <div class="task-share-panel-head">
        <button type="button" class="task-share-back" data-share-back aria-label="Back to share options">←</button>
        <div>
            <strong>Site WhatsApp</strong>
            <small>Send this lead to the site team and finish the current follow-up.</small>
        </div>
    </div>

    <form id="task-site-share-form" novalidate>
        @csrf
        <input type="hidden" name="followup_id" value="{{ $followup->id }}">
        @if (request()->boolean('allow_early'))<input type="hidden" name="allow_early" value="1">@endif
        @if (request()->filled('return_to'))<input type="hidden" name="return_to" value="{{ request('return_to') }}">@endif

        <div class="task-share-mobile-field">
            <label for="task-site-group">Group name <span class="muted">(optional)</span></label>
            <input type="text" name="group_name" id="task-site-group" class="input" maxlength="150"
                   list="task-site-groups" placeholder="e.g. Kashi Site Sales" autocomplete="off" spellcheck="false">
            <datalist id="task-site-groups">
                @foreach ($knownGroups as $group)
                    <option value="{{ $group }}"></option>
                @endforeach
            </datalist>
            <small class="task-share-field-help">Choose the actual group in WhatsApp.</small>
        </div>

        <div class="task-share-mobile-field task-share-message-field">
            <label for="task-site-message">Message</label>
            <textarea name="message" id="task-site-message" rows="6" class="input" maxlength="5000">{{ "🔔 New Lead

👤 *{$lead->customer_name}*
📱 {$lead->phone}" . ($lead->email ? "
✉️ {$lead->email}" : '') . ($lead->project?->name ? "
🏗️ {$lead->project->name}" : '') . "

Please call and follow up with this lead." }}</textarea>
        </div>

        <div class="task-share-action-wrap">
            <button type="button" id="task-site-share-btn" class="task-share-primary">
                <span>📲</span> Open WhatsApp &amp; Finish
            </button>
            <small class="task-share-action-help">CRM will record the share, complete this follow-up and set your 2-day check-back.</small>
        </div>
    </form>
</div>

<div id="task-share-agent-panel" class="task-share-panel" hidden>
    <div class="task-share-panel-head">
        <button type="button" class="task-share-back" data-share-back>←</button>
        <div>
            <strong>Hand Over to Other Project Agent</strong>
            <small>This creates a NEW lead for the selected project and receiver. The original lead remains with its original project/owner.</small>
        </div>
    </div>

    <div id="task-handover-loading" class="task-share-loading">Loading eligible team members…</div>
    <div id="task-handover-error" class="task-share-missing" hidden></div>

    <form method="POST" action="/followups/handover-agent" data-ajax id="task-handover-form" hidden>
        @csrf
        <input type="hidden" name="followup_id" value="{{ $followup->id }}">
        @if (request()->boolean('allow_early'))<input type="hidden" name="allow_early" value="1">@endif
        @if (request()->filled('return_to'))<input type="hidden" name="return_to" value="{{ request('return_to') }}">@endif

        <div class="field">
            <label>Team member <span class="req">*</span></label>
            <select name="agent_id" id="task-handover-agent" class="input" required>
                <option value="">Select team member</option>
            </select>
        </div>

        <div class="field">
            <label>Destination team <span class="req">*</span></label>
            <select name="team_id" id="task-handover-team" class="input" required disabled>
                <option value="">Select team member first</option>
            </select>

            <label style="margin-top:10px;display:block;">Project to hand over for <span class="req">*</span></label>
            <select name="project_id" id="task-handover-project" class="input" required disabled>
                <option value="">Select team member first</option>
            </select>
            <p class="muted" style="font-size:11px;margin-top:4px;">The selected agent must belong to the selected team and the project must be routed to that team/agent.</p>
        </div>

        <div class="field">
            <label>Reason for handover <span class="req">*</span></label>
            <textarea name="reason" class="input" rows="3" maxlength="1000" required
                      placeholder="e.g. Customer is interested in XYZ project, which Rahul handles."></textarea>
        </div>

        <div class="task-share-note">🆕 A separate new lead will be created for the receiving agent. It starts at New, gets its own follow-up, and links back to this lead in the timeline. The current follow-up on this lead is completed as the handover action.</div>

        <button type="submit" class="task-share-primary is-transfer">
            <span>🆕</span> Create New Lead &amp; Hand Over
        </button>
    </form>
</div>

<style>
.task-share-intro{padding:12px 14px;border:1px solid var(--c-border);background:var(--c-surface-2);border-radius:12px;margin-bottom:14px}
.task-share-lead-name{font-size:17px;font-weight:800;color:var(--c-text)}
.task-share-project{font-size:13px;color:var(--c-text-2);margin-top:2px}
.task-share-task{font-size:12px;color:var(--c-muted);margin-top:7px}
.task-share-options{display:grid;gap:10px}
.task-share-option{width:100%;display:grid;grid-template-columns:44px 1fr 22px;align-items:center;text-align:left;gap:10px;padding:14px;border:1px solid var(--c-border);border-radius:12px;background:var(--c-surface);cursor:pointer;font-family:inherit;color:var(--c-text);transition:all .15s ease}
.task-share-option:hover{transform:translateY(-1px);box-shadow:0 5px 16px rgba(0,0,0,.07);border-color:var(--c-primary)}
.task-share-option.is-site:hover{border-color:#25D366;background:#f4fff8}
.task-share-option.is-agent:hover{border-color:#2563eb;background:#f5f8ff}
.task-share-option-icon{width:42px;height:42px;border-radius:11px;display:grid;place-items:center;font-size:21px;background:var(--c-surface-2)}
.task-share-option strong{display:block;font-size:13.5px;line-height:1.25}
.task-share-option small{display:block;margin-top:4px;font-size:11.5px;line-height:1.4;color:var(--c-muted);font-weight:500}
.task-share-chevron{font-size:24px;color:var(--c-muted);text-align:center}
.task-share-option-disabled{cursor:not-allowed;background:var(--c-surface-2);opacity:.92}
.task-share-option-disabled:hover{transform:none;box-shadow:none;border-color:var(--c-border)}
.task-share-option-disabled .task-share-option-icon{opacity:.72}
.task-share-unavailable{color:#a16207!important;font-weight:700!important}
.task-share-missing{display:block;margin-top:7px;padding:8px 9px;border-radius:8px;background:#fff8e8;border:1px solid #f2dfac;color:#765719;font-size:11px;line-height:1.45}
.task-share-missing b{display:block;margin-bottom:3px}
.task-share-missing span{display:block}
.task-share-lock{font-size:15px;opacity:.7;text-align:center}
.task-share-panel{margin-top:2px}
.task-share-mobile-field{margin-bottom:14px}
.task-share-mobile-field label{display:block;margin-bottom:6px;font-size:12px;font-weight:700;color:var(--c-text-2)}
.task-share-field-help{display:block;margin-top:5px;font-size:11px;color:var(--c-muted);line-height:1.35}
.task-share-message-field textarea{min-height:132px;resize:vertical;line-height:1.45}
.task-share-action-wrap{position:sticky;bottom:0;margin:4px -2px -2px;padding:10px 2px max(2px,env(safe-area-inset-bottom));background:linear-gradient(to bottom,rgba(255,255,255,0),var(--c-surface) 18%);z-index:2}
.task-share-action-help{display:block;text-align:center;margin:7px auto 0;max-width:420px;font-size:10.5px;line-height:1.35;color:var(--c-muted)}
.task-share-panel input,.task-share-panel select,.task-share-panel textarea{scroll-margin-top:90px;scroll-margin-bottom:150px}
.task-share-loading{padding:14px 12px;border:1px dashed var(--c-border);border-radius:10px;color:var(--c-muted);font-size:12px;text-align:center}
.task-share-panel-head{display:flex;gap:10px;align-items:flex-start;padding:11px 12px;margin-bottom:14px;border-radius:11px;background:var(--c-surface-2);border:1px solid var(--c-border)}
.task-share-panel-head strong{display:block;font-size:14px}
.task-share-panel-head small{display:block;margin-top:3px;color:var(--c-muted);font-size:11.5px;line-height:1.4}
.task-share-back{width:34px;height:34px;flex:0 0 34px;border:1px solid var(--c-border);border-radius:9px;background:var(--c-surface);cursor:pointer;font-size:18px;color:var(--c-text)}
.task-share-note{padding:10px 12px;margin:10px 0 14px;border-radius:9px;background:#eef7ff;border:1px solid #cfe7fb;color:#245b7a;font-size:11.5px;line-height:1.45}
.task-share-primary{width:100%;min-height:46px;border:0;border-radius:10px;background:#25D366;color:#fff;font:800 14px inherit;cursor:pointer;box-shadow:0 3px 10px rgba(37,211,102,.25)}
.task-share-primary:hover{background:#1ebe5b}
.task-share-primary.is-transfer{background:var(--c-primary);box-shadow:none}
.task-share-primary.is-transfer:hover{filter:brightness(.95)}
@media(max-width:600px){
.task-share-option{grid-template-columns:40px 1fr 18px;padding:12px;min-height:64px}
.task-share-option-icon{width:38px;height:38px}
.task-share-option strong{font-size:13px}
.task-share-option small{font-size:11px}
.task-share-panel-head{padding:9px 10px;margin-bottom:12px}
.task-share-panel-head small{font-size:11px}
.task-share-mobile-field{margin-bottom:12px}
.task-share-message-field textarea{min-height:118px;max-height:34vh}
.task-share-primary{min-height:48px;font-size:14px}
}

</style>

<script>
(function(){
    var sitePanel = document.getElementById('task-share-site-panel');
    var agentPanel = document.getElementById('task-share-agent-panel');
    var options = document.querySelectorAll('[data-share-choice]');
    var backs = document.querySelectorAll('[data-share-back]');
    var agentSel = document.getElementById('task-handover-agent');
    var projectSel = document.getElementById('task-handover-project');
    var teamSel = document.getElementById('task-handover-team');
    var handoverLoading = document.getElementById('task-handover-loading');
    var handoverError = document.getElementById('task-handover-error');
    var handoverForm = document.getElementById('task-handover-form');
    var siteBtn = document.getElementById('task-site-share-btn');
    var siteForm = document.getElementById('task-site-share-form');
    var handoverLoaded = false;
    if (!sitePanel || !agentPanel) return;

    function show(which){
        options.forEach(function(el){ el.style.display='none'; });
        sitePanel.hidden = which !== 'site';
        agentPanel.hidden = which !== 'agent';
        if (which === 'agent' && !handoverLoaded) loadHandoverOptions();
    }
    function back(){
        options.forEach(function(el){ el.style.display='grid'; });
        sitePanel.hidden = true;
        agentPanel.hidden = true;
    }
    options.forEach(function(el){ el.addEventListener('click', function(){ show(el.dataset.shareChoice); }); });
    backs.forEach(function(el){ el.addEventListener('click', back); });

    async function loadHandoverOptions(){
        // The follow-up is already resolved server-side for this modal.
        // Read it from the hidden canonical field instead of depending on
        // DOM dataset casing / button attributes.
        var idField = document.getElementById('task-share-followup-id');
        var followupId = idField ? String(idField.value || '').trim() : '';
        if (!followupId) {
            showHandoverError(['This lead has no active follow-up available for handover. Complete or schedule a follow-up first.']);
            return;
        }
        handoverLoading.hidden = false;
        handoverError.hidden = true;
        handoverForm.hidden = true;
        try {
            var res = await fetch('/modals/task-share-lead/handover-options?followup_id=' + encodeURIComponent(followupId), {
                headers: {'X-Requested-With':'XMLHttpRequest','Accept':'application/json'}
            });
            var data = await res.json().catch(function(){ return {}; });
            if (!res.ok || !data.ok) {
                throw new Error(data.message || ('Unable to load handover options (HTTP ' + res.status + ').'));
            }
            handoverLoading.hidden = true;
            if (!data.available) {
                showHandoverError(data.reasons && data.reasons.length ? data.reasons : ['No eligible team member is currently routed to another active project.']);
                handoverLoaded = true;
                return;
            }
            agentSel.innerHTML = '<option value="">Select team member</option>';
            Object.keys(data.projects_by_agent || {}).forEach(function(){ /* preserve returned mapping below */ });
            (data.agents || []).forEach(function(agent){
                var opt = document.createElement('option');
                opt.value = agent.id;
                opt.textContent = agent.name + (agent.role === 'team_manager' ? ' (Manager)' : '');
                agentSel.appendChild(opt);
            });
            var projectsByAgent = data.projects_by_agent || {};
            var teamsByAgentProject = data.teams_by_agent_project || {};
            agentSel.onchange = function(){
                var agentKey = String(agentSel.value || '');
                var list = projectsByAgent[agentKey] || [];
                projectSel.innerHTML = '<option value="">Select project</option>';
                teamSel.innerHTML = '<option value="">Select project first</option>';
                teamSel.disabled = true;
                list.forEach(function(item){
                    var opt = document.createElement('option'); opt.value = item.id; opt.textContent = item.name; projectSel.appendChild(opt);
                });
                projectSel.disabled = list.length === 0;
            };
            projectSel.onchange = function(){
                var agentKey = String(agentSel.value || '');
                var projectKey = String(projectSel.value || '');
                var teams = (teamsByAgentProject[agentKey] || {})[projectKey] || [];
                teamSel.innerHTML = '<option value="">Select destination team</option>';
                teams.forEach(function(item){
                    var opt=document.createElement('option'); opt.value=item.id; opt.textContent=item.name; teamSel.appendChild(opt);
                });
                teamSel.disabled = teams.length === 0;
            };
            handoverForm.hidden = false;
            handoverLoaded = true;
        } catch (err) {
            handoverLoading.hidden = true;
            showHandoverError(['Could not load handover options.', err.message || 'Please try again.']);
            handoverLoaded = false;
        }
    }

    function showHandoverError(reasons){
        handoverError.hidden = false;
        handoverError.innerHTML = '<b>Why this is not available:</b>' + reasons.map(function(r){ return '<span>• ' + String(r).replace(/[&<>]/g, function(ch){return {'&':'&amp;','<':'&lt;','>':'&gt;'}[ch];}) + '</span>'; }).join('');
    }

    if (siteBtn && siteForm) {
        var shareReturnUrl = null;

        siteBtn.addEventListener('click', async function(){
            if (siteBtn.disabled) return;

            var group = (siteForm.querySelector('[name="group_name"]').value || '').trim();
            var message = siteForm.querySelector('[name="message"]').value || '';

            if (!message.trim()) {
                alert('Please enter a WhatsApp message.');
                siteForm.querySelector('[name="message"]').focus();
                return;
            }

            if (!window.NpoWhatsApp || typeof window.NpoWhatsApp.choose !== 'function') {
                alert('WhatsApp is not available. Please refresh the CRM page and try again.');
                return;
            }

            siteBtn.disabled = true;
            siteBtn.innerHTML = '<span>⏳</span> Preparing…';

            try {
                var fd = new FormData();
                fd.append('_token', siteForm.querySelector('[name="_token"]').value);
                fd.append('lead_id', '{{ $lead->id }}');
                fd.append('followup_id', '{{ $followup->id }}');
                @if (request()->boolean('allow_early'))
                fd.append('allow_early', '1');
                @endif
                fd.append('group_name', group);
                fd.append('reason_key', 'follow_up');
                fd.append('extra_notes', message);
                fd.append('complete_pending', '1');
                var returnTo = siteForm.querySelector('[name="return_to"]');
                if (returnTo && returnTo.value) fd.append('return_to', returnTo.value);

                var res = await fetch('/leads/external-share', {
                    method:'POST', body:fd,
                    headers:{'X-Requested-With':'XMLHttpRequest','Accept':'application/json'}
                });
                var data = await res.json().catch(function(){ return {}; });
                if (!res.ok || !data.ok) {
                    throw new Error(data.message || 'Could not record the share.');
                }

                // The share is already committed server-side. Keep this CRM
                // tab as the work surface and open WhatsApp separately.
                // Returning to CRM must not depend on WhatsApp closing.
                shareReturnUrl = data.completion_url || data.redirect_url || ('/leads/{{ $lead->id }}?task_completed=1&completed_followup_id={{ $followup->id }}&lead_tab=history#lead-wb-completion');
                siteBtn.innerHTML = '<span>📲</span> Opening WhatsApp…';
                window.NpoWhatsApp.choose('', message, function () {
                    if (shareReturnUrl) window.location.href = shareReturnUrl;
                });
            } catch(err) {
                siteBtn.disabled = false;
                siteBtn.innerHTML = '<span>📲</span> Open WhatsApp &amp; Finish';
                alert('❌ ' + (err.message || 'Could not record the share.'));
            }
        });
    }
})();
</script>
