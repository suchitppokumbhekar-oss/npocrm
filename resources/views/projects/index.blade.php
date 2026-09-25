@extends('layouts.app')

@section('title', 'Projects — NPO CRM')

@php $isAdmin = session('user_role') === 'admin'; @endphp

@section('content')
<div class="page-head project-page-head">
    <div>
        <a href="{{ url('/') }}" class="back-link">← My Work</a>
        <h1 class="page-title">Projects</h1>
        <p class="page-subtitle">Projects routed to your team. Routing is managed separately.</p>
    </div>
    @if ($isAdmin)
        <button type="button" class="btn btn-primary" data-modal="add-project">＋ New Project</button>
    @endif
</div>

<div class="card project-toolbar">
    <form method="GET" action="{{ url('/projects') }}" class="project-filter-form">
        <div class="project-search-field">
            <label for="project-search">Find project</label>
            <input id="project-search" class="input" type="search" name="q" value="{{ $q }}" placeholder="Name, location or RERA…" autocomplete="off">
        </div>
        <div>
            <label for="project-status">Status</label>
            <select id="project-status" class="input" name="status">
                <option value="active" @selected($status === 'active')>Active</option>
                <option value="inactive" @selected($status === 'inactive')>Inactive</option>
                <option value="all" @selected($status === 'all')>All</option>
            </select>
        </div>
        <button type="submit" class="btn btn-info">Search</button>
        @if ($q !== '' || $status !== 'active')
            <a href="{{ url('/projects') }}" class="btn-small btn-ghost">Clear</a>
        @endif
    </form>
</div>

<div class="card">
    <div class="section-head project-list-head">
        <h3>Project master <span class="muted">· {{ number_format($projects->total()) }}</span></h3>
        @if ($projects->hasPages()) <span class="muted">{{ $projects->firstItem() }}–{{ $projects->lastItem() }}</span> @endif
    </div>

    @if ($projects->isEmpty())
        <div class="project-empty"><div>🏗️</div><strong>No projects found</strong><span class="muted">Try a different search or status filter.</span></div>
    @else
        <div class="project-list-table table-wrap">
            <table class="sortable-project-table">
                <thead><tr>
                    @php
                        $headers = ['name'=>'Project','location'=>'Location','rera_number'=>'RERA','leads_count'=>'Leads','status'=>'Status'];
                    @endphp
                    @foreach ($headers as $key => $label)
                        @php $nextDir = ($sort === $key && $direction === 'asc') ? 'desc' : 'asc'; @endphp
                        <th><a class="project-sort-link" href="{{ request()->fullUrlWithQuery(['sort'=>$key,'direction'=>$nextDir,'page'=>1]) }}">{{ $label }} <span class="sort-indicator">{{ $sort === $key ? ($direction === 'asc' ? '▲' : '▼') : '↕' }}</span></a></th>
                    @endforeach
                    <th>Actions</th>
                </tr></thead>
                <tbody>
                @foreach ($projects as $project)
                    <tr>
                        <td><strong>{{ $project->name }}</strong></td>
                        <td class="muted">{{ $project->location }}</td>
                        <td class="muted">{{ $project->rera_number ?: '—' }}</td>
                        <td><span class="badge blue">{{ $project->leads_count }}</span></td>
                        <td>@if ($project->status === 'active') <span class="badge green">Active</span> @else <span class="badge red">Inactive</span> @endif</td>
                        <td class="row-actions"><a href="{{ url('/leads?project=' . $project->id) }}" class="btn-small btn-ghost">Leads</a><a href="{{ route('projects.media', $project->id) }}" class="btn-small btn-ghost">Media</a>@if ($isAdmin)<button type="button" class="btn-small btn-info" data-modal="edit-project" data-project="{{ $project->id }}">Edit</button><a href="{{ url('/settings?tab=routing&project=' . $project->id) }}" class="btn-small btn-ghost">Routing</a>@endif</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>

        <div class="project-mobile-list">
            @foreach ($projects as $project)
                <article class="project-mobile-card"><div class="project-mobile-top"><div><strong>{{ $project->name }}</strong><div class="muted">{{ $project->location }}</div></div>@if ($project->status === 'active') <span class="badge green">Active</span> @else <span class="badge red">Inactive</span> @endif</div><div class="project-mobile-meta"><span>🎯 {{ $project->leads_count }} leads</span><span>RERA: {{ $project->rera_number ?: '—' }}</span></div><div class="project-mobile-actions"><a href="{{ url('/leads?project=' . $project->id) }}" class="btn-small btn-info">View Leads</a><a href="{{ route('projects.media', $project->id) }}" class="btn-small btn-ghost">Media</a>@if ($isAdmin)<button type="button" class="btn-small btn-ghost" data-modal="edit-project" data-project="{{ $project->id }}">Edit</button><a href="{{ url('/settings?tab=routing&project=' . $project->id) }}" class="btn-small btn-ghost">Routing</a>@endif</div></article>
            @endforeach
        </div>

        @if ($projects->hasPages()) <div class="project-pagination">{{ $projects->links() }}</div> @endif
    @endif
</div>
@endsection
