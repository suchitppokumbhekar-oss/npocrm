@extends('layouts.app')

@section('title', 'Team Status — NPO CRM')

@section('content')
<style>
.ts-wrap{max-width:1200px;margin:0 auto;padding:0 0 40px}.ts-head{display:flex;align-items:flex-end;justify-content:space-between;gap:16px;margin-bottom:16px}.ts-head h2{margin:0;font-size:24px}.ts-sub{margin:5px 0 0;color:var(--c-text-2,#667085);font-size:13px}.ts-back{font-size:13px;font-weight:700;text-decoration:none}.ts-summary{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px;margin-bottom:14px}.ts-card{background:var(--c-surface,#fff);border:1px solid var(--c-border,#e5e7eb);border-radius:12px;padding:14px}.ts-card span{display:block;font-size:12px;color:var(--c-text-2,#667085);font-weight:700}.ts-card strong{display:block;font-size:27px;margin-top:3px}.ts-filters{display:flex;gap:8px;flex-wrap:wrap;margin:0 0 14px}.ts-filter{padding:8px 12px;border:1px solid var(--c-border,#dbe1e8);border-radius:9px;text-decoration:none;font-weight:700;font-size:12px;background:#fff}.ts-filter.active{border-color:#111827;background:#111827;color:#fff}.ts-list{display:flex;flex-direction:column;gap:8px}.ts-agent{background:#fff;border:1px solid var(--c-border,#e5e7eb);border-radius:12px;overflow:hidden}.ts-agent summary{list-style:none;cursor:pointer;padding:12px 14px}.ts-agent summary::-webkit-details-marker{display:none}.ts-agent-head{display:grid;grid-template-columns:minmax(0,1fr) auto auto;gap:12px;align-items:center}.ts-agent-name{font-size:14px;font-weight:800}.ts-counts{display:flex;gap:5px;flex-wrap:wrap}.ts-age{font-size:11px;color:var(--c-text-2,#667085);text-align:right}.ts-body{padding:0 14px 14px;border-top:1px solid #eef2f6}.ts-task{display:grid;grid-template-columns:minmax(0,1fr) auto auto;align-items:center;gap:10px;padding:10px 0;border-bottom:1px solid #f0f2f5}.ts-task:last-child{border-bottom:0}.ts-agent-actions,.ts-task-actions{display:flex;align-items:center;gap:10px;flex-wrap:wrap}.ts-nudge{border:1px solid var(--c-border,#dbe1e8);background:#fff;border-radius:9px;padding:7px 10px;font-size:12px;font-weight:800;cursor:pointer}.ts-escalation-history{margin-top:16px;background:#fff;border:1px solid var(--c-border,#e5e7eb);border-radius:12px;padding:14px}.ts-escalation-history h3{margin:0 0 5px}.ts-history-row{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:12px;padding:12px 0;border-bottom:1px solid #f0f2f5}.ts-history-row:last-child{border-bottom:0}.ts-escalation-note,.ts-escalation-next{font-size:11px;margin-top:5px;color:#7a271a}.ts-escalation-next{color:var(--c-text-2,#667085)}.ts-task-name{font-weight:700;font-size:13px}.ts-task-meta{font-size:11px;color:var(--c-text-2,#667085);margin-top:2px}.ts-task-time{font-size:12px;font-weight:700;white-space:nowrap}.ts-open{font-size:12px;font-weight:800;text-decoration:none;white-space:nowrap}.ts-empty{padding:24px;background:#f8fafc;border:1px solid #e5e7eb;border-radius:12px;text-align:center;color:var(--c-text-2,#667085)}.ts-selected{margin-top:16px;background:#fff;border:1px solid var(--c-border,#e5e7eb);border-radius:12px;padding:14px}.ts-selected h3{margin:0 0 10px}.ts-selected-primary{margin-top:0;margin-bottom:16px;border-width:2px}.ts-selected-head{display:flex;align-items:flex-start;justify-content:space-between;gap:12px;margin-bottom:8px}.ts-selected-head .ts-filter{display:inline-flex;white-space:nowrap}.ts-note{font-size:12px;color:var(--c-text-2,#667085);margin:0 0 10px}.ts-danger{color:#b42318}.ts-warn{color:#b54708}.ts-ok{color:#027a48}
@media(max-width:800px){.ts-selected-head{flex-direction:column}.ts-summary{grid-template-columns:repeat(2,minmax(0,1fr))}.ts-agent-head{grid-template-columns:1fr auto}.ts-age{grid-column:1/-1;text-align:left}.ts-task{grid-template-columns:1fr auto}.ts-task .ts-open{grid-column:1/-1}}
</style>

<div class="ts-wrap">
    <div class="ts-head">
        <div>
            <h2>👥 Team Status — Who Needs Help</h2>
            <p class="ts-sub">Management view. From the Dashboard, an agent opens directly into their current actionable tasks.</p>
        </div>
        <a href="{{ url('/') }}" class="ts-back">← Back to Dashboard</a>
    </div>

    <div class="ts-summary">
        <div class="ts-card"><span>👥 Agents in scope</span><strong>{{ $totals->agents }}</strong></div>
        <div class="ts-card"><span>🚨 Overdue</span><strong class="ts-danger">{{ $totals->overdue }}</strong></div>
        <div class="ts-card"><span>📅 Due today</span><strong>{{ $totals->today }}</strong></div>
        <div class="ts-card"><span>⚠️ Escalated</span><strong class="ts-warn">{{ $totals->escalated }}</strong></div>
    </div>

    <div class="ts-filters">
        @foreach ([
            'help' => '👥 Needs attention',
            'overdue' => '🚨 Overdue',
            'today' => '📅 Due today',
            'all' => '📋 All current work',
        ] as $key => $label)
            <a href="{{ url('/team-status?filter=' . $key) }}" class="ts-filter {{ $filter === $key ? 'active' : '' }}">{{ $label }}</a>
        @endforeach
    </div>

    @if ($selectedAgent)
        <div class="ts-selected ts-selected-primary" id="selected-agent-tasks">
            <div class="ts-selected-head">
                <div>
                    <h3>📋 {{ $selectedAgent->user?->name ?? 'Agent' }} — Current Tasks</h3>
                    <p class="ts-note">Current actionable work is shown here directly. No second expand/click is required.</p>
                </div>
                <a class="ts-filter" href="{{ url('/team-status?filter=' . $filter) }}">← All agents</a>
            </div>
            @forelse ($selectedTasks as $task)
                @php
                    $isOverdue = $task->scheduled_for && $task->scheduled_for->lt(now());
                @endphp
                @php $taskNudgeCount = app(\App\Services\NudgeService::class)->countForFollowup((int) $task->id); @endphp
                <div class="ts-task">
                    <div>
                        <div class="ts-task-name">{{ ucwords(str_replace('_',' ', $task->action_type)) }} · {{ $task->lead?->customer_name ?? 'Lead' }}</div>
                        <div class="ts-task-meta">🏗️ {{ $task->lead?->project?->name ?? 'No project' }} · Follow-up #{{ $task->id }}</div>
                        @if($task->escalated_flag)
                            <div class="ts-escalation-note">🚨 Escalated after 2+ hours overdue · {{ $taskNudgeCount }} nudge{{ $taskNudgeCount === 1 ? '' : 's' }} recorded</div>
                        @endif
                    </div>
                    <div class="ts-task-time {{ $isOverdue ? 'ts-danger' : '' }}">
                        <x-relative-date :date="$task->scheduled_for" />
                    </div>
                    <div class="ts-task-actions">
                        @if($task->scheduled_for && $task->scheduled_for->lt(now()) && $task->agent?->phone)
                            <button type="button" data-npo-nudge data-nudge-target-type="followup" data-nudge-target-id="{{ $task->id }}" class="ts-nudge">💬 Nudge @if($taskNudgeCount > 0)<span class="npo-nudge-count">({{ $taskNudgeCount }}×)</span>@endif</button>
                        @endif
                        <a class="ts-open" href="{{ url('/leads/' . $task->lead_id) . '?focus_followup_id=' . $task->id . '&return_to=' . urlencode(url()->full()) }}">Open Lead / Work →</a>
                    </div>
                </div>
            @empty
                <div class="ts-empty">No current tasks for this agent.</div>
            @endforelse
        </div>
    @endif

    @if (!$selectedAgent && $rows->isEmpty())
        <div class="ts-empty">
            @if ($filter === 'help')
                ✅ No agents currently need overdue-task attention.
            @elseif ($filter === 'overdue')
                ✅ No overdue tasks in this scope.
            @else
                Nothing to show for this filter.
            @endif
        </div>
    @elseif (!$selectedAgent)
        <div class="ts-list">
            @foreach ($rows as $row)
                <details class="ts-agent" {{ $selectedAgent && $selectedAgent->id === $row->agent_id ? 'open' : '' }}>
                    <summary>
                        <div class="ts-agent-head">
                            <div class="ts-agent-name">
                                {{ $row->name }}
                                @if ($row->severity === 'critical') <span class="badge red">Critical</span>
                                @elseif ($row->severity === 'high') <span class="badge red">High</span>
                                @elseif ($row->severity === 'medium') <span class="badge orange">Needs attention</span>
                                @endif
                            </div>
                            <div class="ts-counts">
                                @if ($row->overdue) <span class="badge red">🚨 {{ $row->overdue }} overdue</span>@endif
                                @if ($row->today) <span class="badge orange">📅 {{ $row->today }} today</span>@endif
                                @if ($row->escalated) <span class="badge red">⚠️ {{ $row->escalated }}</span>@endif
                            </div>
                            <div class="ts-age">
                                @if ($row->oldest_overdue)
                                    Oldest overdue: {{ $row->oldest_overdue->scheduled_for->format('d M, h:i A') }}
                                @else
                                    No overdue
                                @endif
                            </div>
                        </div>
                    </summary>
                    <div class="ts-body">
                        <div class="ts-agent-actions">
                            <a class="ts-open" href="{{ url('/team-status?filter=all&agent_id=' . $row->agent_id) }}">Open this agent's current tasks →</a>
                            @if ($row->phone && $row->overdue > 0)
                                <button type="button" data-npo-nudge data-nudge-target-type="agent" data-nudge-target-id="{{ $row->agent_id }}" class="ts-nudge">💬 Nudge {{ explode(' ', trim($row->name))[0] }} @if($row->nudge_count > 0)<span class="npo-nudge-count">({{ $row->nudge_count }}×)</span>@endif</button>
                            @endif
                        </div>
                    </div>
                </details>
            @endforeach
        </div>
    @endif

    <div class="ts-escalation-history">
        <h3>🚨 Escalation History</h3>
        <p class="ts-note">Escalation is automatic when a pending follow-up is more than 2 hours overdue. Each event records the reason, recipients, and the first CRM activity afterwards.</p>
        @forelse ($escalationHistory as $item)
            @php
                $details = (array) ($item->log->details ?? []);
                $recipients = collect($details['recipients'] ?? [])->pluck('name')->filter()->values()->all();
                    if (empty($recipients)) $recipients = $details['escalated_to_names'] ?? [];
                $next = $item->activities->first();
            @endphp
            <div class="ts-history-row">
                <div>
                    <strong>{{ $item->followup->lead?->customer_name ?? 'Lead' }}</strong> · {{ ucwords(str_replace('_',' ', $item->followup->action_type)) }}
                    <div class="ts-task-meta">Agent: {{ $item->followup->agent?->user?->name ?? 'Unassigned' }} · {{ $item->log->created_at?->format('d M Y, h:i A') }} · Status now: {{ $item->followup->status }}</div>
                    <div class="ts-task-meta">Reason: {{ $details['reason'] ?? 'Overdue threshold reached.' }}</div>
                    <div class="ts-task-meta">Escalated to: {{ !empty($recipients) ? implode(', ', $recipients) : 'No escalation recipient recorded' }}</div>
                    @if($next)
                        <div class="ts-escalation-next"><strong>What happened next:</strong> {{ $next->displayLabel(140) }} · {{ $next->logged_at?->format('d M Y, h:i A') }} @if($next->agent?->user?->name) · by {{ $next->agent->user->name }} @endif</div>
                    @else
                        <div class="ts-escalation-next muted"><strong>No CRM activity recorded after escalation yet.</strong></div>
                    @endif
                </div>
                <a class="ts-open" href="{{ url('/leads/' . $item->followup->lead_id) . '?focus_followup_id=' . $item->followup->id . '&return_to=' . urlencode(url()->full()) }}">View Lead →</a>
            </div>
        @empty
            <div class="ts-empty">No escalation events recorded in the current scope.</div>
        @endforelse
    </div>

</div>

@push('scripts')
<script>
(function () {
    var selected = document.getElementById('selected-agent-tasks');
    if (selected) {
        window.requestAnimationFrame(function () {
            var rect = selected.getBoundingClientRect();
            var topOffset = 88;
            if (rect.top < topOffset || rect.top > window.innerHeight * 0.78) {
                window.scrollTo({ top: Math.max(0, window.scrollY + rect.top - topOffset), behavior: 'smooth' });
            }
        });
    }

    var agents = document.querySelectorAll('.ts-agent');
    if (!agents.length) return;

    agents.forEach(function (el) {
        el.addEventListener('toggle', function () {
            if (!el.open) return;

            window.requestAnimationFrame(function () {
                var summary = el.querySelector(':scope > summary');
                if (!summary) return;

                // Keep the opened agent header near the top so the newly revealed
                // task list is immediately visible instead of opening below the fold.
                var topOffset = 88;
                var rect = summary.getBoundingClientRect();
                var desired = window.scrollY + rect.top - topOffset;
                if (Math.abs(rect.top - topOffset) > 12) {
                    window.scrollTo({ top: Math.max(0, desired), behavior: 'smooth' });
                }
            });
        });
    });
})();
</script>
@endpush
@endsection
