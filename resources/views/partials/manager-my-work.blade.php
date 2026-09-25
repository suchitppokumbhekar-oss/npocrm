{{-- TEAM MANAGER — PERSONAL WORK QUEUE --}}
<div class="manager-command-block manager-my-work">
    <div class="manager-command-heading">
        <div>
            <span class="manager-command-kicker">PERSONAL QUEUE</span>
            <h3>⚡ MY WORK</h3>
            <p>Your own leads and follow-ups.</p>
        </div>

        <a href="{{ url('/tasks?scope=personal') }}"
           class="manager-command-open">
            Open →
        </a>
    </div>

    <div class="manager-command-metrics">
        <a href="{{ url('/tasks?scope=personal') }}"
           class="manager-command-metric metric-danger">
            <strong>{{ $personalOverdueCount }}</strong>
            <span>Overdue</span>
        </a>

        <a href="{{ url('/tasks?scope=personal') }}"
           class="manager-command-metric metric-warn">
            <strong>{{ $personalTodayCount }}</strong>
            <span>Due Today</span>
        </a>

        <a href="{{ url('/?scope=personal#untouched-work') }}"
           class="manager-command-metric metric-new">
            <strong>{{ $untouchedLeadCount ?? 0 }}</strong>
            <span>New Leads</span>
        </a>
    </div>
</div>