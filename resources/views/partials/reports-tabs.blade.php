@php
    $currentReport = $currentReport ?? '';
@endphp

<nav class="settings-tabs" style="margin-bottom:var(--s-3);">
    <a href="{{ url('/reports?tab=conversion') }}"
       class="tab {{ $currentReport === 'conversion' ? 'active' : '' }}">🎯 Conversion</a>

    <a href="{{ url('/reports?tab=sources') }}"
       class="tab {{ $currentReport === 'sources' ? 'active' : '' }}">📥 Sources</a>

    <a href="{{ url('/reports?tab=agents') }}"
       class="tab {{ $currentReport === 'agents' ? 'active' : '' }}">👤 Agents</a>

    <a href="{{ url('/reports?tab=projects') }}"
       class="tab {{ $currentReport === 'projects' ? 'active' : '' }}">🏗️ Projects</a>

    <a href="{{ url('/reports/brokerage') }}"
       class="tab {{ $currentReport === 'brokerage' ? 'active' : '' }}">💼 Brokerage</a>

    <a href="{{ url('/reports/team') }}"
       class="tab {{ $currentReport === 'team' ? 'active' : '' }}">👥 Team Performance</a>

    <a href="{{ url('/reports/scorecard') }}"
       class="tab {{ $currentReport === 'scorecard' ? 'active' : '' }}">👤 Agent Scorecard</a>

    <a href="{{ url('/reports/crm-health') }}"
       class="tab {{ $currentReport === 'crm-health' ? 'active' : '' }}">🩺 CRM Health</a>

    <a href="{{ url('/reports/telecalling') }}"
       class="tab {{ $currentReport === 'telecalling' ? 'active' : '' }}">📞 Telecalling</a>

    @if(app(\App\Services\SuperAdminService::class)->isSuperAdmin())
        <a href="{{ url('/admin/work-summary?period=today') }}"
           class="tab {{ $currentReport === 'work-summary' ? 'active' : '' }}">👤 Super Admin Work</a>

        <a href="{{ url('/search?type=customers') }}"
           class="tab {{ $currentReport === 'customer-360' ? 'active' : '' }}">👤 Customer 360</a>
    @endif
</nav>
