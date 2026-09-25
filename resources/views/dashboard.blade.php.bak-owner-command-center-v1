@extends('layouts.app')

@section('title', 'Dashboard — NPO CRM')

@php
    /* ---------- Precompute ---------- */
    $canSeeContacts    = app(\App\Services\AccessService::class)->canAccessContacts();
    $pendingTodayCount = $overdueCount + $dueSoonCount + $restOfTodayCount;
    $pendingFutureCount = max(0, $totalPending - $pendingTodayCount);

    $isAdmin     = ($userRole === 'admin');
    $isManager   = ($userRole === 'team_manager');
    $isAdminOrMgr = $isAdmin || $isManager;
    $scopeSuffix = $isManager ? "&scope=" . ($workScope ?? "team") : "";
    $scopeFirst = $isManager ? "?scope=" . ($workScope ?? "team") : "";
    $scopeLabel = $isManager
        ? (($workScope ?? "team") === "delegated" ? "All Delegated" : "My Team")
        : "Team";

    $firstName   = explode(' ', $userName)[0] ?? 'User';

    $teamOverdueTotal = $isAdminOrMgr ? $agentOverdueSummary->sum('overdue') : 0;
    $teamAgentsBehind = $isAdminOrMgr ? $agentOverdueSummary->count() : 0;
@endphp

@section('content')

    {{-- 0. ATTENDANCE BANNER (payroll agents only) --}}
    @include('partials.attendance-banner')

    {{-- 1. FOCUS BANNER --}}
    <div class="card greeting-card">
        <div class="greeting-left">
            <h2>{{ $greeting }}, {{ $firstName }} 👋</h2>

            @if ($isAdminOrMgr)
                @if ($teamOverdueTotal > 0)
                    <p class="greeting-sub urgent">
                        🚨 {{ $scopeLabel }} has <strong>{{ $teamOverdueTotal }}</strong> overdue task{{ $teamOverdueTotal === 1 ? '' : 's' }}.
                        <strong>Open Team Status to review them.</strong>
                    </p>
                @elseif ($isManager && $personalOverdueCount > 0)
                    <p class="greeting-sub urgent">
                        ⚡ You have <strong>{{ $personalOverdueCount }}</strong> overdue task{{ $personalOverdueCount === 1 ? '' : 's' }}.
                        <strong>Open My Work to start.</strong>
                    </p>
                @elseif ($isManager && $personalTodayCount > 0)
                    <p class="greeting-sub soon">
                        ⚡ You have <strong>{{ $personalTodayCount }}</strong> task{{ $personalTodayCount === 1 ? '' : 's' }} due today.
                        <strong>Open My Work to continue.</strong>
                    </p>
                @else
                    <p class="greeting-sub">
                        {{ $isAdmin ? '📊 Management overview is ready.' : '✅ ' . $scopeLabel . ' and your personal work are on track.' }}
                    </p>
                @endif
            @else
                @if ($overdueCount > 0)
                    <p class="greeting-sub urgent">
                        🚨 <strong>{{ $overdueCount }}</strong> overdue task{{ $overdueCount === 1 ? '' : 's' }}.
                        <strong>Clear these before anything else.</strong>
                    </p>
                @elseif ($dueSoonCount > 0)
                    <p class="greeting-sub soon">
                        ⏰ <strong>{{ $dueSoonCount }}</strong> task{{ $dueSoonCount === 1 ? '' : 's' }} due in the next 2 hours.
                        Get ahead of them now.
                    </p>
                @elseif ($restOfTodayCount > 0)
                    <p class="greeting-sub">
                        📅 <strong>{{ $restOfTodayCount }}</strong> task{{ $restOfTodayCount === 1 ? '' : 's' }} left today.
                    </p>
                @elseif ($tomorrowCount > 0)
                    <p class="greeting-sub">
                        ✅ Today is clear. {{ $tomorrowCount }} task{{ $tomorrowCount === 1 ? '' : 's' }} lined up for tomorrow.
                    </p>
                @elseif ($pendingFutureCount > 0)
                    <p class="greeting-sub">
                        ✅ Nothing pending today. {{ $pendingFutureCount }} in the pipeline.
                    </p>
                @else
                    <p class="greeting-sub">🎉 All caught up. Nothing pending.</p>
                @endif
            @endif

            @if ($isManager && $agentId)
                <p class="greeting-sub" style="margin-top:6px;font-size:13px;">
                    ⚡ Your work: <strong>{{ $personalOverdueCount }}</strong> overdue · <strong>{{ $personalTodayCount }}</strong> due today.
                </p>
            @endif
        </div>
    </div>

    @if ($isAdminOrMgr)
        @if ($isManager)
            <nav class="task-scope-tabs" aria-label="Dashboard scope">
                <a href="{{ url('/?scope=team') }}" class="task-scope-tab {{ ($workScope ?? 'team') === 'team' ? 'active' : '' }}">
                    <strong>My Team</strong>
                    <small>Direct team responsibility</small>
                </a>
                <a href="{{ url('/?scope=delegated') }}" class="task-scope-tab {{ ($workScope ?? 'team') === 'delegated' ? 'active' : '' }}">
                    <strong>All Delegated</strong>
                    <small>Other teams &amp; agents in your scope</small>
                </a>
            </nav>
        @endif

        {{-- MANAGEMENT DASHBOARD: summaries only. Individual task execution belongs in Team Status / Who Needs Help. --}}
        <div class="mgmt-summary-grid">
            <a class="mgmt-summary-card mgmt-danger" href="{{ url('/team-status?filter=overdue' . ($isManager ? '&scope=' . ($workScope ?? 'team') : '')) }}">
                <span class="msc-icon">🚨</span>
                <span class="msc-label">Overdue</span>
                <strong>{{ $teamOverdueTotal }}</strong>
                <small>Open overdue tasks →</small>
            </a>
            <a class="mgmt-summary-card mgmt-warn" href="{{ url('/team-status?filter=today' . ($isManager ? '&scope=' . ($workScope ?? 'team') : '')) }}">
                <span class="msc-icon">📅</span>
                <span class="msc-label">Due today</span>
                <strong>{{ $agentOverdueSummary->sum('due_soon') }}</strong>
                <small>Review today's work →</small>
            </a>
            <a class="mgmt-summary-card mgmt-help" href="{{ url('/team-status?filter=help' . ($isManager ? '&scope=' . ($workScope ?? 'team') : '')) }}">
                <span class="msc-icon">👥</span>
                <span class="msc-label">Needs help</span>
                <strong>{{ $teamAgentsBehind }}</strong>
                <small>Open Team Status →</small>
            </a>
            @if ($isManager && $agentId)
                <a class="mgmt-summary-card mgmt-neutral" href="{{ url('/tasks?scope=personal') }}">
                    <span class="msc-icon">⚡</span>
                    <span class="msc-label">My Work</span>
                    <strong>{{ $personalOverdueCount + $personalTodayCount }}</strong>
                    <small>Open your work queue →</small>
                </a>
            @else
                <a class="mgmt-summary-card mgmt-neutral" href="{{ url('/reports') }}">
                    <span class="msc-icon">📊</span>
                    <span class="msc-label">Reports</span>
                    <strong>→</strong>
                    <small>Open management reports →</small>
                </a>
            @endif
        </div>

        @if ($todayVisits->isNotEmpty())
            <details class="dash-section dash-visit">
                <summary class="dash-section-head">
                    <span class="dsh-arrow">▸</span>
                    <span class="dsh-icon">🏠</span>
                    <span class="dsh-title">SITE VISITS TODAY</span>
                    <span class="dsh-count">{{ $todayVisits->count() }}</span>
                </summary>
                <div class="dash-section-body">
                    <div class="mgmt-section-note">Today's visits are shown as a quick check. Use the lead record for full details.</div>
                    @foreach ($todayVisits->take(8) as $lead)
                        <a href="{{ url('/leads/' . $lead->id) . '?return_to=' . urlencode(url()->full()) }}" class="visit-row visit-row-link">
                            <div style="flex:1;min-width:0;">
                                <div class="visit-name-row"><strong class="visit-name">{{ $lead->customer_name }}</strong></div>
                                <div class="muted" style="font-size:12px;margin-top:3px;">🏗️ {{ $lead->project?->name ?? 'No project' }} · 👤 <strong>{{ $lead->agent?->user?->name ?? 'Unassigned' }}</strong></div>
                            </div>
                            <span class="visit-time-badge">{{ $lead->visit_scheduled_at->format('h:i A') }}</span>
                        </a>
                    @endforeach
                    @if ($todayVisits->count() > 8)
                        <a href="{{ url('/leads?preset=visits_today' . $scopeSuffix) }}" class="mgmt-more-link">View all {{ $todayVisits->count() }} visits →</a>
                    @endif
                </div>
            </details>
        @endif

        @if ($reactivationTasks->isNotEmpty())
            <details class="dash-section">
                <summary class="dash-section-head">
                    <span class="dsh-arrow">▸</span>
                    <span class="dsh-icon">🔄</span>
                    <span class="dsh-title">REACTIVATION / NURTURE</span>
                    <span class="dsh-count">{{ $reactivationTasks->count() }}</span>
                </summary>
                <div class="dash-section-body">
                    <div class="mgmt-section-note">Future nurture is kept separate from active work. It does not need attention until it becomes due.</div>
                    <a href="{{ url('/tasks?view=nurture' . $scopeSuffix . '#task-results') }}" class="mgmt-more-link">Open Reactivation / Nurture →</a>
                </div>
            </details>
        @endif
    @else
    {{-- 3a. OVERDUE --}}
    @if ($overdueTasks->isNotEmpty())
        <details class="dash-section dash-overdue" open>
            <summary class="dash-section-head">
                <span class="dsh-arrow">▸</span>
                <span class="dsh-icon">🚨</span>
                <span class="dsh-title">OVERDUE — START HERE</span>
                <span class="dsh-count">{{ $overdueCount }}</span>
            </summary>
            <div class="dash-section-body">
                @foreach ($overdueTasks as $task)
                    <x-task-card :task="$task" :work-only="true" />
                @endforeach
            </div>
        </details>
    @endif

    {{-- 3b. DUE SOON --}}
    @if ($dueSoonTasks->isNotEmpty())
        <details class="dash-section dash-soon" @if($overdueTasks->isEmpty()) open @endif>
            <summary class="dash-section-head">
                <span class="dsh-arrow">▸</span>
                <span class="dsh-icon">⏰</span>
                <span class="dsh-title">DUE IN NEXT 2 HOURS</span>
                <span class="dsh-count">{{ $dueSoonCount }}</span>
            </summary>
            <div class="dash-section-body">
                @foreach ($dueSoonTasks as $task)
                    <x-task-card :task="$task" :early-action-gate="true" :work-only="true" />
                @endforeach
            </div>
        </details>
    @endif

    {{-- 3c. REST OF TODAY --}}
    @if ($restOfTodayTasks->isNotEmpty())
        <details class="dash-section" @if($overdueTasks->isEmpty() && $dueSoonTasks->isEmpty()) open @endif>
            <summary class="dash-section-head">
                <span class="dsh-arrow">▸</span>
                <span class="dsh-icon">📅</span>
                <span class="dsh-title">REST OF TODAY</span>
                <span class="dsh-count">{{ $restOfTodayCount }}</span>
            </summary>
            <div class="dash-section-body">
                @foreach ($restOfTodayTasks as $task)
                    <x-task-card :task="$task" :early-action-gate="true" :work-only="true" />
                @endforeach
            </div>
        </details>
    @endif

    {{-- 3d. SITE VISITS TODAY --}}
    @if ($todayVisits->isNotEmpty())
        <details class="dash-section dash-visit">
            <summary class="dash-section-head">
                <span class="dsh-arrow">▸</span>
                <span class="dsh-icon">🏠</span>
                <span class="dsh-title">SITE VISITS TODAY</span>
                <span class="dsh-count">{{ $todayVisits->count() }}</span>
            </summary>
            <div class="dash-section-body">
                @foreach ($todayVisits as $lead)
                    <a href="{{ url('/leads/' . $lead->id) . '?return_to=' . urlencode(url()->full()) }}" class="visit-row visit-row-link">
                        <div style="flex:1;min-width:0;">
                            <div class="visit-name-row">
                                <strong class="visit-name">{{ $lead->customer_name }}</strong>
                                @if ($lead->tag)
                                    <x-lead-tag-chip :tag="$lead->tag" />
                                @endif
                            </div>

                            @if ($lead->labels->isNotEmpty())
                                <x-lead-labels-row :labels="$lead->labels" :max="2" />
                            @endif

                            <div class="muted" style="font-size:12px;margin-top:3px;">
                                🏗️ {{ $lead->project?->name ?? 'No project' }}
                                · 👤 <strong>{{ $lead->agent?->user?->name ?? 'Unassigned' }}</strong>
                            </div>
                        </div>
                        <span class="visit-time-badge">
                            {{ $lead->visit_scheduled_at->format('h:i A') }}
                        </span>
                    </a>
                @endforeach
            </div>
        </details>
    @endif

    {{-- 3e. REACTIVATION / NURTURE --}}
    @if ($reactivationTasks->isNotEmpty())
        <details class="dash-section">
            <summary class="dash-section-head">
                <span class="dsh-arrow">▸</span>
                <span class="dsh-icon">🔄</span>
                <span class="dsh-title">REACTIVATION / NURTURE</span>
                <span class="dsh-count">{{ $reactivationTasks->count() }}</span>
            </summary>
            <div class="dash-section-body">
                <div style="margin:0 0 10px 0;padding:9px 11px;border-radius:8px;background:#f7f4fb;font-size:12px;color:var(--c-text-2);">
                    Lost leads intentionally scheduled for future re-contact. These are nurture reminders, not current Do Now work.
                </div>
                @foreach ($reactivationTasks as $task)
                    <x-task-card :task="$task" :show-actions="false" :work-only="true" />
                @endforeach
            </div>
        </details>
    @endif

    {{-- 3e. NOTHING PENDING --}}
    @if ($overdueTasks->isEmpty() && $dueSoonTasks->isEmpty() && $restOfTodayTasks->isEmpty())
        <div class="card empty-queue">
            <div style="font-size:40px;">🎉</div>
            <h3 style="margin-top:8px;">Nothing pending right now</h3>
            <p class="muted" style="margin-top:4px;">
                @if ($tomorrowCount > 0)
                    {{ $tomorrowCount }} task{{ $tomorrowCount === 1 ? '' : 's' }} lined up for tomorrow.
                @elseif ($pendingFutureCount > 0)
                    {{ $pendingFutureCount }} upcoming task{{ $pendingFutureCount === 1 ? '' : 's' }} in the pipeline.
                @else
                    New tasks will appear here as follow-ups become due.
                @endif
            </p>
        </div>
    @endif

    @endif

    {{-- 3f. SECONDARY ACTIONS --}}
    <div class="work-secondary-actions">
        @if ($isAdminOrMgr)
            <a href="{{ url('/team-status' . $scopeFirst) }}" class="secondary-action secondary-action-primary">👥 <span>Team Status</span><b>{{ $teamAgentsBehind }}</b></a>
            <a href="{{ url('/leads' . $scopeFirst) }}" class="secondary-action">🎯 <span>All Leads</span></a>
            @if ($isManager && $agentId)
                <a href="{{ url('/tasks?scope=personal') }}" class="secondary-action">⚡ <span>My Work</span><b>{{ $personalOverdueCount + $personalTodayCount }}</b></a>
            @endif
            <a href="{{ url('/reports') }}" class="secondary-action">📊 <span>Reports</span></a>
            @if ($isAdmin)
                <button type="button" class="secondary-action" data-modal="add-lead">＋ <span>New Lead</span></button>
            @endif
        @else
            <a href="{{ url('/leads' . $scopeFirst) }}" class="secondary-action">🎯 <span>All Leads</span></a>
            <a href="{{ url('/my-leads') }}" class="secondary-action">👤 <span>My Leads</span></a>
            <a href="{{ url('/tasks') }}" class="secondary-action secondary-action-primary">⚡ <span>My Work</span><b>{{ $totalPending }}</b></a>
            @if ($isAdmin)
                <button type="button" class="secondary-action" data-modal="add-lead">＋ <span>New Lead</span></button>
            @endif
            @if ($canSeeContacts)
                <a href="{{ url('/contacts') }}" class="secondary-action">📇 <span>Contacts</span></a>
            @endif
        @endif
    </div>

    {{-- 4. TEAM STATUS — compact management snapshot. Full investigation lives on /team-status. --}}
    @if ($isAdminOrMgr)
        <details class="dash-section dash-team-status">
            <summary class="dash-section-head">
                <span class="dsh-arrow">▸</span>
                <span class="dsh-icon">👥</span>
                <span class="dsh-title">{{ strtoupper($scopeLabel) }} — WHO NEEDS HELP</span>
                <span class="dsh-count">{{ $teamAgentsBehind }}</span>
            </summary>
            <div class="dash-section-body">
                @if ($agentOverdueSummary->isEmpty())
                    <div class="mgmt-ok-box">✅ No agent currently needs overdue-task attention.</div>
                @else
                    <div class="mgmt-team-list">
                        @foreach ($agentOverdueSummary->take(8) as $summary)
                            <div class="mgmt-team-row">
                                <div class="mgmt-team-name"><strong>{{ $summary->name }}</strong></div>
                                <div class="mgmt-team-counts">
                                    <span class="badge red">🚨 {{ $summary->overdue }}</span>
                                    <span class="badge orange">📅 {{ $summary->due_soon }}</span>
                                    @if ($summary->escalated > 0)<span class="badge red">⚠️ {{ $summary->escalated }}</span>@endif
                                </div>
                                @if ($summary->phone && $summary->overdue > 0)
                                    <button type="button"
                                            data-npo-nudge
                                            data-nudge-target-type="agent"
                                            data-nudge-target-id="{{ $summary->agent_id }}"
                                            data-whatsapp-phone="{{ preg_replace('/\D/', '', $summary->phone) }}"
                                            class="btn-small btn-ghost"
                                            title="Nudge {{ $summary->name }} on WhatsApp">
                                        💬 Nudge @if(($summary->nudge_count ?? 0) > 0)<span class="npo-nudge-count">({{ $summary->nudge_count }}×)</span>@endif
                                    </button>
                                @endif
                                <a class="mgmt-open-agent" href="{{ url('/team-status?filter=all&agent_id=' . $summary->agent_id . ($isManager ? '&scope=' . ($workScope ?? 'team') : '')) }}">View tasks →</a>
                            </div>
                        @endforeach
                    </div>
                    <a href="{{ url('/team-status?filter=help' . ($isManager ? '&scope=' . ($workScope ?? 'team') : '')) }}" class="mgmt-more-link">Open full Team Status →</a>
                @endif
            </div>
        </details>
    @endif

    {{-- 4b. SUPER ADMIN — who did what --}}
    @php
        $isSuperAdminDashboard = app(\App\Services\SuperAdminService::class)->isSuperAdmin();
    @endphp
    @if ($isSuperAdminDashboard)
        @php
            $superAdminWork = app(\App\Services\SuperAdminWorkSummaryService::class)->summary('today');
            $superAdminMetrics = \App\Services\SuperAdminWorkSummaryService::METRICS;
        @endphp
        <details class="dash-section dash-work-summary">
            <summary class="dash-section-head">
                <span class="dsh-arrow">▸</span>
                <span class="dsh-icon">🧾</span>
                <span class="dsh-title">WHO DID WHAT TODAY</span>
                <span class="dsh-count">{{ $superAdminWork->count() }} agents</span>
            </summary>
            <div class="dash-section-body" style="overflow:auto;">
                <div style="display:flex;justify-content:space-between;gap:8px;align-items:center;flex-wrap:wrap;margin-bottom:10px;">
                    <span class="muted" style="font-size:12px;">Every non-zero count opens the exact leads/tasks behind it.</span>
                    <a href="{{ url('/admin/work-summary?period=today') }}" class="mgmt-more-link">Open weekly/monthly view →</a>
                </div>
                <table style="width:100%;border-collapse:collapse;min-width:1050px;">
                    <thead><tr>
                        <th style="text-align:left;padding:8px;border-bottom:1px solid #e5e7eb;">Agent</th>
                        @foreach($superAdminMetrics as $metric=>$label)
                            <th style="padding:8px;border-bottom:1px solid #e5e7eb;text-align:center;font-size:11px;">{{ $label }}</th>
                        @endforeach
                    </tr></thead>
                    <tbody>
                    @foreach($superAdminWork as $w)
                        <tr>
                            <td style="padding:8px;border-bottom:1px solid #f1f5f9;"><strong>{{ $w->name }}</strong></td>
                            @foreach($superAdminMetrics as $metric=>$label)
                                @php $c = $w->{$metric}; @endphp
                                <td style="padding:8px;border-bottom:1px solid #f1f5f9;text-align:center;">
                                    <a href="{{ url('/admin/work-summary/'.$w->agent_id.'/'.$metric.'?period=today') }}" title="{{ $label }} for {{ $w->name }}">{{ number_format($c) }}</a>
                                </td>
                            @endforeach
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </details>
    @endif

    {{-- 5. ESCALATION HISTORY — what was escalated, to whom, and what happened next. --}}
    @php
        $dashboardEscalations = app(\App\Services\NudgeService::class)->recentEscalationsForAgents(
            $scopedAgentIds ?? app(\App\Services\NudgeService::class)->visibleAgentIds(),
            10
        );
    @endphp
    <details class="dash-section dash-escalations">
        <summary class="dash-section-head">
            <span class="dsh-arrow">▸</span>
            <span class="dsh-icon">🚨</span>
            <span class="dsh-title">ESCALATIONS — WHAT HAPPENED</span>
            <span class="dsh-count">{{ $dashboardEscalations->count() }}</span>
        </summary>
        <div class="dash-section-body">
            <div class="mgmt-section-note">
                An escalation means a pending follow-up passed the configured 2-hour overdue threshold. This section records why it escalated, who was notified, and the first CRM work recorded afterwards.
            </div>
            @forelse ($dashboardEscalations as $item)
                @php
                    $details = (array) ($item->log->details ?? []);
                    $recipients = collect($details['recipients'] ?? [])->pluck('name')->filter()->values()->all();
                    if (empty($recipients)) $recipients = $details['escalated_to_names'] ?? [];
                @endphp
                <div class="dash-escalation-row">
                    <div>
                        <strong>{{ $item->followup->lead?->customer_name ?? 'Lead' }}</strong>
                        <div class="muted" style="font-size:12px;margin-top:3px;">
                            {{ $item->followup->action_type }} · Agent: {{ $item->followup->agent?->user?->name ?? 'Unassigned' }} · {{ $item->log->created_at?->format('d M Y, h:i A') }}
                        </div>
                        <div class="muted" style="font-size:12px;margin-top:3px;">
                            Escalated to: {{ !empty($recipients) ? implode(', ', $recipients) : 'No escalation recipient recorded' }}
                        </div>
                        @if ($item->activities->isNotEmpty())
                            @php $next = $item->activities->first(); @endphp
                            <div class="dash-escalation-next">
                                <strong>Next recorded action:</strong> {{ $next->displayLabel(120) }} · {{ $next->logged_at?->format('d M, h:i A') }}
                                @if($next->agent?->user?->name) · by {{ $next->agent->user->name }} @endif
                            </div>
                        @else
                            <div class="dash-escalation-next muted"><strong>No subsequent CRM activity recorded yet.</strong></div>
                        @endif
                    </div>
                    <a class="mgmt-open-agent" href="{{ url('/leads/' . $item->followup->lead_id) . '?focus_followup_id=' . $item->followup->id . '&return_to=' . urlencode(url()->full()) }}">Open lead →</a>
                </div>
            @empty
                <div class="mgmt-ok-box">No escalation history is available in the current scope.</div>
            @endforelse
        </div>
    </details>

    {{-- 6. BUSINESS PULSE — operational and actionable --}}
    @if (! empty($businessPulse))
        @php
            $teamActiveCount   = $businessPulse['team_activity']->where('status', 'active')->count();
            $teamTotalCount    = $businessPulse['team_activity']->count();
            $teamIdleCount     = $businessPulse['team_activity']->where('status', 'idle')->count();
            $teamSilentCount   = $businessPulse['team_activity']->where('status', 'silent')->count();
            $pipelineTotal      = $businessPulse['pipeline_aging']->sum('count');
            $stalePipelineCount = $businessPulse['pipeline_aging']
                ->sum('stale_count');
            $stalePipelineLeads = $businessPulse['pipeline_aging']
                ->flatMap(fn ($s) => $s->stale_leads->map(fn ($lead) => (object) [
                    'id' => $lead->id,
                    'name' => $lead->name,
                    'age_days' => $lead->age_days,
                    'status_label' => $s->label,
                ]))
                ->sortByDesc('age_days')
                ->values();
            $topProjectCount   = $businessPulse['top_projects']->count();
            $staleProjectCount = $businessPulse['stale_projects']->count();
        @endphp

        <details class="dash-section dash-pulse">
            <summary class="dash-section-head">
                <span class="dsh-arrow">▸</span>
                <span class="dsh-icon">📊</span>
                <span class="dsh-title">BUSINESS PULSE</span>
                <span class="dsh-count">{{ $teamTotalCount }} agents</span>
            </summary>
            <div class="dash-section-body pulse-body-outer">
                <details class="pulse-details">
                    <summary class="pulse-summary">
                        <div class="pulse-summary-left">
                            <span class="pulse-summary-arrow">▸</span>
                            <span class="pulse-summary-title">👥 {{ $scopeLabel }} Activity Today</span>
                        </div>
                        <div class="pulse-summary-right">
                            <span class="badge green" style="font-size:11px;">🟢 {{ $teamActiveCount }}</span>
                            <span class="badge orange" style="font-size:11px;">🟡 {{ $teamIdleCount }}</span>
                            <span class="badge red" style="font-size:11px;">🔴 {{ $teamSilentCount }}</span>
                        </div>
                    </summary>
                    <div class="pulse-body">
                        @if ($teamTotalCount === 0)
                            <p class="muted" style="font-size:13px;margin:0;">No active agents in your scope.</p>
                        @else
                            <div class="pulse-activity-list">
                                @foreach ($businessPulse['team_activity'] as $a)
                                    <div class="pulse-activity-row">
                                        <div class="pulse-activity-name">
                                            @php
                                                $icon = $a->status === 'active' ? '🟢'
                                                      : ($a->status === 'idle' ? '🟡' : '🔴');
                                            @endphp
                                            <span class="pulse-dot">{{ $icon }}</span>
                                            <a href="{{ url('/leads?agent_id=' . $a->agent_id . $scopeSuffix) }}" class="pulse-link">
                                                <strong>{{ $a->name }}</strong>
                                            </a>
                                        </div>
                                        <div class="pulse-activity-meta">
                                            @if ($a->last_at)
                                                <span class="muted" style="font-size:12px;">
                                                    last {{ $a->last_at->diffForHumans() }}
                                                </span>
                                            @else
                                                <span class="muted" style="font-size:12px;">no activity yet</span>
                                            @endif
                                            <a href="{{ url('/leads?agent_id=' . $a->agent_id . $scopeSuffix) }}"
                                               class="badge {{ $a->today_count > 0 ? 'green' : 'red' }}"
                                               style="font-size:11px;text-decoration:none;">
                                                {{ $a->today_count }} today
                                            </a>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        @endif
                    </div>
                </details>

                <details class="pulse-details">
                    <summary class="pulse-summary">
                        <div class="pulse-summary-left">
                            <span class="pulse-summary-arrow">▸</span>
                            <span class="pulse-summary-title">⏳ Pipeline Aging</span>
                        </div>
                        <div class="pulse-summary-right">
                            <span class="muted" style="font-size:12px;">
                                {{ number_format($pipelineTotal) }} active
                            </span>
                            @if ($stalePipelineCount > 0)
                                <span class="badge red" style="font-size:11px;">
                                    ⚠️ {{ $stalePipelineCount }} stale lead{{ $stalePipelineCount === 1 ? '' : 's' }}
                                </span>
                            @endif
                        </div>
                    </summary>
                    <div class="pulse-body">
                        @if ($pipelineTotal === 0)
                            <p class="muted" style="font-size:13px;margin:0;">No active leads in the pipeline.</p>
                        @else
                            @if ($stalePipelineCount > 0)
                                <div class="pulse-stale-leads" style="margin-bottom:12px;padding:10px 12px;border:1px solid #fecaca;border-radius:10px;background:#fff7f7;">
                                    <div style="display:flex;justify-content:space-between;align-items:center;gap:8px;margin-bottom:7px;">
                                        <strong style="font-size:12px;">⚠️ Stale leads · 7+ days in current stage</strong>
                                        <span class="muted" style="font-size:11px;">{{ $stalePipelineCount }} total</span>
                                    </div>
                                    <div style="display:flex;flex-direction:column;gap:6px;">
                                        @foreach ($stalePipelineLeads as $staleLead)
                                            <a href="{{ url('/leads/' . $staleLead->id . '?return_to=' . urlencode(url()->full())) }}" class="pulse-link-row" style="display:flex;align-items:center;justify-content:space-between;gap:10px;text-decoration:none;">
                                                <span style="min-width:0;">
                                                    <strong style="font-size:12px;">{{ $staleLead->name }}</strong>
                                                    <span class="muted" style="font-size:11px;"> · {{ $staleLead->status_label }}</span>
                                                </span>
                                                <span class="badge red" style="font-size:10px;white-space:nowrap;">{{ $staleLead->age_days }}d</span>
                                            </a>
                                        @endforeach
                                    </div>
                                    @if ($stalePipelineCount > $stalePipelineLeads->count())
                                        <div class="muted" style="font-size:11px;margin-top:7px;">Showing the oldest {{ $stalePipelineLeads->count() }}; each link opens the lead directly.</div>
                                    @endif
                                </div>
                            @else
                                <div style="margin-bottom:12px;padding:9px 12px;border:1px solid #bbf7d0;border-radius:10px;background:#f0fdf4;font-size:12px;">✅ No lead has been in its current stage for 7+ days.</div>
                            @endif
                            <div class="pulse-aging-list">
                                @foreach ($businessPulse['pipeline_aging'] as $s)
                                    <a href="{{ url('/leads?status=' . urlencode($s->key) . $scopeSuffix) }}"
                                       class="pulse-aging-row pulse-link-row">
                                        <div class="pulse-aging-label">
                                            <span class="badge {{ $s->color }}" style="font-size:11px;">
                                                {{ $s->label }}
                                            </span>
                                        </div>
                                        <div class="pulse-aging-count">
                                            <strong>{{ $s->count }}</strong>
                                            <span class="muted" style="font-size:11px;">
                                                lead{{ $s->count === 1 ? '' : 's' }}
                                            </span>
                                            @if ($s->stale_count > 0)
                                                <span class="badge red" style="font-size:10px;">⚠️ {{ $s->stale_count }} stale</span>
                                            @endif
                                        </div>
                                        <div class="pulse-aging-age">
                                            @if ($s->oldest_days === null)
                                                <span class="muted" style="font-size:12px;">—</span>
                                            @else
                                                @php
                                                    $ageClass = $s->oldest_days >= 7 ? 'red'
                                                              : ($s->oldest_days >= 3 ? 'orange' : 'green');
                                                @endphp
                                                <span class="badge {{ $ageClass }}" style="font-size:11px;"
                                                      title="{{ $s->oldest_lead }}">
                                                    oldest: {{ $s->oldest_days }}d
                                                </span>
                                            @endif
                                        </div>
                                    </a>
                                @endforeach
                            </div>
                        @endif
                    </div>
                </details>

                <details class="pulse-details">
                    <summary class="pulse-summary">
                        <div class="pulse-summary-left">
                            <span class="pulse-summary-arrow">▸</span>
                            <span class="pulse-summary-title">🔥 Busiest Projects</span>
                        </div>
                        <div class="pulse-summary-right">
                            <span class="muted" style="font-size:12px;">top {{ $topProjectCount }}</span>
                        </div>
                    </summary>
                    <div class="pulse-body">
                        @if ($businessPulse['top_projects']->isEmpty())
                            <p class="muted" style="font-size:13px;margin:0;">No leads on any project yet.</p>
                        @else
                            <div class="pulse-project-list">
                                @foreach ($businessPulse['top_projects'] as $p)
                                    <a href="{{ url('/leads?project=' . $p->id . $scopeSuffix) }}"
                                       class="pulse-project-row pulse-link-row">
                                        <div class="pulse-project-name">🏗️ {{ $p->name }}</div>
                                        <span class="badge blue" style="font-size:11px;">
                                            {{ $p->lead_count }} lead{{ $p->lead_count === 1 ? '' : 's' }}
                                        </span>
                                    </a>
                                @endforeach
                            </div>
                        @endif
                    </div>
                </details>

                <details class="pulse-details">
                    <summary class="pulse-summary">
                        <div class="pulse-summary-left">
                            <span class="pulse-summary-arrow">▸</span>
                            <span class="pulse-summary-title">⚠️ Stale Projects</span>
                        </div>
                        <div class="pulse-summary-right">
                            @if ($staleProjectCount === 0)
                                <span class="badge green" style="font-size:11px;">✅ none</span>
                            @else
                                <span class="badge red" style="font-size:11px;">{{ $staleProjectCount }}</span>
                            @endif
                        </div>
                    </summary>
                    <div class="pulse-body">
                        <p class="muted" style="font-size:11px;margin:0 0 var(--s-2) 0;">
                            Projects with open leads and no activity in 14+ days.
                        </p>
                        @if ($businessPulse['stale_projects']->isEmpty())
                            <p class="muted" style="font-size:13px;margin:0;">✅ Everything is being worked on.</p>
                        @else
                            <div class="pulse-project-list">
                                @foreach ($businessPulse['stale_projects'] as $p)
                                    <a href="{{ url('/leads?project=' . $p->id . $scopeSuffix) }}"
                                       class="pulse-project-row pulse-link-row">
                                        <div class="pulse-project-name">🏗️ {{ $p->name }}</div>
                                        <div class="pulse-project-meta">
                                            <span class="badge orange" style="font-size:11px;">
                                                {{ $p->non_final }} open
                                            </span>
                                            @if ($p->days_silent !== null)
                                                <span class="muted" style="font-size:11px;">· silent {{ $p->days_silent }}d</span>
                                            @else
                                                <span class="muted" style="font-size:11px;">· never worked</span>
                                            @endif
                                        </div>
                                    </a>
                                @endforeach
                            </div>
                        @endif
                    </div>
                </details>
            </div>
        </details>
    @endif

    {{-- 6. TOMORROW PREVIEW --}}
    @if ($tomorrowTasks->isNotEmpty())
        <details class="dash-section">
            <summary class="dash-section-head">
                <span class="dsh-arrow">▸</span>
                <span class="dsh-icon">📆</span>
                <span class="dsh-title">TOMORROW</span>
                <span class="dsh-count">{{ $tomorrowCount }}</span>
            </summary>
            <div class="dash-section-body">
                @foreach ($tomorrowTasks->take(5) as $task)
                    <div class="task-preview-row">
                        <span class="task-preview-icon">
                            {{ $task->action_type === 'whatsapp_followup' ? '💬' : '📞' }}
                        </span>
                        <div style="flex:1;min-width:0;">
                            <div class="task-preview-name">
                                {{ $task->lead?->customer_name ?? 'Lead' }}
                                <x-task-type-chip :action-type="$task->action_type" style="margin-left:6px;" />
                            </div>
                            <div class="muted" style="font-size:12px;">
                                <x-relative-date :date="$task->scheduled_for" />
                                · 👤 <strong>{{ $task->agent?->user?->name ?? 'Unassigned' }}</strong>
                            </div>
                        </div>
                    </div>
                @endforeach
                @if ($tomorrowCount > 5)
                    <a href="{{ url('/tasks?view=upcoming' . $scopeSuffix . '#task-results') }}" style="display:block;text-align:center;margin-top:8px;font-size:13px;">
                        +{{ $tomorrowCount - 5 }} more →
                    </a>
                @endif
            </div>
        </details>
    @endif

    {{-- 7. RECENT LEADS --}}
    <details class="dash-section">
        <summary class="dash-section-head">
            <span class="dsh-arrow">▸</span>
            <span class="dsh-icon">📋</span>
            <span class="dsh-title">RECENT LEADS</span>
            <span class="dsh-count">{{ $recentLeads->count() }}</span>
        </summary>
        <div class="dash-section-body">
            @if ($recentLeads->isEmpty())
                <p class="muted">No leads yet. Tap ➕ New Lead to add the first one.</p>
            @else
                @foreach ($recentLeads as $lead)
                    <a href="{{ url('/leads/' . $lead->id) . '?return_to=' . urlencode(url()->full()) }}" class="lead-compact-row">
                        <div style="flex:1;min-width:0;">
                            <div class="lead-compact-name">
                                {{ $lead->customer_name }}
                                @if ($lead->tag)
                                    <x-lead-tag-chip :tag="$lead->tag" />
                                @endif
                            </div>

                            @if ($lead->labels->isNotEmpty())
                                <x-lead-labels-row :labels="$lead->labels" :max="3" />
                            @endif

                            <div class="muted" style="font-size:12px;margin-top:3px;">
                                {{ $lead->phone }}
                                @if ($lead->project?->name) · {{ $lead->project->name }} @endif
                                · 👤 <strong>{{ $lead->agent?->user?->name ?? 'Unassigned' }}</strong>
                            </div>
                        </div>
                        <x-status-badge :status="$lead->statusKey()" />
                    </a>
                @endforeach
            @endif
            <a href="{{ url('/leads' . $scopeFirst) }}" style="display:block;text-align:center;margin-top:8px;font-size:13px;">
                See all leads →
            </a>
        </div>
    </details>

        {{-- ============================================================ --}}
    @if ($isAdminOrMgr)
    {{-- 8. BUSINESS METRICS — Leads · Visits · Bookings --}}
    {{-- ============================================================ --}}
    <details class="dash-section dash-metrics">
        <summary class="dash-section-head">
            <span class="dsh-arrow">▸</span>
            <span class="dsh-icon">📊</span>
            <span class="dsh-title">STATS &amp; PIPELINE</span>
            <span class="dsh-count">Today</span>
        </summary>
        <div class="dash-section-body">

            {{-- ---------- LEADS ---------- --}}
            <div class="biz-group">
                <div class="biz-group-head">
                    <span class="biz-group-title">🎯 Leads</span>
                    <a href="{{ url('/leads' . $scopeFirst) }}" class="biz-group-link">All →</a>
                </div>
                <div class="biz-grid">
                    <a href="{{ url('/leads' . $scopeFirst) }}" class="biz-tile">
                        <span class="bt-count">{{ number_format($totalLeads) }}</span>
                        <span class="bt-label">Total</span>
                    </a>
                    <a href="{{ url('/leads?preset=new_today' . $scopeSuffix) }}" class="biz-tile bt-today">
                        <span class="bt-count">{{ number_format($statNewToday) }}</span>
                        <span class="bt-label">Today</span>
                    </a>
                    <a href="{{ url('/leads?preset=new_week' . $scopeSuffix) }}" class="biz-tile">
                        <span class="bt-count">{{ number_format($statNewWeek) }}</span>
                        <span class="bt-label">This Week</span>
                    </a>
                    <a href="{{ url('/leads?preset=active' . $scopeSuffix) }}" class="biz-tile bt-active">
                        <span class="bt-count">{{ number_format($statActive) }}</span>
                        <span class="bt-label">Active</span>
                    </a>
                </div>
            </div>

            {{-- ---------- SITE VISITS ---------- --}}
            <div class="biz-group">
                <div class="biz-group-head">
                    <span class="biz-group-title">🏠 Site Visits</span>
                    <a href="{{ url('/leads?preset=visits_scheduled' . $scopeSuffix) }}" class="biz-group-link">Scheduled →</a>
                </div>
                <div class="biz-grid">
                    <a href="{{ url('/leads?preset=visits_scheduled' . $scopeSuffix) }}" class="biz-tile bt-next">
                        <span class="bt-count">{{ number_format($statVisitsScheduled) }}</span>
                        <span class="bt-label">Scheduled (current)</span>
                    </a>
                    <a href="{{ url('/leads?preset=visits_done_actual' . $scopeSuffix) }}" class="biz-tile bt-visitdone">
                        <span class="bt-count">{{ number_format($statVisitsDoneActual) }}</span>
                        <span class="bt-label">Actually Done (all)</span>
                    </a>
                    <a href="{{ url('/leads?preset=visits_done_actual_week' . $scopeSuffix) }}" class="biz-tile">
                        <span class="bt-count">{{ number_format($statVisitsDoneActualWeek) }}</span>
                        <span class="bt-label">Actually Done This Week</span>
                    </a>
                    <a href="{{ url('/leads?preset=visits_next_7d' . $scopeSuffix) }}" class="biz-tile bt-next">
                        <span class="bt-count">{{ number_format($statVisitsNext7d) }}</span>
                        <span class="bt-label">Next 7 Days</span>
                    </a>
                    <a href="{{ url('/leads?preset=visits_today' . $scopeSuffix) }}" class="biz-tile bt-today">
                        <span class="bt-count">{{ number_format($statVisitsToday) }}</span>
                        <span class="bt-label">Today</span>
                    </a>
                </div>
            </div>

            {{-- ---------- BOOKINGS + PIPELINE ---------- --}}
            <div class="biz-group">
                <div class="biz-group-head">
                    <span class="biz-group-title">🎉 Bookings &amp; Closing Pipeline</span>
                    <a href="{{ url('/leads?status=booking' . $scopeSuffix) }}" class="biz-group-link">All booked →</a>
                </div>
                <div class="biz-grid">
                    <a href="{{ url('/leads?status=booking' . $scopeSuffix) }}" class="biz-tile bt-booked">
                        <span class="bt-count">{{ number_format($statBookedTotal) }}</span>
                        <span class="bt-label">Booked (all)</span>
                    </a>
                    <a href="{{ url('/leads?preset=bookings_month' . $scopeSuffix) }}" class="biz-tile bt-booked">
                        <span class="bt-count">{{ number_format($statBookedMonth) }}</span>
                        <span class="bt-label">This Month</span>
                    </a>
                    <a href="{{ url('/leads?status=negotiation' . $scopeSuffix) }}" class="biz-tile bt-negotiation">
                        <span class="bt-count">{{ number_format($statNegotiation) }}</span>
                        <span class="bt-label">Negotiation</span>
                    </a>
                    <a href="{{ url('/leads?status=visit_done' . $scopeSuffix) }}" class="biz-tile bt-visitdone">
                        <span class="bt-count">{{ number_format($statVisitDone) }}</span>
                        <span class="bt-label">Visit Done (current stage)</span>
                    </a>
                </div>
            </div>

            {{-- ---------- STATUS CHART ---------- --}}
            <div class="biz-chart">
                <div class="biz-group-head" style="margin-bottom:var(--s-2);">
                    <span class="biz-group-title">📊 Pipeline Breakdown <span class="muted" style="font-size:11px;font-weight:400;">(current lead stage)</span></span>
                </div>
                <x-status-chart :counts="$statusCounts" :scope="$workScope" />
            </div>

            {{-- ---------- AGENT COUNT (compact footer) ---------- --}}
            <div class="biz-footer">
                <span>👥 <strong>{{ $agentCount }}</strong> active agents</span>
                <span>⏳ <strong>{{ $totalPending }}</strong> current tasks</span>
                @if ($totalNurture > 0)
                    <span>🔄 <strong>{{ $totalNurture }}</strong> nurture</span>
                @endif
                <span>🚨 <strong>{{ $overdueCount }}</strong> overdue</span>
            </div>

        </div>
    </details>
    @endif

@endsection


<style>
.mgmt-summary-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin:14px 0 4px}
.mgmt-summary-card{display:flex;flex-direction:column;gap:3px;text-decoration:none!important;padding:15px 16px;border:1px solid var(--c-border,#e5e7eb);border-radius:12px;background:var(--c-surface,#fff);box-shadow:0 1px 3px rgba(0,0,0,.04);min-width:0}
.mgmt-summary-card:hover{transform:translateY(-1px)}
.mgmt-summary-card .msc-icon{font-size:20px}.mgmt-summary-card .msc-label{font-size:12px;color:var(--c-text-2,#667085);font-weight:700}.mgmt-summary-card strong{font-size:28px;line-height:1.1;color:var(--c-text,#101828)}.mgmt-summary-card small{font-size:11px;color:var(--c-text-2,#667085)}
.mgmt-danger{border-color:#fecaca}.mgmt-warn{border-color:#fed7aa}.mgmt-help{border-color:#c7d2fe}.mgmt-neutral{border-color:#d1d5db}
.mgmt-section-note{padding:10px 12px;margin-bottom:10px;border-radius:9px;background:#f8fafc;color:var(--c-text-2,#667085);font-size:12px}
.mgmt-more-link{display:inline-flex;align-items:center;margin-top:10px;font-weight:700;text-decoration:none;color:var(--c-primary,#2563eb);font-size:13px}
.mgmt-ok-box{padding:14px;border:1px solid #bbf7d0;background:#f0fdf4;border-radius:10px;font-size:13px}
.mgmt-team-list{display:flex;flex-direction:column;gap:8px}.mgmt-team-row{display:grid;grid-template-columns:minmax(0,1fr) auto auto;align-items:center;gap:10px;padding:10px 0;border-bottom:1px solid var(--c-border,#eef2f6)}.mgmt-team-row:last-child{border-bottom:0}.mgmt-team-counts{display:flex;gap:5px;flex-wrap:wrap}.mgmt-open-agent{font-size:12px;font-weight:700;text-decoration:none;color:var(--c-primary,#2563eb);white-space:nowrap}
.secondary-action-primary{border-color:#c7d2fe!important;background:#f8faff}
@media (max-width:900px){.mgmt-summary-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media (max-width:560px){.mgmt-summary-grid{grid-template-columns:repeat(2,minmax(0,1fr));gap:8px}.mgmt-summary-card{padding:12px}.mgmt-summary-card strong{font-size:23px}.mgmt-team-row{grid-template-columns:1fr auto}.mgmt-open-agent{grid-column:1/-1}}
</style>

@push('scripts')
<script>
(function () {
    var sections = document.querySelectorAll('.dash-section, .pulse-details');
    sections.forEach(function (el) {
        el.addEventListener('toggle', function () {
            if (!el.open) return;
            window.requestAnimationFrame(function () {
                var head = el.querySelector(':scope > summary');
                var body = el.querySelector(':scope > .dash-section-body, :scope > .pulse-body');
                var target = body || el;
                var rect = target.getBoundingClientRect();
                var topOffset = 90;
                if (rect.top < topOffset || rect.top > window.innerHeight * 0.72) {
                    window.scrollTo({ top: window.scrollY + rect.top - topOffset, behavior: 'smooth' });
                }
            });
        });
    });
})();
</script>
@endpush