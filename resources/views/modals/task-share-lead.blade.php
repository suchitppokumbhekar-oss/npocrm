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

        <input type="hidden" name="agent_id" id="task-handover-agent" required>
        <input type="hidden" name="project_id" id="task-handover-project" required>
        <input type="hidden" name="team_id" id="task-handover-team">

        <div class="handover-step" id="handover-agent-step">
            <div class="handover-step-head">
                <span class="handover-step-number">1</span>
                <div>
                    <strong>Who should receive this lead?</strong>
                    <small>Search by team member name.</small>
                </div>
            </div>

            <div class="handover-search-wrap">
                <span class="handover-search-icon">⌕</span>
                <input type="search"
                       id="handover-agent-search"
                       class="input handover-search"
                       placeholder="Search team member…"
                       autocomplete="off"
                       spellcheck="false">
            </div>

            <div id="handover-agent-results" class="handover-results"></div>
            <div id="handover-agent-selected" class="handover-selected" hidden></div>
        </div>

        <div class="handover-step" id="handover-project-step" hidden>
            <div class="handover-step-head">
                <span class="handover-step-number">2</span>
                <div>
                    <strong>Which project?</strong>
                    <small>Only projects this team member can handle are shown.</small>
                </div>
            </div>

            <div class="handover-search-wrap">
                <span class="handover-search-icon">⌕</span>
                <input type="search"
                       id="handover-project-search"
                       class="input handover-search"
                       placeholder="Search project…"
                       autocomplete="off"
                       spellcheck="false">
            </div>

            <div id="handover-project-results" class="handover-results"></div>
            <div id="handover-project-selected" class="handover-selected" hidden></div>
        </div>

        <div class="handover-step" id="handover-team-step" hidden>
            <div class="handover-step-head">
                <span class="handover-step-number">3</span>
                <div>
                    <strong>Which team?</strong>
                    <small>This agent handles the project through more than one team.</small>
                </div>
            </div>

            <div id="handover-team-results" class="handover-results"></div>
        </div>

        <div class="handover-step handover-reason-step" id="handover-reason-step" hidden>
            <div class="handover-step-head">
                <span class="handover-step-number" id="handover-reason-number">3</span>
                <div>
                    <strong>Why are you handing it over?</strong>
                    <small>A short note helps the receiving agent understand the customer.</small>
                </div>
            </div>

            <textarea name="reason"
                      id="handover-reason"
                      class="input"
                      rows="3"
                      maxlength="1000"
                      required
                      placeholder="e.g. Customer wants this project and Rahul handles it."></textarea>
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

    var agentField = document.getElementById('task-handover-agent');
    var projectField = document.getElementById('task-handover-project');
    var teamField = document.getElementById('task-handover-team');

    var agentSearch = document.getElementById('handover-agent-search');
    var projectSearch = document.getElementById('handover-project-search');
    var agentResults = document.getElementById('handover-agent-results');
    var projectResults = document.getElementById('handover-project-results');
    var teamResults = document.getElementById('handover-team-results');

    var agentSelected = document.getElementById('handover-agent-selected');
    var projectSelected = document.getElementById('handover-project-selected');
    var projectStep = document.getElementById('handover-project-step');
    var teamStep = document.getElementById('handover-team-step');
    var reasonStep = document.getElementById('handover-reason-step');
    var reasonNumber = document.getElementById('handover-reason-number');

    var handoverLoading = document.getElementById('task-handover-loading');
    var handoverError = document.getElementById('task-handover-error');
    var handoverForm = document.getElementById('task-handover-form');
    var siteBtn = document.getElementById('task-site-share-btn');
    var siteForm = document.getElementById('task-site-share-form');

    var handoverLoaded = false;
    var handoverAgents = [];
    var projectsByAgent = {};
    var teamsByAgentProject = {};

    if (!sitePanel || !agentPanel) return;

    function esc(value){
        var el = document.createElement('div');
        el.textContent = value == null ? '' : String(value);
        return el.innerHTML;
    }

    function makeChoice(label, meta, handler){
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'handover-choice';

        var text = document.createElement('span');
        var strong = document.createElement('strong');
        strong.textContent = label;
        text.appendChild(strong);

        if (meta) {
            var small = document.createElement('small');
            small.textContent = meta;
            text.appendChild(small);
        }

        var arrow = document.createElement('span');
        arrow.className = 'handover-choice-arrow';
        arrow.textContent = '›';

        btn.appendChild(text);
        btn.appendChild(arrow);
        btn.addEventListener('click', handler);
        return btn;
    }

    function showEmpty(container, message){
        container.innerHTML = '<div class="handover-empty">' + esc(message) + '</div>';
    }

    function showSelected(container, label, meta, changeHandler){
        container.innerHTML =
            '<div class="handover-selected-main">' +
                '<strong>' + esc(label) + '</strong>' +
                (meta ? '<small>' + esc(meta) + '</small>' : '') +
            '</div>';

        var change = document.createElement('button');
        change.type = 'button';
        change.className = 'handover-change';
        change.textContent = 'Change';
        change.addEventListener('click', changeHandler);

        container.appendChild(change);
        container.hidden = false;
    }

    function show(which){
        options.forEach(function(el){ el.style.display = 'none'; });
        sitePanel.hidden = which !== 'site';
        agentPanel.hidden = which !== 'agent';

        if (which === 'agent' && !handoverLoaded) {
            loadHandoverOptions();
        }
    }

    function back(){
        options.forEach(function(el){ el.style.display = 'grid'; });
        sitePanel.hidden = true;
        agentPanel.hidden = true;
    }

    options.forEach(function(el){
        el.addEventListener('click', function(){
            show(el.dataset.shareChoice);
        });
    });

    backs.forEach(function(el){
        el.addEventListener('click', back);
    });

    function renderAgents(query){
        var q = String(query || '').trim().toLowerCase();
        agentResults.innerHTML = '';
        if (!q) {
            showEmpty(agentResults, "Type a name to find a team member.");
            return;
        }


        var matches = handoverAgents.filter(function(agent){
            return !q || String(agent.name || '').toLowerCase().indexOf(q) !== -1;
        });

        if (!matches.length) {
            showEmpty(agentResults, 'No matching team member.');
            return;
        }

        matches.forEach(function(agent){
            agentResults.appendChild(
                makeChoice(
                    agent.name,
                    '',
                    function(){ selectAgent(agent); }
                )
            );
        });
    }

    function resetProjectAndBelow(){
        projectField.value = '';
        teamField.value = '';
        projectSearch.value = '';
        projectSearch.hidden = false;
        projectSelected.hidden = true;
        projectSelected.innerHTML = '';
        projectResults.innerHTML = '';
        teamResults.innerHTML = '';
        teamResults.classList.remove('handover-selected');
        teamStep.hidden = true;
        reasonStep.hidden = true;
        reasonNumber.textContent = '3';
    }

    function selectAgent(agent){
        agentField.value = agent.id;
        agentSearch.value = '';
        agentSearch.hidden = true;
        agentResults.innerHTML = '';

        showSelected(
            agentSelected,
            agent.name,
            'Receiving agent',
            function(){
                agentField.value = '';
                agentSelected.hidden = true;
                agentSelected.innerHTML = '';
                agentSearch.hidden = false;
                resetProjectAndBelow();
                projectStep.hidden = true;
                agentResults.innerHTML = '<div class="handover-empty">Type a name to find a team member.</div>';
                agentSearch.focus();
            }
        );

        resetProjectAndBelow();
        projectStep.hidden = false;
        renderProjects('');
        projectSearch.focus();
    }

    agentSearch.addEventListener('input', function(){
        renderAgents(agentSearch.value);
    });

    function renderProjects(query){
        var agentKey = String(agentField.value || '');
        var projects = projectsByAgent[agentKey] || [];
        var q = String(query || '').trim().toLowerCase();

        projectResults.innerHTML = '';
        if (!q) {
            showEmpty(projectResults, "Type a project name to search.");
            return;
        }


        var matches = projects.filter(function(project){
            return !q || String(project.name || '').toLowerCase().indexOf(q) !== -1;
        });

        if (!matches.length) {
            showEmpty(projectResults, 'No matching eligible project.');
            return;
        }

        matches.forEach(function(project){
            projectResults.appendChild(
                makeChoice(project.name, '', function(){
                    selectProject(project);
                })
            );
        });
    }

    function selectProject(project){
        projectField.value = project.id;
        projectSearch.value = '';
        projectSearch.hidden = true;
        projectResults.innerHTML = '';

        showSelected(
            projectSelected,
            project.name,
            'Destination project',
            function(){
                projectField.value = '';
                teamField.value = '';
                projectSelected.hidden = true;
                projectSelected.innerHTML = '';
                projectSearch.hidden = false;
                teamStep.hidden = true;
                teamResults.innerHTML = '';
                teamResults.classList.remove('handover-selected');
                reasonStep.hidden = true;
                renderProjects('');
                projectSearch.focus();
            }
        );

        resolveTeam();
    }

    projectSearch.addEventListener('input', function(){
        renderProjects(projectSearch.value);
    });

    function resolveTeam(){
        var agentKey = String(agentField.value || '');
        var projectKey = String(projectField.value || '');
        var teams = (teamsByAgentProject[agentKey] || {})[projectKey] || [];

        teamField.value = '';
        teamResults.innerHTML = '';
        teamResults.classList.remove('handover-selected');
        teamStep.hidden = true;
        reasonStep.hidden = true;

        if (teams.length === 0) {
            teamStep.hidden = false;
            reasonNumber.textContent = '4';
            showEmpty(
                teamResults,
                'No eligible destination team is configured for this person and project.'
            );
            return;
        }

        if (teams.length === 1) {
            teamField.value = teams[0].id;
            reasonNumber.textContent = '3';
            reasonStep.hidden = false;
            return;
        }

        reasonNumber.textContent = '4';
        teamStep.hidden = false;

        teams.forEach(function(team){
            teamResults.appendChild(
                makeChoice(team.name, '', function(){
                    teamField.value = team.id;
                    teamResults.innerHTML =
                        '<div class="handover-selected-main">' +
                            '<strong>' + esc(team.name) + '</strong>' +
                            '<small>Destination team selected</small>' +
                        '</div>';
                    teamResults.classList.add('handover-selected');
                    reasonStep.hidden = false;
                })
            );
        });
    }

    async function loadHandoverOptions(){
        var idField = document.getElementById('task-share-followup-id');
        var followupId = idField ? String(idField.value || '').trim() : '';

        if (!followupId) {
            showHandoverError([
                'This lead has no active follow-up available for handover.'
            ]);
            return;
        }

        handoverLoading.hidden = false;
        handoverError.hidden = true;
        handoverForm.hidden = true;

        try {
            var res = await fetch(
                '/modals/task-share-lead/handover-options?followup_id=' + encodeURIComponent(followupId),
                {
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest',
                        'Accept': 'application/json'
                    }
                }
            );

            var data = await res.json().catch(function(){ return {}; });

            if (!res.ok || !data.ok) {
                throw new Error(
                    data.message ||
                    ('Unable to load handover options (HTTP ' + res.status + ').')
                );
            }

            handoverLoading.hidden = true;

            if (!data.available) {
                showHandoverError(
                    data.reasons && data.reasons.length
                        ? data.reasons
                        : ['No eligible team member is currently routed to another active project.']
                );
                handoverLoaded = true;
                return;
            }

            handoverAgents = data.agents || [];
            projectsByAgent = data.projects_by_agent || {};
            teamsByAgentProject = data.teams_by_agent_project || {};

            handoverForm.hidden = false;
            handoverLoaded = true;
            agentResults.innerHTML = '<div class="handover-empty">Type a name to find a team member.</div>';

        } catch (err) {
            handoverLoading.hidden = true;
            showHandoverError([
                'Could not load handover options.',
                err.message || 'Please try again.'
            ]);
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

<style>
/* Guided cross-project handover */
.handover-step{
    margin:0 0 14px;
    padding:14px;
    border:1px solid var(--c-border);
    border-radius:12px;
    background:var(--c-surface);
}
.handover-step-head{
    display:flex;
    align-items:flex-start;
    gap:10px;
    margin-bottom:11px;
}
.handover-step-number{
    display:grid;
    place-items:center;
    flex:0 0 28px;
    width:28px;
    height:28px;
    border-radius:50%;
    background:var(--c-primary);
    color:#fff;
    font-size:12px;
    font-weight:800;
}
.handover-step-head strong{
    display:block;
    font-size:13px;
    line-height:1.3;
}
.handover-step-head small{
    display:block;
    margin-top:2px;
    color:var(--c-muted);
    font-size:11px;
    line-height:1.35;
}
.handover-search-wrap{
    position:relative;
}
.handover-search-icon{
    position:absolute;
    left:11px;
    top:50%;
    transform:translateY(-50%);
    color:var(--c-muted);
    pointer-events:none;
}
.handover-search{
    width:100%;
    padding-left:34px!important;
}
.handover-results{
    display:grid;
    gap:6px;
    margin-top:8px;
    max-height:230px;
    overflow:auto;
}
.handover-choice{
    width:100%;
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:10px;
    min-height:44px;
    padding:10px 12px;
    border:1px solid var(--c-border);
    border-radius:9px;
    background:var(--c-surface);
    color:var(--c-text);
    font:600 12.5px inherit;
    text-align:left;
    cursor:pointer;
}
.handover-choice:hover,
.handover-choice:focus{
    border-color:var(--c-primary);
    background:var(--c-surface-2);
    outline:none;
}
.handover-choice-arrow{
    color:var(--c-muted);
    font-size:17px;
}
.handover-selected{
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:10px;
    padding:10px 12px;
    border:1px solid #b9ddc8;
    border-radius:10px;
    background:#f2fbf5;
}
.handover-selected-main{
    min-width:0;
}
.handover-selected-main strong{
    display:block;
    font-size:13px;
    overflow:hidden;
    text-overflow:ellipsis;
    white-space:nowrap;
}
.handover-selected-main small{
    display:block;
    margin-top:2px;
    color:var(--c-muted);
    font-size:10.5px;
}
.handover-change{
    flex:0 0 auto;
    border:0;
    background:transparent;
    color:var(--c-primary);
    font:700 11px inherit;
    cursor:pointer;
    padding:6px;
}
.handover-empty{
    padding:10px;
    color:var(--c-muted);
    font-size:11.5px;
    text-align:center;
}
.handover-team-auto{
    margin:-4px 0 14px;
    padding:8px 11px;
    border-radius:9px;
    background:var(--c-surface-2);
    color:var(--c-text-2);
    font-size:11px;
}
.handover-reason-step textarea{
    width:100%;
    resize:vertical;
    min-height:86px;
}
@media(max-width:600px){
    .handover-step{
        padding:12px;
        margin-bottom:10px;
    }
    .handover-results{
        max-height:190px;
    }
    .handover-choice{
        min-height:46px;
    }
}
</style>

<style>
/* Share Lead modal: hidden state must always win over component display rules. */
.task-share-missing[hidden],
.task-share-loading[hidden],
.handover-step[hidden],
.handover-selected[hidden],
#task-handover-form[hidden]{
    display:none !important;
}
</style>
