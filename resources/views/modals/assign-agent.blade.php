@php
    $currentPrimaryId = $currentPrimaryId ?? $lead->agent_id;
    $sharedAgentIds   = $sharedAgentIds ?? [];
    $isAgentUser      = session('user_role') === 'agent';
@endphp

<form method="POST" action="/leads/assign-agent" data-ajax>
    @csrf
    <input type="hidden" name="lead_id" value="{{ $lead->id }}">

    <p class="muted" style="margin-bottom:12px;">
        @if($isAgentUser)
            Share <strong>{{ $lead->customer_name }}</strong> with another agent. Your primary ownership stays unchanged.
        @else
            Managing assignments for <strong>{{ $lead->customer_name }}</strong>
        @endif
    </p>

    {{-- ============================================================ --}}
    {{-- PRIMARY AGENT --}}
    {{-- ============================================================ --}}
    <div class="field">
        <label>👑 Primary Agent</label>
        @if($isAgentUser)
            <input type="hidden" name="agent_id" value="{{ $currentPrimaryId }}">
            <div class="input" style="background:var(--c-surface-2);">
                {{ $lead->agent?->user?->name ?? 'Unassigned' }}
                <span class="muted" style="font-size:11px;display:block;margin-top:3px;">Primary ownership cannot be changed by an agent here.</span>
            </div>
        @else
            <select name="agent_id" class="input" required>
                <option value="">— Select primary agent —</option>
                @foreach ($agents as $a)
                    <option value="{{ $a->id }}" @selected((int) $currentPrimaryId === $a->id)>
                        {{ $a->user?->name ?? ('Agent #' . $a->id) }}
                        @if ($a->teams->isNotEmpty()) · {{ $a->teams->first()->name }} @endif
                    </option>
                @endforeach
            </select>
            <p class="muted" style="font-size:11px;margin-top:4px;">
                The primary owner. Their <code>current_load</code> counts this lead.
            </p>
        @endif
    </div>

    {{-- ============================================================ --}}
    {{-- SHARED AGENTS --}}
    {{-- ============================================================ --}}
    <div class="field">
        <label>👥 Also share with</label>
        <p class="muted" style="font-size:11px;margin-bottom:6px;">
            The selected agent can work on this lead and their activity remains visible in the lead history.
        </p>

        <div class="agent-checklist">
            @foreach ($agents as $a)
                @if ((int) $a->id === (int) $currentPrimaryId)
                    @continue
                @endif
                <label class="agent-check-row">
                    <input type="checkbox" name="additional_agent_ids[]" value="{{ $a->id }}"
                           @checked(in_array($a->id, $sharedAgentIds, true))>
                    <span>
                        {{ $a->user?->name ?? ('Agent #' . $a->id) }}
                        @if ($a->teams->isNotEmpty())
                            <em class="muted" style="font-size:11px;">· {{ $a->teams->first()->name }}</em>
                        @endif
                    </span>
                </label>
            @endforeach
        </div>
    </div>

    {{-- ============================================================ --}}
    {{-- HOW IS THIS BEING SHARED --}}
    {{-- ============================================================ --}}
    <div class="field">
        <label>How is this being shared? <span class="req">*</span></label>

        <label class="agent-check-row" style="margin-bottom:6px;">
            <input type="radio" name="share_type" value="internal" checked
                   style="width:18px;height:18px;min-height:auto;accent-color:var(--c-primary);">
            <span>
                👥 <strong>Internal co-agent</strong>
                <em class="muted" style="display:block;font-size:11px;font-weight:400;">
                    Working in parallel. Check-back reminder goes to the <strong>primary agent</strong> in 24h.
                </em>
            </span>
        </label>

        <label class="agent-check-row">
            <input type="radio" name="share_type" value="site_team"
                   style="width:18px;height:18px;min-height:auto;accent-color:var(--c-primary);">
            <span>
                🏢 <strong>On-site sales team</strong>
                <em class="muted" style="display:block;font-size:11px;font-weight:400;">
                    They own the follow-through. Check-back reminder goes to <strong>you</strong> in 2 days.
                </em>
            </span>
        </label>

        <p class="muted" style="font-size:11px;margin-top:6px;">
            A check-back task is created automatically so nobody forgets to ask what happened.
        </p>
    </div>

    {{-- ============================================================ --}}
    {{-- WHY SHARE / REASSIGN --}}
    {{-- ============================================================ --}}
    <div class="field">
        <label>Why is this being shared / reassigned? <span class="req">*</span></label>
        <textarea name="share_note" rows="3" class="input" maxlength="500"
                  placeholder="e.g. Customer also interested in 3BHK at Raheja — his office is in Andheri"></textarea>
        <p class="muted" style="font-size:11px;margin-top:4px;">
            This is logged in the timeline and shown to the receiving agent so they know the context.
        </p>
    </div>

    <button type="submit" class="btn btn-block">💾 Save Assignments</button>
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