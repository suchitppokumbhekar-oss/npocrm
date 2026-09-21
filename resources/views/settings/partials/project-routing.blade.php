<div style="margin-bottom:var(--s-3);">
    <a href="{{ url('/projects') }}" class="back-link">← Back to Projects</a>
</div>

{{-- ============================================================
     PROJECT ROUTING
     ============================================================ --}}
<section class="tab-panel" data-panel="routing">

    {{-- STRICT MODE --}}
    <div class="card">
        <h3>🎯 Project Routing</h3>
        <p class="muted" style="font-size:13px;">
            Assign teams and agents to projects. When a lead arrives for a project,
            it's routed to the least-loaded eligible agent.
            <strong>Direct agents</strong> always take priority over team members.
        </p>

        <form method="POST" action="/settings/routing/strict"
              style="margin-top:var(--s-3);padding:var(--s-3);background:var(--c-surface-2);border-radius:8px;">
            @csrf
            <label style="display:flex;align-items:center;gap:10px;cursor:pointer;">
                <input type="checkbox" name="strict" value="1"
                       @checked(($settingsMap['project_routing_strict'] ?? '1') === '1')
                       style="width:auto;min-height:auto;">
                <span>
                    <strong>Strict routing mode</strong>
                    <span class="muted" style="font-size:12px;display:block;">
                        ON: unrouted projects → lead stays <strong>unassigned</strong>, admins notified.<br>
                        OFF: fall back to global least-loaded agent.
                    </span>
                </span>
            </label>
            <button type="submit" class="btn-small" style="margin-top:var(--s-2);">💾 Save</button>
        </form>
    </div>

    {{-- FILTERS --}}
    <div class="card">
        <form method="GET" action="/settings">
            <input type="hidden" name="tab" value="routing">

            <div class="flex" style="flex-wrap:wrap;gap:var(--s-2);">
                <div class="flex-item" style="min-width:200px;">
                    <label>🔍 Search</label>
                    <input type="text" name="routing_q" class="input"
                           value="{{ $routingFilters['q'] ?? '' }}"
                           placeholder="Project name or location">
                </div>

                <div class="flex-item" style="min-width:180px;">
                    <label>📍 Location</label>
                    <select name="routing_location" class="input">
                        <option value="">All locations</option>
                        @foreach (($routingLocations ?? collect()) as $loc)
                            <option value="{{ $loc }}" @selected(($routingFilters['location'] ?? '') === $loc)>
                                {{ $loc }}
                            </option>
                        @endforeach
                    </select>
                </div>

                <div class="flex-item" style="min-width:160px;">
                    <label>Routing status</label>
                    <select name="routing_filter" class="input">
                        <option value="all"      @selected(($routingFilters['routed'] ?? 'all') === 'all')>All projects</option>
                        <option value="routed"   @selected(($routingFilters['routed'] ?? '') === 'routed')>✅ Routed only</option>
                        <option value="unrouted" @selected(($routingFilters['routed'] ?? '') === 'unrouted')>⚠️ Not routed only</option>
                    </select>
                </div>

                <div class="flex-item" style="min-width:160px;">
                    <label>Agents</label>
                    <select name="routing_agents" class="input">
                        <option value="all"  @selected(($routingFilters['agents'] ?? 'all') === 'all')>Any</option>
                        <option value="has"  @selected(($routingFilters['agents'] ?? '') === 'has')>Has direct agents</option>
                        <option value="none" @selected(($routingFilters['agents'] ?? '') === 'none')>No direct agents</option>
                    </select>
                </div>

                <div class="flex-item" style="min-width:160px;">
                    <label>Teams</label>
                    <select name="routing_teams" class="input">
                        <option value="all"  @selected(($routingFilters['teams'] ?? 'all') === 'all')>Any</option>
                        <option value="has"  @selected(($routingFilters['teams'] ?? '') === 'has')>Has teams</option>
                        <option value="none" @selected(($routingFilters['teams'] ?? '') === 'none')>No teams</option>
                    </select>
                </div>
            </div>

            <div style="margin-top:var(--s-2);display:flex;gap:var(--s-2);flex-wrap:wrap;">
                <button type="submit" class="btn-small btn-info">🔍 Apply</button>
                @if (
                    ($routingFilters['q'] ?? '') ||
                    ($routingFilters['location'] ?? '') ||
                    (($routingFilters['routed'] ?? 'all') !== 'all') ||
                    (($routingFilters['agents'] ?? 'all') !== 'all') ||
                    (($routingFilters['teams'] ?? 'all') !== 'all')
                )
                    <a href="/settings?tab=routing" class="btn-small btn-ghost"
                       style="background:transparent;color:var(--c-text);border:1.5px solid var(--c-border);">
                        Reset
                    </a>
                @endif
            </div>
        </form>
    </div>

        {{-- ============================================================ --}}
    {{-- BULK ASSIGN — TEAM + MEMBER TO SELECTED PROJECTS --}}
    {{-- ============================================================ --}}
    <div class="card" style="border-left:4px solid var(--c-primary);">
        <h4 style="margin:0 0 4px 0;">⚡ Bulk Assign Routing</h4>
        <p class="muted" style="font-size:12px;margin:0 0 var(--s-3) 0;">
            Tick projects in the table below, pick a <strong>team</strong> and
            a <strong>member</strong>, then click <strong>Assign</strong>.
            The member becomes the direct agent for those projects.
            Or switch to <strong>"Matching filter"</strong> to apply to every project the current filters return.
        </p>

        <div class="flex" style="flex-wrap:wrap;gap:var(--s-2);">
            <div class="flex-item" style="min-width:220px;">
                <label>👥 Team <span class="req">*</span></label>
                <select id="bulk-team" class="input" required>
                    <option value="">— pick a team —</option>
                    @foreach (($routingTeams ?? collect()) as $team)
                        <option value="{{ $team->id }}">{{ $team->name }}</option>
                    @endforeach
                </select>
            </div>

            <div class="flex-item" style="min-width:220px;">
                <label>👤 Member <span class="req">*</span></label>
                <select id="bulk-member" class="input" required>
                    <option value="">— pick a team first —</option>
                </select>
            </div>

            <div class="flex-item" style="min-width:220px;">
                <label>Apply to</label>
                <select id="bulk-scope" class="input">
                    <option value="selected">✅ Only checked projects</option>
                    <option value="filter">
                        📋 All {{ number_format($routingMatchingCount ?? 0) }} matching current filter
                    </option>
                </select>
            </div>
        </div>

        <div style="margin-top:var(--s-3);display:flex;gap:var(--s-2);flex-wrap:wrap;align-items:center;">
            <button type="button" id="bulk-assign-btn" class="btn-small btn-info">
                ➕ Assign to selected
            </button>
            <button type="button" id="bulk-remove-btn" class="btn-small"
                    style="background:transparent;color:var(--c-text);border:1.5px solid var(--c-border);">
                ➖ Remove from selected
            </button>
            <button type="button" id="bulk-select-page" class="btn-small"
                    style="background:transparent;color:var(--c-text);border:1.5px solid var(--c-border);">
                Select all on page
            </button>
            <button type="button" id="bulk-clear" class="btn-small"
                    style="background:transparent;color:var(--c-text);border:1.5px solid var(--c-border);">
                Clear
            </button>
            <span id="bulk-count" class="muted" style="font-size:12px;">0 selected</span>
            <span id="bulk-status" class="muted" style="font-size:12px;"></span>
        </div>
    </div>

    {{-- PROJECTS TABLE --}}
    <div class="card">
        <div class="section-head">
            <h4 style="margin:0;">
                📋 Projects
                <span class="muted" style="font-size:12px;">
                    · {{ number_format($routingProjects->total()) }} total
                    @if ($routingProjects->total() > $routingProjects->perPage())
                        · showing {{ $routingProjects->firstItem() }}–{{ $routingProjects->lastItem() }}
                    @endif
                </span>
            </h4>
        </div>

        @if ($routingProjects->isEmpty())
            <p class="muted">No projects match your filter.</p>
        @else
            <div class="table-wrap">
                <table>
                    <tr>
                        <th style="width:36px;text-align:center;">
                            <input type="checkbox" id="bulk-select-all-page"
                                   title="Select all on this page"
                                   style="width:auto;min-height:auto;">
                        </th>
                        <th>Project</th>
                        <th>Location</th>
                        <th style="text-align:center;">👤 Direct</th>
                        <th style="text-align:center;">👥 Teams</th>
                        <th>Status</th>
                        <th>Action</th>
                    </tr>
                    @foreach ($routingProjects as $p)
                        @php
                            $hasRouting = ($p->active_direct_agents_count + $p->active_teams_count) > 0;
                        @endphp
                        <tr>
                            <td style="text-align:center;">
                                <input type="checkbox" class="bulk-project-checkbox"
                                       value="{{ $p->id }}"
                                       style="width:auto;min-height:auto;">
                            </td>
                            <td>
                                <button type="button"
                                        data-modal="edit-project"
                                        data-project="{{ $p->id }}"
                                        title="Click to edit this project"
                                        style="background:transparent;border:0;padding:0;text-align:left;cursor:pointer;font-family:inherit;font-size:inherit;color:var(--c-primary);font-weight:700;text-decoration:underline;">
                                    {{ $p->name }}
                                </button>
                                @if ($p->rera_number)
                                    <br><span class="muted" style="font-size:11px;">RERA: {{ $p->rera_number }}</span>
                                @endif
                            </td>
                            <td>
                                <span class="muted" style="font-size:12px;">{{ $p->location ?? '—' }}</span>
                            </td>
                            <td style="text-align:center;">
                                @if ($p->active_direct_agents_count > 0)
                                    <span class="badge purple">{{ $p->active_direct_agents_count }}</span>
                                @else
                                    <span class="muted">—</span>
                                @endif
                            </td>
                            <td style="text-align:center;">
                                @if ($p->active_teams_count > 0)
                                    <span class="badge blue">{{ $p->active_teams_count }}</span>
                                @else
                                    <span class="muted">—</span>
                                @endif
                            </td>
                            <td>
                                @if ($hasRouting)
                                    <span class="badge green">✅ Routed</span>
                                @else
                                    <span class="badge red">⚠️ No routing</span>
                                @endif
                            </td>
                            <td>
                                <button type="button" class="btn-small btn-info"
                                        data-modal="project-routing"
                                        data-project="{{ $p->id }}">
                                    ⚙️ Configure
                                </button>
                            </td>
                        </tr>
                    @endforeach
                </table>
            </div>

            @if ($routingProjects->hasPages())
                <div style="display:flex;justify-content:space-between;align-items:center;margin-top:var(--s-3);gap:var(--s-2);flex-wrap:wrap;">
                    <div class="muted" style="font-size:12px;">
                        Page {{ $routingProjects->currentPage() }} of {{ $routingProjects->lastPage() }}
                    </div>
                    <div style="display:flex;gap:6px;flex-wrap:wrap;">
                        @if ($routingProjects->onFirstPage())
                            <span class="btn-small" style="opacity:.4;cursor:not-allowed;">← Prev</span>
                        @else
                            <a href="{{ $routingProjects->previousPageUrl() }}" class="btn-small btn-ghost"
                               style="background:transparent;color:var(--c-text);border:1.5px solid var(--c-border);">
                                ← Prev
                            </a>
                        @endif

                        @php
                            $start = max(1, $routingProjects->currentPage() - 2);
                            $end   = min($routingProjects->lastPage(), $routingProjects->currentPage() + 2);
                        @endphp

                        @for ($p = $start; $p <= $end; $p++)
                            @if ($p == $routingProjects->currentPage())
                                <span class="btn-small" style="background:var(--c-primary);color:#fff;">{{ $p }}</span>
                            @else
                                <a href="{{ $routingProjects->url($p) }}" class="btn-small btn-ghost"
                                   style="background:transparent;color:var(--c-text);border:1.5px solid var(--c-border);">
                                    {{ $p }}
                                </a>
                            @endif
                        @endfor

                        @if ($routingProjects->hasMorePages())
                            <a href="{{ $routingProjects->nextPageUrl() }}" class="btn-small btn-ghost"
                               style="background:transparent;color:var(--c-text);border:1.5px solid var(--c-border);">
                                Next →
                            </a>
                        @else
                            <span class="btn-small" style="opacity:.4;cursor:not-allowed;">Next →</span>
                        @endif
                    </div>
                </div>
            @endif
        @endif
    </div>

</section>

{{-- ============================================================ --}}
{{-- ROUTING PAGE JS --}}
{{-- ============================================================ --}}
<script>
(function () {
    'use strict';

    var teamSel   = document.getElementById('bulk-team');
    var memberSel = document.getElementById('bulk-member');
    var scopeSel  = document.getElementById('bulk-scope');
    var statusEl  = document.getElementById('bulk-status');
    var countEl   = document.getElementById('bulk-count');

    if (!teamSel || !scopeSel) return;

    // ---------- Populate member dropdown via AJAX when team changes ----------
    teamSel.addEventListener('change', async function () {
        var teamId = this.value;

        memberSel.innerHTML = '<option value="">— loading members… —</option>';
        memberSel.disabled = true;

        if (!teamId) {
            memberSel.innerHTML = '<option value="">— pick a team first —</option>';
            return;
        }

        try {
            var res = await fetch(
                '/settings/routing/team-members/' + encodeURIComponent(teamId),
                { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } }
            );
            var data = await res.json();

            memberSel.innerHTML = '<option value="">— pick a member —</option>';

            if (!data.success || !data.members || data.members.length === 0) {
                var opt = document.createElement('option');
                opt.value = '';
                opt.textContent = '— team has no members —';
                memberSel.appendChild(opt);
                return;
            }

            data.members.forEach(function (m) {
                var opt = document.createElement('option');
                opt.value = m.id;
                opt.textContent = m.name;
                memberSel.appendChild(opt);
            });
            memberSel.disabled = false;
        } catch (e) {
            console.error(e);
            memberSel.innerHTML = '<option value="">— failed to load —</option>';
        }
    });

    // ---------- Selection counter ----------
    function refreshCount() {
        var n = document.querySelectorAll('.bulk-project-checkbox:checked').length;
        if (countEl) countEl.textContent = n + ' selected';
    }

    document.addEventListener('change', function (e) {
        if (e.target.classList && e.target.classList.contains('bulk-project-checkbox')) {
            refreshCount();
        }
    });

    document.getElementById('bulk-select-page')?.addEventListener('click', function () {
        document.querySelectorAll('.bulk-project-checkbox').forEach(function (b) { b.checked = true; });
        refreshCount();
    });

    document.getElementById('bulk-clear')?.addEventListener('click', function () {
        document.querySelectorAll('.bulk-project-checkbox').forEach(function (b) { b.checked = false; });
        refreshCount();
    });

    // ---------- Submit ----------
    async function submit(action) {
        if (!teamSel.value) {
            alert('Pick a team first.');
            return;
        }
        if (!memberSel.value) {
            alert('Pick a team member.');
            return;
        }

        var payload = {
            team_id:         teamSel.value,
            agent_id:        memberSel.value,
            action:          action,
            use_filter:      scopeSel.value === 'filter' ? 1 : 0,
            filter_q:        @json($routingFilters['q']        ?? ''),
            filter_location: @json($routingFilters['location'] ?? ''),
            filter_routed:   @json($routingFilters['routed']   ?? 'all'),
            filter_agents:   @json($routingFilters['agents']   ?? 'all'),
            filter_teams:    @json($routingFilters['teams']    ?? 'all')
        };

        if (scopeSel.value === 'selected') {
            payload.project_ids = Array.from(
                document.querySelectorAll('.bulk-project-checkbox:checked')
            ).map(function (b) { return b.value; });

            if (payload.project_ids.length === 0) {
                alert('Tick at least one project, or switch scope to "Matching filter".');
                return;
            }
        }

        var confirmMsg = action === 'remove'
            ? 'Remove this team and member from the selected projects?'
            : 'Assign this team AND set the member as direct agent on the selected projects?';
        if (!confirm(confirmMsg)) return;

        if (statusEl) statusEl.textContent = '⏳ Working…';

        try {
            var res = await fetch('{{ route("settings.routing.bulkAssign") }}', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept':       'application/json',
                    'X-CSRF-TOKEN': '{{ csrf_token() }}'
                },
                body: JSON.stringify(payload)
            });

            var data = await res.json();

            if (!res.ok || !data.success) {
                alert('❌ ' + (data.message || 'Failed.'));
                if (statusEl) statusEl.textContent = '';
                return;
            }

            if (statusEl) statusEl.textContent = '✅ ' + data.message + ' Reloading…';
            setTimeout(function () { location.reload(); }, 800);
        } catch (e) {
            console.error(e);
            alert('❌ Network error. Check console.');
            if (statusEl) statusEl.textContent = '';
        }
    }

    document.getElementById('bulk-assign-btn')?.addEventListener('click', function () { submit('assign'); });
    document.getElementById('bulk-remove-btn')?.addEventListener('click', function () { submit('remove'); });

    refreshCount();
})();
</script>