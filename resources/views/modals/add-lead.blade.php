<form method="POST" action="/leads" data-ajax data-device-lead-form>
    @php($deviceImport = $deviceImport ?? [])
    @php($role = $role ?? session('user_role', 'agent'))

    <div class="field" style="margin-bottom:14px;padding:12px;border:1px solid var(--c-border,#d9e1ea);border-radius:12px;background:var(--c-surface-2,#f7f9fb);">
        <div style="font-weight:700;margin-bottom:8px;">📱 Add this lead from your Android phone</div>
        <div style="display:grid;grid-template-columns:1fr;gap:8px;">
            <button type="button" class="btn btn-block" data-device-whatsapp-share>📇 From Phone Contacts</button>
            <button type="button" class="btn btn-block" data-device-recent-calls>☎️ From Recent Calls</button>
        </div>
        <div class="muted" data-device-lead-status style="margin-top:8px;font-size:12px;line-height:1.4;">Select a person on the phone. The CRM will fill the name and number for you.</div>
    </div>
    @csrf

    <div class="field">
        <label>Name *</label>
        <input type="text" name="customer_name" class="input" required maxlength="255" value="{{ e($deviceImport['name'] ?? '') }}">
    </div>

    <div class="field">
        <label>Phone *</label>
        <input type="tel" name="phone" class="input" required maxlength="20"
               inputmode="tel" autocomplete="off" value="{{ e($deviceImport['phone'] ?? '') }}">
    </div>

    <div class="flex">
        <div class="flex-item">
            <label>Email</label>
            <input type="email" name="email" class="input" maxlength="255" value="{{ e($deviceImport['email'] ?? '') }}">
        </div>
        <div class="flex-item">
            <label>Budget (₹)</label>
            <input type="number" name="budget" class="input" step="0.01" min="0">
        </div>
    </div>

    <div class="flex">
        <div class="flex-item">
            <label>Source *</label>
            <select name="source" class="input" required>
                @foreach ($sources as $src)
                    <option value="{{ $src->key }}">{{ $src->label }}</option>
                @endforeach
            </select>
        </div>
        <div class="flex-item">
            <label>Project *</label>
            <x-project-picker name="project_id" required placeholder="Search project…" />
        </div>
    </div>


    @if ($role === 'admin')
        <div class="field">
            <label>Assign To</label>
            <select name="assign_to_agent_id" class="input">
                <option value="auto" selected>⚡ Auto-assign (least loaded)</option>
                @foreach ($agentsByTeam as $teamName => $agents)
                    <optgroup label="{{ $teamName }}">
                        @foreach ($agents as $a)
                            <option value="{{ $a->id }}">{{ $a->user?->name ?? ('Agent #' . $a->id) }}</option>
                        @endforeach
                    </optgroup>
                @endforeach
            </select>
        </div>
    @elseif ($role === 'team_manager')
        <div class="field">
            <label>Assign To</label>
            <select name="assign_to_agent_id" class="input">
                <option value="auto">⚡ Auto-assign within my team</option>
                @if ($selfAgentId)
                    <option value="{{ $selfAgentId }}" selected>👤 Assign to me</option>
                @endif
                @foreach ($agentsByTeam as $agents)
                    @foreach ($agents as $a)
                        @if ($a->id !== $selfAgentId)
                            <option value="{{ $a->id }}">{{ $a->user?->name ?? ('Agent #' . $a->id) }}</option>
                        @endif
                    @endforeach
                @endforeach
            </select>
        </div>
    @else
        <div class="field">
            <label>Assign To</label>
            <input type="text" class="input" value="👤 Me" disabled
                   style="background:var(--c-surface-2);color:var(--c-muted);">
        </div>
    @endif

    <input type="hidden" name="device_source" value="{{ e($deviceImport['source'] ?? '') }}">
    <button type="submit" class="btn btn-block">💾 Save Lead</button>

    @if (!empty($deviceImport['shared_text']) || !empty($deviceImport['shared_url']))
        <div class="muted" style="margin-top:10px;font-size:12px;line-height:1.4;">Shared content is available for review; the CRM will not create a lead automatically.</div>
    @endif

</form>