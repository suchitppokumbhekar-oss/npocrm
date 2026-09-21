@php
    $action  = $agent
        ? url("/my-team/agents/save/{$agent->id}")
        : url("/my-team/agents/save");
    $agentUser = $agent?->user;

        // Teams visible to the current user
    //   - Admin: all active teams
    //   - Team manager: only teams they manage
    $myTeams = (session('user_role') === 'admin')
        ? \App\Models\Team::where('is_active', true)->orderBy('name')->get()
        : \App\Models\Team::where('manager_user_id', session('user_id'))
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

    // Currently selected teams for this agent
    $selectedTeamIds = $agent
        ? $agent->teams()->wherePivot('is_active', true)->pluck('teams.id')->all()
        : [];
@endphp

<form method="POST" action="{{ $action }}" data-ajax>
    @csrf

    <p class="muted" style="margin-bottom:var(--s-3);font-size:13px;">
        {{ $agent ? 'Editing' : 'New agent will be added to your team.' }}
    </p>

    {{-- ============================================================ --}}
    {{-- TEAM SELECTION (required — no agent can be without a team) --}}
    {{-- ============================================================ --}}
    @if ($myTeams->isEmpty())
        <div class="alert alert-error" style="margin-bottom:var(--s-3);">
            ⚠️ You don't manage any active teams. Contact your admin before adding agents.
        </div>
    @else
        <div class="field">
            <label>
                Team(s) *
                <span class="muted" style="font-weight:400;">— an agent must belong to at least one team</span>
            </label>

            <div class="agent-checklist">
                @foreach ($myTeams as $t)
                    <label class="agent-check-row">
                        <input type="checkbox"
                               name="team_ids[]"
                               value="{{ $t->id }}"
                               @checked(in_array($t->id, old('team_ids', $selectedTeamIds), true))>
                        <span>
                            {{ $t->name }}
                            <em class="muted" style="font-size:11px;">
                                · {{ $t->agents()->wherePivot('is_active', true)->count() }} agents
                            </em>
                        </span>
                    </label>
                @endforeach
            </div>
        </div>
    @endif

    {{-- ============================================================ --}}
    {{-- PERSONAL DETAILS --}}
    {{-- ============================================================ --}}
    <div class="field">
        <label>Full Name *</label>
        <input type="text" name="name" class="input" required maxlength="100"
               value="{{ old('name', $agentUser?->name) }}">
    </div>

    <div class="field">
        <label>Email *</label>
        <input type="email" name="email" class="input" required maxlength="150"
               value="{{ old('email', $agentUser?->email) }}">
    </div>

    <div class="field">
        <label>Phone *</label>
        <input type="tel" name="phone" class="input" required maxlength="20"
               value="{{ old('phone', $agent->phone ?? '') }}">
    </div>

    <div class="flex">
        <div class="flex-item">
            <label>Personal WhatsApp</label>
            <input type="text" name="whatsapp_personal_number" class="input" maxlength="20"
                   value="{{ old('whatsapp_personal_number', $agent->whatsapp_personal_number ?? '') }}"
                   placeholder="+91 98765 43210">
        </div>
        <div class="flex-item">
            <label>Business WhatsApp</label>
            <input type="text" name="whatsapp_business_number" class="input" maxlength="20"
                   value="{{ old('whatsapp_business_number', $agent->whatsapp_business_number ?? '') }}"
                   placeholder="+91 98765 43210">
        </div>
    </div>
    <p class="muted" style="font-size:11px;margin-top:-4px;">When both are configured, the CRM lets you choose which account you intend to use before opening WhatsApp.</p>

    <div class="flex">
        <div class="flex-item">
            <label>Max Daily Leads</label>
            <input type="number" name="max_daily_leads" class="input"
                   min="1" max="200"
                   value="{{ old('max_daily_leads', $agent->max_daily_leads ?? 10) }}">
        </div>
        <div class="flex-item">
            <label>Status *</label>
            <select name="status" class="input" required>
                <option value="active"   @selected(old('status', $agent->status ?? 'active') === 'active')>Active</option>
                <option value="inactive" @selected(old('status', $agent->status ?? '') === 'inactive')>Inactive</option>
            </select>
        </div>
    </div>

    <div class="field">
        <label>
            Password {{ $agent ? '' : '*' }}
            @if ($agent)
                <span class="muted" style="font-weight:400;">— leave blank to keep current</span>
            @endif
        </label>
        <input type="password" name="password" class="input" minlength="6"
               {{ $agent ? '' : 'required' }}
               placeholder="{{ $agent ? '••••••••' : 'Minimum 6 characters' }}">
    </div>

    <button type="submit" class="btn btn-block"
            @if ($myTeams->isEmpty()) disabled @endif>
        {{ $agent ? '💾 Update Agent' : '➕ Add to My Team' }}
    </button>
</form>

<style>
.agent-checklist {
    max-height: 160px;
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