@extends('layouts.app')

@section('title', 'My Team — NPO CRM')

@section('content')

    <a href="{{ url('/') }}" class="back-link">← Back to Dashboard</a>

    <div class="card">
        <h2>👥 My Team</h2>
        <p class="muted" style="font-size:13px;margin-top:4px;">
            Manage your team's agents and see the projects your team handles.
        </p>
    </div>

    {{-- ============================================================ --}}
    {{-- TEAMS --}}
    {{-- ============================================================ --}}
    @if ($teams->isEmpty())
        <div class="card" style="border-left:4px solid var(--c-warn);">
            <h3>⚠️ No active team</h3>
            <p class="muted">
                You don't manage any active teams yet. Contact your admin to be assigned one.
            </p>
        </div>
    @else
        <div class="card">
            <div class="section-head">
                <h3>👥 Your Team{{ $teams->total() > 1 ? 's' : '' }}
                    <span class="muted" style="font-size:12px;">
                        · {{ number_format($teams->total()) }} total
                    </span>
                </h3>
            </div>

            @foreach ($teams as $team)
                <div style="padding:var(--s-2) 0;border-bottom:1px solid var(--c-border-2);">
                    <strong>{{ $team->name }}</strong>
                    @if ($team->description)
                        <br><span class="muted" style="font-size:12px;">{{ $team->description }}</span>
                    @endif
                </div>
            @endforeach

            @include('partials.pagination', ['paginator' => $teams])
        </div>
    @endif

    {{-- ============================================================ --}}
    {{-- AGENTS --}}
    {{-- ============================================================ --}}
    <div class="card">
        <div class="section-head">
            <h3>👤 Agents
                <span class="muted" style="font-size:12px;">
                    · {{ number_format($agents->total()) }} total
                    @if ($agents->total() > $agents->perPage())
                        · showing {{ $agents->firstItem() }}–{{ $agents->lastItem() }}
                    @endif
                </span>
            </h3>
            <button type="button" class="btn-small btn-info"
                    data-modal="my-team-agent-form"
                    @if ($teams->isEmpty()) disabled @endif>
                ➕ Add Agent
            </button>
        </div>

        @if ($agents->isEmpty())
            <p class="muted">
                No agents in your team yet. Click <strong>➕ Add Agent</strong> to create the first one.
            </p>
        @else
            <div class="table-wrap">
                <table>
                    <tr>
                        <th>Name</th>
                        <th>Email</th>
                        <th>Phone</th>
                        <th>Load</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                    @foreach ($agents as $agent)
                        <tr>
                            <td><strong>{{ $agent->user?->name ?? '—' }}</strong></td>
                            <td>
                                <span class="muted" style="font-size:12px;">
                                    {{ $agent->user?->email ?? '—' }}
                                </span>
                            </td>
                            <td>{{ $agent->phone ?? '—' }}</td>
                            <td>
                                <span class="badge {{ $agent->current_load >= $agent->max_daily_leads ? 'red' : ($agent->current_load > 0 ? 'orange' : 'blue') }}">
                                    {{ $agent->current_load }} / {{ $agent->max_daily_leads }}
                                </span>
                            </td>
                            <td>
                                @if ($agent->status === 'active')
                                    <span class="badge green">Active</span>
                                @else
                                    <span class="badge red">Inactive</span>
                                @endif
                            </td>
                            <td class="row-actions">
                                <button class="btn-small btn-info"
                                        data-modal="my-team-agent-form"
                                        data-agent="{{ $agent->id }}">✏️</button>
                                <form method="POST" action="/my-team/agents/{{ $agent->id }}/toggle"
                                      style="display:inline;">
                                    @csrf
                                    <button type="submit" class="btn-small"
                                            title="Toggle active status">
                                        {{ $agent->status === 'active' ? '⏸' : '▶' }}
                                    </button>
                                </form>
                                <form method="POST" action="/my-team/agents/{{ $agent->id }}/reset-load"
                                      style="display:inline;"
                                      onsubmit="return confirm('Recount this agent\'s active leads?');">
                                    @csrf
                                    <button type="submit" class="btn-small" title="Recount active leads">🔄</button>
                                </form>
                                <form method="POST" action="/my-team/agents/{{ $agent->id }}/remove"
                                      style="display:inline;"
                                      onsubmit="return confirm('Remove this agent from your team?');">
                                    @csrf
                                    <button type="submit" class="btn-small"
                                            style="background:var(--c-danger);"
                                            title="Remove from team">✕</button>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                </table>
            </div>

            @include('partials.pagination', ['paginator' => $agents])
        @endif
    </div>

    {{-- ============================================================ --}}
    {{-- YOUR TEAM'S PROJECTS (read-only) --}}
    {{-- ============================================================ --}}
    <div class="card">
        <div class="section-head">
            <h3>🎯 Your Team's Projects
                <span class="muted" style="font-size:12px;">
                    · {{ number_format($projects->total()) }} total
                    @if ($projects->total() > $projects->perPage())
                        · showing {{ $projects->firstItem() }}–{{ $projects->lastItem() }}
                    @endif
                </span>
            </h3>
        </div>

        <p class="muted" style="font-size:13px;">
            These are the projects your team handles. When a lead arrives for one of these,
            it goes to the least-loaded agent in your team.
        </p>
        <p class="muted" style="font-size:12px;margin-top:var(--s-2);padding:8px 12px;background:var(--c-surface-2);border-left:3px solid var(--c-info);border-radius:6px;">
            🔒 Only admins can assign new projects to your team. Contact your admin to add a project.
        </p>

        @if ($projects->isEmpty())
            <div style="border-left:4px solid var(--c-warn);padding:var(--s-3);margin-top:var(--s-3);background:#fff8e1;border-radius:6px;">
                <p class="muted" style="margin:0;">
                    No projects are assigned to your team yet. Ask your admin to assign some.
                </p>
            </div>
        @else
            <div class="table-wrap" style="margin-top:var(--s-3);">
                <table>
                    <tr>
                        <th>Project</th>
                        <th>Location</th>
                    </tr>
                    @foreach ($projects as $p)
                        <tr>
                            <td><strong>{{ $p->name }}</strong></td>
                            <td>
                                <span class="muted" style="font-size:12px;">{{ $p->location ?? '—' }}</span>
                            </td>
                        </tr>
                    @endforeach
                </table>
            </div>

            @include('partials.pagination', ['paginator' => $projects])
        @endif
    </div>

@endsection