@php
    $currentAgentIds = $project->activeDirectAgents->pluck('id')->all();
    $currentTeamIds  = $project->activeTeams->pluck('id')->all();
@endphp

<form method="POST" action="/settings/projects/{{ $project->id }}/routing/sync" data-ajax>
    @csrf

    {{-- ============================================================ --}}
    {{-- DIRECT AGENTS --}}
    {{-- ============================================================ --}}
    <h4 style="font-size:14px;margin:0 0 4px;">👤 Direct Agents
        <span class="muted" style="font-weight:400;font-size:12px;">(highest priority)</span>
    </h4>
    <p class="muted" style="font-size:12px;margin-bottom:8px;">
        These specific agents are picked first for this project's leads.
    </p>

    @if ($routingAgents->isEmpty())
        <p class="muted" style="font-size:13px;">No active agents available.</p>
    @else
        <div class="agent-checklist">
            @foreach ($routingAgents as $a)
                <label class="agent-check-row">
                    <input type="checkbox"
                           name="agent_ids[]"
                           value="{{ $a->id }}"
                           @checked(in_array($a->id, $currentAgentIds, true))>
                    <span>{{ $a->user?->name ?? 'Agent #' . $a->id }}</span>
                </label>
            @endforeach
        </div>
    @endif

    {{-- ============================================================ --}}
    {{-- TEAMS --}}
    {{-- ============================================================ --}}
    <h4 style="font-size:14px;margin:var(--s-4) 0 4px;">👥 Teams
        <span class="muted" style="font-weight:400;font-size:12px;">(fallback)</span>
    </h4>
    <p class="muted" style="font-size:12px;margin-bottom:8px;">
        If no direct agent matches, leads go to the least-loaded member of these teams.
    </p>

    @if ($routingTeams->isEmpty())
        <p class="muted" style="font-size:13px;">No active teams available.</p>
    @else
        <div class="agent-checklist">
            @foreach ($routingTeams as $t)
                <label class="agent-check-row">
                    <input type="checkbox"
                           name="team_ids[]"
                           value="{{ $t->id }}"
                           @checked(in_array($t->id, $currentTeamIds, true))>
                    <span>
                        {{ $t->name }}
                        <em class="muted" style="font-size:11px;">
                            · {{ $t->agents()->wherePivot('is_active', true)->count() }} agents
                        </em>
                    </span>
                </label>
            @endforeach
        </div>
    @endif

    {{-- ============================================================ --}}
    {{-- SUBMIT --}}
    {{-- ============================================================ --}}
    <button type="submit" class="btn btn-block" style="margin-top:var(--s-4);">
        💾 Save Routing
    </button>

    <p class="muted" style="font-size:11px;margin-top:8px;text-align:center;">
        Unchecked items are automatically removed.
    </p>
</form>

<style>
/* Reuse the existing agent-checklist styles if they exist */
.agent-checklist {
    max-height: 200px;
    overflow-y: auto;
    border: 1.5px solid var(--c-border);
    border-radius: 8px;
    padding: var(--s-2);
    background: var(--c-surface-2);
}
.agent-check-row {
    display: flex;
    align-items: center;
    gap: var(--s-2);
    padding: 6px 4px;
    font-size: 14px;
    cursor: pointer;
    border-radius: 4px;
}
.agent-check-row:hover {
    background: var(--c-surface);
}
.agent-check-row input[type=checkbox] {
    width: 18px;
    height: 18px;
    min-height: auto;
    flex-shrink: 0;
    accent-color: var(--c-primary);
}
</style>