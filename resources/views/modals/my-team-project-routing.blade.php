<form method="POST" action="/my-team/projects/{{ $project->id }}/sync" data-ajax>
    @csrf

    <p class="muted" style="margin-bottom:var(--s-3);font-size:13px;">
        Configure how <strong>{{ $project->name }}</strong> is handled by your team.
    </p>

    {{-- ============================================================ --}}
    {{-- TEAMS --}}
    {{-- ============================================================ --}}
    <h4 style="font-size:14px;margin:0 0 4px;">👥 Your Teams</h4>
    <p class="muted" style="font-size:12px;margin-bottom:8px;">
        Routing this project to a team means its members get its leads.
    </p>

    @if ($myTeams->isEmpty())
        <p class="muted" style="font-size:13px;">You have no active teams.</p>
    @else
        <div class="agent-checklist">
            @foreach ($myTeams as $t)
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
    {{-- AGENTS --}}
    {{-- ============================================================ --}}
    <h4 style="font-size:14px;margin:var(--s-4) 0 4px;">👤 Specific Agents
        <span class="muted" style="font-weight:400;font-size:12px;">(highest priority)</span>
    </h4>
    <p class="muted" style="font-size:12px;margin-bottom:8px;">
        These agents get this project's leads first. Others in your team are used only if these are busy.
    </p>

    @if ($myAgents->isEmpty())
        <p class="muted" style="font-size:13px;">You have no active agents.</p>
    @else
        <div class="agent-checklist">
            @foreach ($myAgents as $a)
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

    <button type="submit" class="btn btn-block" style="margin-top:var(--s-4);">
        💾 Save Routing
    </button>

    <p class="muted" style="font-size:11px;margin-top:8px;text-align:center;">
        Unchecked items are automatically removed. Other teams' routing is not affected.
    </p>
</form>

<style>
.agent-checklist {
    max-height: 180px;
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
.agent-check-row:hover { background: var(--c-surface); }
.agent-check-row input[type=checkbox] {
    width: 18px;
    height: 18px;
    min-height: auto;
    flex-shrink: 0;
    accent-color: var(--c-primary);
}
</style>