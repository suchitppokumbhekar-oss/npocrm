@php
    $action = $agent
        ? url("/settings/agents/save/{$agent->id}")
        : url("/settings/agents/save");

    $agentUser = $agent?->user;
    $selected  = old('team_ids', $selectedTeamIds ?? []);
@endphp

<form method="POST" action="{{ $action }}" data-ajax>
    @csrf

    {{-- ============================================================ --}}
    {{-- USER ACCOUNT --}}
    {{-- ============================================================ --}}
    <p style="font-weight:700;font-size:13px;margin-bottom:var(--s-2);">👤 User Account</p>

    <div class="field">
        <label>Full Name *</label>
        <input type="text" name="name" class="input" required maxlength="100"
               value="{{ old('name', $agentUser?->name) }}">
    </div>

    <div class="field">
        <label>Email * <span class="muted" style="font-weight:400;">(used for login)</span></label>
        <input type="email" name="email" class="input" required maxlength="150"
               value="{{ old('email', $agentUser?->email) }}">
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

    {{-- ============================================================ --}}
    {{-- AGENT PROFILE --}}
    {{-- ============================================================ --}}
    <hr style="border:0;border-top:1px dashed var(--c-border);margin:var(--s-3) 0;">

    <p style="font-weight:700;font-size:13px;margin-bottom:var(--s-2);">📞 Agent Profile</p>

    <div class="field">
        <label>Phone *</label>
        <input type="text" name="phone" class="input" required maxlength="20"
               value="{{ old('phone', $agent->phone ?? '') }}"
               placeholder="+91 98765 43210">
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

    <div class="field">
        <label>Max Daily Leads</label>
        <input type="number" name="max_daily_leads" class="input"
               min="1" max="200"
               value="{{ old('max_daily_leads', $agent->max_daily_leads ?? 10) }}">
        <p class="muted" style="font-size:11px;margin-top:4px;">
            Auto‑assignment picks the least loaded agent below this limit.
        </p>
    </div>

    <div class="field">
        <label>Status *</label>
        <select name="status" class="input" required>
            <option value="active"   @selected(old('status', $agent->status ?? 'active') === 'active')>Active</option>
            <option value="inactive" @selected(old('status', $agent->status ?? '') === 'inactive')>Inactive</option>
        </select>
    </div>
    <div class="field">
        <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-weight:600;">
            <input type="checkbox" name="is_telecaller" value="1"
                   @checked(old('is_telecaller', $agentUser?->is_telecaller ?? false))
                   style="width:18px;height:18px;min-height:auto;accent-color:var(--c-primary);">
            <span>📞 Telecaller — can access Contacts &amp; Dialer</span>
        </label>
        <p class="muted" style="font-size:11px;margin-top:4px;">
            Admins and team managers always have access. This flag only affects agents.
        </p>
    </div>
    <div class="field">
        <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-weight:600;">
            <input type="checkbox" name="is_on_payroll" value="1"
                   @checked(old('is_on_payroll', $agentUser?->is_on_payroll ?? false))
                   style="width:18px;height:18px;min-height:auto;accent-color:var(--c-primary);">
            <span>🏢 On payroll — required to mark daily attendance</span>
        </label>
        <p class="muted" style="font-size:11px;margin-top:4px;">
            Uncheck for freelancers, referral agents, or anyone not on the company roster.
            They can still use the CRM normally — only attendance is skipped.
        </p>
    </div>    
    {{-- ============================================================ --}}
    {{-- TEAM MEMBERSHIP --}}
    {{-- ============================================================ --}}
    @if ($allTeams->isNotEmpty())
        <hr style="border:0;border-top:1px dashed var(--c-border);margin:var(--s-3) 0;">

        <p style="font-weight:700;font-size:13px;margin-bottom:var(--s-2);">👥 Teams</p>

        <div class="field">
            <label>Tick the teams this agent belongs to</label>
            <div class="team-checklist">
                @foreach ($allTeams as $team)
                    <label class="team-check-row">
                        <input type="checkbox" name="team_ids[]" value="{{ $team->id }}"
                               @checked(in_array($team->id, $selected, true))>
                        <span>{{ $team->name }}</span>
                    </label>
                @endforeach
            </div>
        </div>
    @endif

    <button type="submit" class="btn btn-block">
        {{ $agent ? '💾 Update Agent' : '➕ Create Agent' }}
    </button>
</form>

<style>
.team-checklist {
  max-height: 180px;
  overflow-y: auto;
  border: 1.5px solid var(--c-border);
  border-radius: 8px;
  padding: var(--s-2);
  background: var(--c-surface-2);
}
.team-check-row {
  display: flex;
  align-items: center;
  gap: var(--s-2);
  padding: 6px 4px;
  font-size: 14px;
  cursor: pointer;
  border-radius: 4px;
}
.team-check-row:hover { background: var(--c-surface); }
.team-check-row input[type=checkbox] {
  width: 18px; height: 18px; min-height: auto; flex-shrink: 0;
  accent-color: var(--c-primary);
}
</style>