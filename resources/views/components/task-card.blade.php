@props(['task', 'showActions' => true, 'earlyActionGate' => false, 'workOnly' => false, 'focused' => false, 'returnTo' => null])

@php
    $settings    = app(\App\Services\SettingsService::class);
    $actionType  = $settings->actionTypeByKey($task->action_type);
    $actionLabel = $actionType?->label ?? $task->action_type;

    $isOverdue   = $task->scheduled_for && now()->greaterThan($task->scheduled_for);

    $isFuture = $task->scheduled_for && $task->scheduled_for->isFuture();
    $actionsLocked = (bool) $isFuture;
    $isEscalated = (bool) $task->escalated_flag;
    $class       = $isOverdue ? 'task-overdue' : ($isEscalated ? 'task-escalated' : '');

    $overdueBy = $isOverdue
        ? $task->scheduled_for->diffForHumans(null, true) . ' overdue'
        : $task->scheduled_for?->diffForHumans();

    $isFreshAuto = $task->auto_created
        && $task->created_at
        && $task->created_at->isAfter(now()->subMinutes(5));

    $highlight = 'call';
    if (str_contains((string) $task->action_type, 'whatsapp')) {
        $highlight = 'whatsapp';
    } elseif (str_contains((string) $task->action_type, 'email')) {
        $highlight = null;
    }

    $leadUrl = url('/leads/' . $task->lead_id);
    // The originating work surface is a first-class part of the task action.
    // On My Work/Tasks the current request is the origin; when this card is
    // rendered on the Lead Workbench, the controller/view supplies the original
    // Tasks URL through the returnTo prop.
    $workReturnTo = (string) ($returnTo ?: '');
    if ($workReturnTo === '' && (request()->is('/') || request()->is('tasks*'))) {
        $workReturnTo = request()->getRequestUri() ?: '/tasks';
    }
    $workReturnTo = $workReturnTo ?: '/';
    $workUrl = $leadUrl . '?' . http_build_query([
        'focus_work' => 1,
        'focus_followup_id' => $task->id,
        'return_to' => $workReturnTo,
    ]) . '#pending-tasks';
    $historyParams = [
        'focus_history' => 1,
        'focus_followup_id' => $task->id,
    ];
    if ($workReturnTo) {
        $historyParams['return_to'] = $workReturnTo;
    }
    $historyUrl = $leadUrl . '?' . http_build_query($historyParams) . '#activity-history';
    $projectName = $task->lead?->project?->name;
    $agentName   = $task->agent?->user?->name;
    $agentPhone  = $task->agent?->phone;

    $role      = session('user_role');
    $canNudge  = in_array($role, ['admin', 'team_manager'], true);
    $showNudge = $canNudge && ($isOverdue || $isEscalated) && $agentPhone && $task->lead;

    if ($showNudge) {
        $first = explode(' ', trim($agentName ?: 'Agent'))[0];

        $nudgeMsg = "Hi {$first},\n\n"
                  . "Follow-up on {$task->lead->customer_name}"
                  . ($projectName ? " ({$projectName})" : '')
                  . " was due " . ($isOverdue ? $overdueBy : 'now') . " and is still pending.\n\n"
                  . "🔗 Open lead: {$leadUrl}\n\n"
                  . "Please attend today.\n\n"
                  . "— " . session('user_name', 'Manager');

        $waNumber = preg_replace('/\D/', '', $agentPhone);
        $nudgePhone = $waNumber;
        $nudgeUrl = null;
        $nudgeCount = app(\App\Services\NudgeService::class)->countForFollowup((int) $task->id);
    }
@endphp

<div class="task-card {{ $class }} {{ $workOnly ? 'task-card-work-only' : '' }} {{ $focused ? 'task-card-focused' : '' }}">

    <div class="tc-main task-card-open" data-lead-url="{{ $workOnly ? $workUrl : $leadUrl }}" role="link" tabindex="0" aria-label="Open lead {{ $task->lead->customer_name ?? 'lead' }}">

        <div class="tc-row-1">
            <a href="{{ $workOnly ? $workUrl : $leadUrl }}" class="task-lead-link">
                {{ $task->lead->customer_name ?? '—' }}
            </a>
            <x-task-type-chip :action-type="$task->action_type" />
            @if ($isEscalated)
                <span class="tc-escalated" title="Escalated">🚨</span>
            @endif
        </div>

        <div class="tc-row-2">
            @if ($projectName)
                <span class="tc-meta-item" title="Project">🏗️ {{ $projectName }}</span>
            @endif

            @if ($agentName)
                <span class="tc-meta-item" title="Assigned to {{ $agentName }}">👤 {{ $agentName }}</span>
            @endif

            <span class="tc-meta-item {{ $isOverdue ? 'tc-time-overdue' : '' }}">
                🕒
                @if ($isOverdue)
                    <strong>{{ $overdueBy }}</strong>
                @else
                    <x-relative-date :date="$task->scheduled_for" />
                @endif
            </span>
        </div>

        @if ($isFreshAuto)
            <div class="tc-auto-line" title="Auto-scheduled by the last logged outcome">
                🔄 Auto-scheduled just now
            </div>
        @endif

        @if ($showNudge)
            <button type="button" data-npo-nudge data-nudge-target-type="followup" data-nudge-target-id="{{ $task->id }}" data-npo-whatsapp data-whatsapp-phone="{{ $nudgePhone }}" data-whatsapp-text="{{ base64_encode($nudgeMsg) }}"
               class="tc-nudge-link"
               title="Nudge {{ $agentName }} on WhatsApp">
                <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                    <path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/>
                </svg>
                Nudge {{ explode(' ', trim($agentName))[0] ?? 'agent' }} @if(($nudgeCount ?? 0) > 0)<span class="tc-nudge-count npo-nudge-count">{{ $nudgeCount }}×</span>@endif
            </button>
        @endif

    </div>

    @if ($workOnly)
        <div class="tc-work-open">
            <a href="{{ $workUrl }}" class="tc-work-open-btn">
                <span>▶</span> Open Lead &amp; Work
            </a>
        </div>
    @elseif ($showActions)
        <div class="tc-actions {{ $actionsLocked ? 'tc-actions-gated' : '' }}" @if($actionsLocked) data-task-gate="1" data-task-due="{{ $task->scheduled_for->toIso8601String() }}" @endif>
            @if ($actionsLocked)
                <div class="tc-early-lock" data-task-gate-message>
                    <span class="tc-early-lock-title">⏳ Actions unlock at <x-relative-date :date="$task->scheduled_for" /></span>
                    <span class="tc-early-countdown" data-task-countdown>Calculating time remaining…</span>
                </div>
            @endif
            <div class="tc-gated-actions" data-gated-actions>
            @if ($task->lead)
                <div class="tc-quick-actions">
                    <x-contact-buttons :lead="$task->lead" :highlight="$highlight" />
                    @if (in_array(session('user_role'), ['admin','team_manager','agent'], true))
                        <button type="button" class="btn-small btn-info"
                                data-modal="task-share-lead"
                                data-followup-id="{{ $task->id }}"
                                data-lead-id="{{ $task->lead_id }}"
                                @if($workReturnTo !== '/') data-return-to="{{ e($workReturnTo) }}" @endif>
                            📤 Share Lead
                        </button>
                    @endif
                </div>
            @endif

            <div class="tc-done-wrap">
                <button type="button" class="tc-done-btn"
                    data-modal="complete-task" data-followup-id="{{ $task->id }}" data-lead-id="{{ $task->lead_id }}" @if($workReturnTo !== '/') data-return-to="{{ e($workReturnTo) }}" @endif>
                <span class="tc-done-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round">
                        <path d="m5 12 4 4L19 6"/>
                    </svg>
                </span>
                <span class="tc-done-text">Done — Log Now</span>
                </button>
                <div class="tc-done-help">Record what you did to complete this follow-up.</div>
            </div>
            </div>
            @if ($actionsLocked)
                <button type="button" class="tc-start-early" data-task-start-early>Start Early</button>
                @if (in_array(session('user_role'), ['admin','team_manager','agent'], true))
                    <button type="button" class="btn-small btn-danger" data-modal="dispose-followup" data-lead-id="{{ $task->lead_id }}" data-followup-id="{{ $task->id }}">Cancel Future Task</button>
                @endif
            @endif
        </div>
    @else
        <div style="padding:0 14px 12px 14px;">
            <div style="background:#eef7ff;border:1px solid #cfe7fb;border-radius:8px;padding:9px 11px;font-size:12px;color:#245b7a;font-weight:600;">
                🕒 Not due yet — no action needed now. It will move into your work queue when due.
            </div>
        </div>
    @endif

</div>