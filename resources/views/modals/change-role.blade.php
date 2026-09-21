@php
    $currentRole = $targetUser->role instanceof \App\Enums\UserRole
        ? $targetUser->role->value
        : (string) $targetUser->role;

    $isAgent  = $currentRole === 'agent';
    $isTM     = $currentRole === 'team_manager';
@endphp

<form method="POST" action="/settings/users/{{ $targetUser->id }}/change-role" data-ajax>
    @csrf

    <p class="muted" style="margin-bottom:var(--s-3);font-size:13px;">
        Changing role for <strong>{{ $targetUser->name }}</strong>
        <span class="muted">({{ $targetUser->email }})</span>
    </p>

    {{-- Current role --}}
    <div class="field">
        <label>Current Role</label>
        <div>
            @if ($isAgent)
                <span class="badge blue">👤 Agent</span>
            @elseif ($isTM)
                <span class="badge purple">🎯 Team Manager</span>
            @else
                <span class="badge">{{ $currentRole }}</span>
            @endif
        </div>
    </div>

    {{-- New role --}}
    <div class="field">
        <label>New Role *</label>

        <label style="display:flex;gap:10px;cursor:pointer;padding:12px;border:1.5px solid {{ $isAgent ? 'var(--c-primary)' : 'var(--c-border)' }};border-radius:8px;margin-bottom:6px;">
            <input type="radio" name="role" value="agent"
                   @checked($isAgent) required
                   style="width:auto;min-height:auto;margin-top:3px;">
            <span>
                <strong>👤 Agent</strong>
                <span class="muted" style="display:block;font-size:12px;margin-top:2px;">
                    Works leads. Sees only their own and shared leads.
                </span>
            </span>
        </label>

        <label style="display:flex;gap:10px;cursor:pointer;padding:12px;border:1.5px solid {{ $isTM ? 'var(--c-primary)' : 'var(--c-border)' }};border-radius:8px;">
            <input type="radio" name="role" value="team_manager"
                   @checked($isTM) required
                   style="width:auto;min-height:auto;margin-top:3px;">
            <span>
                <strong>🎯 Team Manager</strong>
                <span class="muted" style="display:block;font-size:12px;margin-top:2px;">
                    Manages a team. Sees team-scoped leads and can add/edit agents in their team.
                </span>
            </span>
        </label>
    </div>

    {{-- Warning for TM → Agent --}}
    @if ($isTM && $managedTeams->isNotEmpty())
        <div style="padding:12px 14px;background:#fdecea;border-left:4px solid var(--c-danger);border-radius:6px;margin-bottom:var(--s-3);font-size:13px;">
            <strong>⚠️ Heads up:</strong> {{ $targetUser->name }} currently manages
            <strong>{{ $managedTeams->count() }} team{{ $managedTeams->count() === 1 ? '' : 's' }}</strong>:
            <div style="margin-top:6px;">
                @foreach ($managedTeams as $t)
                    <span class="badge purple" style="margin-right:4px;margin-bottom:4px;">{{ $t->name }}</span>
                @endforeach
            </div>
            <br>
            If you change to <strong>Agent</strong>, these teams will have <strong>no manager</strong> until you assign a replacement in <a href="{{ url('/settings#teams') }}">Settings → Teams</a>.
        </div>
    @endif

    {{-- Note for Agent → TM --}}
    @if ($isAgent)
        <div style="padding:12px 14px;background:#e8f4ff;border-left:4px solid var(--c-info);border-radius:6px;margin-bottom:var(--s-3);font-size:13px;">
            ℹ️ After changing to Team Manager, go to <a href="{{ url('/settings#teams') }}">Settings → Teams</a>
            and assign them as the manager of a team to activate their team dashboard.
        </div>
    @endif

    <button type="submit" class="btn btn-block">💾 Change Role</button>
</form>