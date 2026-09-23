@extends('layouts.app')

@section('title', 'My Work — NPO CRM')

@section('content')

    @php
        $mode = $viewMode ?? 'now';
        $isTeamManager = session('user_role') === 'team_manager';
        $scopeQuery = $isTeamManager ? '&scope=' . ($workScope ?? 'team') : '';
        $displayScope = $isTeamManager ? ($workScope ?? 'team') : 'personal';
        $scopeLabel = match ($displayScope) { 'personal' => 'My Work', 'delegated' => 'All Delegated', default => 'My Team' };
        $modeUrls = [
            'now'      => url('/tasks?view=now' . $scopeQuery . '#task-results'),
            'upcoming' => url('/tasks?view=upcoming' . $scopeQuery . '#task-results'),
            'nurture'  => url('/tasks?view=nurture' . $scopeQuery . '#task-results'),
        ];
        $modeCopy = [
            'now'      => ['label' => 'Do now', 'title' => ($displayScope === 'personal' ? 'Your work now' : ($displayScope === 'delegated' ? 'Delegated work now' : 'Team work now')), 'desc' => 'Only work that needs attention today. Overdue comes first.'],
            'upcoming' => ['label' => 'Coming up', 'title' => 'Coming up', 'desc' => 'See what is next without mixing it into today’s work.'],
            'nurture'  => ['label' => 'Nurture', 'title' => 'Nurture later', 'desc' => 'Future reactivation reminders. Nothing here needs action right now.'],
        ];
    @endphp

    <div class="task-desk" id="task-desk">
        <a href="{{ url('/') }}" class="back-link js-smart-back" data-back-fallback="{{ url('/') }}">← Back</a>

        <section class="task-desk-hero">
            <div>
                <div class="task-desk-kicker">{{ strtoupper($scopeLabel) }}</div>
                <h2>{{ $modeCopy[$mode]['title'] }}</h2>
                <p>{{ $modeCopy[$mode]['desc'] }}</p>
            </div>
            <div class="task-desk-now-pill">
                <span>⚡</span>
                <strong>{{ number_format($viewCounts['now']) }}</strong>
                <small>to do now</small>
            </div>
        </section>

        @if ($isTeamManager)
            <nav class="task-scope-tabs task-scope-tabs-three" aria-label="Work scope">
                <a href="{{ url('/tasks?view=' . $mode . '&scope=personal#task-results') }}" class="task-scope-tab {{ ($workScope ?? 'team') === 'personal' ? 'active' : '' }}">
                    <strong>My Work</strong>
                    <small>Only your own tasks</small>
                </a>
                <a href="{{ url('/tasks?view=' . $mode . '&scope=team#task-results') }}" class="task-scope-tab {{ ($workScope ?? 'team') === 'team' ? 'active' : '' }}">
                    <strong>My Team</strong>
                    <small>Direct team responsibility</small>
                </a>
                <a href="{{ url('/tasks?view=' . $mode . '&scope=delegated#task-results') }}" class="task-scope-tab {{ ($workScope ?? 'team') === 'delegated' ? 'active' : '' }}">
                    <strong>All Delegated</strong>
                    <small>Other teams &amp; agents in your scope</small>
                </a>
            </nav>
        @endif

        {{-- Three decisions only: now, next, later. Counts are navigation, not decoration. --}}
        <nav class="task-work-tabs" aria-label="Work queue">
            <a href="{{ $modeUrls['now'] }}" class="task-work-tab {{ $mode === 'now' ? 'active' : '' }}">
                <span>⚡</span>
                <strong>{{ number_format($viewCounts['now']) }}</strong>
                <small>Do now</small>
            </a>
            <a href="{{ $modeUrls['upcoming'] }}" class="task-work-tab {{ $mode === 'upcoming' ? 'active' : '' }}">
                <span>🗓️</span>
                <strong>{{ number_format($viewCounts['upcoming']) }}</strong>
                <small>Coming up</small>
            </a>
            <a href="{{ $modeUrls['nurture'] }}" class="task-work-tab {{ $mode === 'nurture' ? 'active' : '' }}">
                <span>🌱</span>
                <strong>{{ number_format($viewCounts['nurture']) }}</strong>
                <small>Nurture</small>
            </a>
        </nav>

        @if ($mode === 'now')
            <section class="task-queue-guide" aria-label="How to use your work queue">
                <strong>Start at the top.</strong>
                <span>Open the lead, do the work, then log the outcome. The next action is created from that outcome.</span>
            </section>
        @elseif ($mode === 'upcoming')
            <section class="task-queue-guide" aria-label="Upcoming work guidance">
                <strong>Plan, don’t chase.</strong>
                <span>These tasks are scheduled for later. Use them to prepare without cluttering your immediate work.</span>
            </section>
        @else
            <section class="task-queue-guide" aria-label="Nurture guidance">
                <strong>Nothing to do yet.</strong>
                <span>These are future reactivation reminders. They stay separate so today’s work remains clear.</span>
            </section>
        @endif

        <section class="task-results" id="task-results">
            @if ($mode === 'now')
                @foreach (['overdue' => ['label' => '🚨 Overdue', 'class' => 'queue-overdue'], 'today' => ['label' => '📅 Today', 'class' => 'queue-soon']] as $key => $meta)
                    @if (isset($buckets[$key]) && $buckets[$key]->isNotEmpty())
                        <div class="task-queue-block {{ $meta['class'] }}">
                            <div class="task-queue-head">
                                <div>
                                    <h3>{{ $meta['label'] }}</h3>
                                    <p>{{ $key === 'overdue' ? 'These need attention before anything else.' : 'Your scheduled work for today.' }}</p>
                                </div>
                                <span>{{ $buckets[$key]->count() }}</span>
                            </div>
                            @foreach ($buckets[$key] as $task)
                                <x-task-card :task="$task" :early-action-gate="true" :show-actions="false" :work-only="true" />
                            @endforeach
                        </div>
                    @endif
                @endforeach
            @elseif ($mode === 'upcoming')
                @foreach (['tomorrow' => '📆 Tomorrow', 'this_week' => '📅 This week', 'later' => '🗓️ Later'] as $key => $label)
                    @if (isset($buckets[$key]) && $buckets[$key]->isNotEmpty())
                        <div class="task-queue-block">
                            <div class="task-queue-head">
                                <div><h3>{{ $label }}</h3><p>Scheduled work — no need to act early.</p></div>
                                <span>{{ $buckets[$key]->count() }}</span>
                            </div>
                            @foreach ($buckets[$key] as $task)
                                <x-task-card :task="$task" :early-action-gate="true" :show-actions="false" :work-only="true" />
                            @endforeach
                        </div>
                    @endif
                @endforeach
            @else
                @if (isset($buckets['reactivation']) && $buckets['reactivation']->isNotEmpty())
                    <div class="task-queue-block">
                        <div class="task-queue-head">
                            <div><h3>🌱 Reactivation</h3><p>Future Lost-lead nurture reminders.</p></div>
                            <span>{{ $buckets['reactivation']->count() }}</span>
                        </div>
                        @foreach ($buckets['reactivation'] as $task)
                            <x-task-card :task="$task" :early-action-gate="true" :show-actions="false" :work-only="true" />
                        @endforeach
                    </div>
                @endif
            @endif

            @if ($viewCounts[$mode] === 0)
                <div class="task-empty-state">
                    <div class="task-empty-icon">{{ $mode === 'now' ? '🎉' : ($mode === 'upcoming' ? '🧘' : '🌱') }}</div>
                    <h3>{{ $mode === 'now' ? 'You’re clear.' : ($mode === 'upcoming' ? 'Nothing scheduled.' : 'No nurture reminders.') }}</h3>
                    <p>{{ $mode === 'now' ? 'No active work needs your attention right now.' : ($mode === 'upcoming' ? 'There is nothing else scheduled in the coming days.' : 'Future reactivation work will appear here when scheduled.') }}</p>
                </div>
            @endif
        </section>
    </div>
@endsection
