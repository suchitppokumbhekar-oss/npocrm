@extends('layouts.app')

@section('title', 'Contacts — NPO CRM')

@section('content')

<a href="{{ url('/') }}" class="back-link">← Back to dashboard</a>

<div class="card">
    <div class="section-head">
        <div>
            <h2 style="margin:0;">📇 Contacts</h2>
            <p class="muted" style="margin-top:4px;font-size:13px;">
                {{ number_format($contacts->total()) }} contact{{ $contacts->total() === 1 ? '' : 's' }}
            </p>
        </div>
        <div style="display:flex;gap:var(--s-2);flex-wrap:wrap;">
            <a href="{{ url('/contacts/dialer') }}" class="btn-small btn-info">📞 Start Calling</a>
            @if (app(\App\Services\AccessService::class)->canImportContacts())
                <a href="{{ url('/contacts/import') }}" class="btn-small"
                   style="background:transparent;color:var(--c-text);border:1.5px solid var(--c-border);">
                    📥 Import CSV
                </a>
            @endif
        </div>
    </div>
</div>

{{-- FILTERS --}}
<div class="card">
    <form method="GET" action="{{ url('/contacts') }}">
        <div class="flex" style="flex-wrap:wrap;gap:var(--s-2);">
            <div class="flex-item" style="min-width:200px;">
                <label>🔍 Search</label>
                <input type="text" name="q" class="input" value="{{ $q }}" placeholder="Name, phone, email">
            </div>
            <div class="flex-item" style="min-width:140px;">
                <label>Status</label>
                <select name="status" class="input">
                    <option value="">All</option>
                    @foreach (['new','called','interested','not_interested','converted','dnc','invalid'] as $st)
                        <option value="{{ $st }}" @selected($status === $st)>{{ ucfirst(str_replace('_',' ',$st)) }}</option>
                    @endforeach
                </select>
            </div>
            <div class="flex-item" style="min-width:180px;">
                <label>Project</label>
                <select name="project_id" class="input">
                    <option value="">All projects</option>
                    @foreach ($projects as $p)
                        <option value="{{ $p->id }}" @selected((string)$projectId === (string)$p->id)>{{ $p->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="flex-item" style="min-width:180px;">
                <label>Agent</label>
                <select name="agent_id" class="input">
                    <option value="">All agents</option>
                    @foreach ($agents as $a)
                        <option value="{{ $a->id }}" @selected((string)$agentId === (string)$a->id)>
                            {{ $a->user?->name ?? 'Agent #'.$a->id }}
                        </option>
                    @endforeach
                </select>
            </div>
        </div>
        <div style="margin-top:var(--s-2);display:flex;gap:var(--s-2);flex-wrap:wrap;">
            <button type="submit" class="btn-small btn-info">🔍 Apply</button>
            <a href="{{ url('/contacts') }}" class="btn-small"
               style="background:transparent;color:var(--c-text);border:1.5px solid var(--c-border);">Reset</a>
        </div>
    </form>
</div>

{{-- TABLE --}}
<div class="card">
    @if ($contacts->isEmpty())
        <p class="muted">No contacts match your filters.</p>
    @else
        <div class="table-wrap">
            <table>
                <tr>
                    <th>Name</th>
                    <th>Phone</th>
                    <th>Project</th>
                    <th>Assigned</th>
                    <th>Attempts</th>
                    <th>Connected</th>
                    <th>Last Outcome</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
                @foreach ($contacts as $c)
                    <tr>
                        <td>
                            <a href="{{ url('/contacts/'.$c->id) }}" style="font-weight:600;color:inherit;">
                                {{ $c->name }}
                            </a>
                        </td>
                        <td>{{ $c->phone }}</td>
                        <td><span class="muted" style="font-size:12px;">{{ $c->project?->name ?? '—' }}</span></td>
                        <td>
                            @if ($c->agent?->user?->name)
                                <span class="badge purple" style="font-size:11px;">{{ $c->agent->user->name }}</span>
                            @else
                                <span class="muted">—</span>
                            @endif
                        </td>
                        <td>{{ $c->attempts }}</td>
                        <td>
                            @if ($c->connected_count > 0)
                                <span class="badge green">{{ $c->connected_count }}</span>
                            @else
                                <span class="muted">0</span>
                            @endif
                        </td>
                        <td>
                            @if ($c->last_outcome_key)
                                <span class="muted" style="font-size:12px;">{{ $c->last_outcome_key }}</span>
                            @else
                                <span class="muted">—</span>
                            @endif
                        </td>
                        <td>
                            @php
                                $badge = [
                                    'new' => 'blue', 'called' => 'orange', 'interested' => 'green',
                                    'not_interested' => 'red', 'converted' => 'green',
                                    'dnc' => 'red', 'invalid' => 'red',
                                ][$c->status] ?? 'blue';
                            @endphp
                            <span class="badge {{ $badge }}">{{ ucfirst(str_replace('_',' ',$c->status)) }}</span>
                        </td>
                        <td>
                            <a href="{{ url('/contacts/'.$c->id) }}" class="btn-small btn-info">View</a>
                        </td>
                    </tr>
                @endforeach
            </table>
        </div>
        @include('partials.pagination', ['paginator' => $contacts])
    @endif
</div>

@endsection