@php
    $action = $team
        ? url("/settings/teams/save/{$team->id}")
        : url("/settings/teams/save");

    $selected = old('agent_ids', $selectedAgentIds ?? []);
@endphp

<form method="POST" action="{{ $action }}" data-ajax>
    @csrf

    <div class="field">
        <label>Team Name *</label>
        <input type="text" name="name" class="input" required maxlength="100"
               value="{{ old('name', $team->name ?? '') }}"
               placeholder="e.g. Mumbai West Team">
    </div>

    <div class="field">
        <label>Description</label>
        <input type="text" name="description" class="input" maxlength="255"
               value="{{ old('description', $team->description ?? '') }}"
               placeholder="Optional">
    </div>

    <div class="field">
        <label>Team Manager</label>
        <select name="manager_user_id" class="input">
            <option value="">— No manager —</option>
            @foreach ($managers as $m)
                <option value="{{ $m->id }}"
                        @selected((string) old('manager_user_id', $team->manager_user_id ?? '') === (string) $m->id)>
                    {{ $m->name }} ({{ $m->role instanceof \App\Enums\UserRole ? $m->role->label() : $m->role }})
                </option>
            @endforeach
        </select>
        <p class="muted" style="font-size:11px;margin-top:4px;">
            Only users with the Admin or Team Manager role appear here.
        </p>
    </div>

    <div class="field">
        <label>Agents in this team</label>
        @if ($allAgents->isEmpty())
            <p class="muted">No active agents available.</p>
        @else
            <div class="agent-checklist">
                @foreach ($allAgents as $agent)
                    <label class="agent-check-row">
                        <input type="checkbox"
                               name="agent_ids[]"
                               value="{{ $agent->id }}"
                               @checked(in_array($agent->id, $selected, true))>
                        <span>
                            {{ $agent->user?->name ?? ('Agent #' . $agent->id) }}
                            @if ($agent->phone)
                                <em class="muted" style="font-size:11px;">· {{ $agent->phone }}</em>
                            @endif
                        </span>
                    </label>
                @endforeach
            </div>
        @endif
    </div>

    <div class="field">
        <label style="display:flex;align-items:center;gap:8px;">
            <input type="checkbox" name="is_active" value="1"
                   @checked(old('is_active', $team->is_active ?? true))
                   style="width:auto;min-height:auto;">
            Active
        </label>
    </div>

    <button type="submit" class="btn btn-block">
        {{ $team ? '💾 Update Team' : '➕ Create Team' }}
    </button>
</form>

<style>
.agent-checklist {
  max-height: 220px;
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
  width: 18px; height: 18px; min-height: auto; flex-shrink: 0;
  accent-color: var(--c-primary);
}
</style>