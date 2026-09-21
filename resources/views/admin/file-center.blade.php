@extends('layouts.app')

@section('title', 'File Center — NPO CRM')

@section('content')
<div class="page-head" style="margin-bottom:14px;">
    <div>
        <h2 style="margin:0 0 4px;">🗂️ Super Admin File Center</h2>
        <p class="muted" style="margin:0;font-size:12px;">Single controlled place for CRM file downloads. Every uploaded file is privately stored and attributed; every download is recorded in Vigilance Audit.</p>
    </div>
</div>

<div class="card" style="margin-bottom:14px;">
    <form method="GET" action="{{ route('admin.file-center') }}" class="file-filter-grid">
        <label>Search
            <input type="search" name="q" value="{{ request('q') }}" placeholder="filename, source, SHA-256…">
        </label>
        <label>Uploader
            <select name="user_id">
                <option value="">All users</option>
                @foreach($users as $user)
                    <option value="{{ $user->id }}" @selected((string)request('user_id') === (string)$user->id)>{{ $user->name }} — {{ $user->role }}</option>
                @endforeach
            </select>
        </label>
        <label>Kind
            <select name="kind">
                <option value="">All</option>
                <option value="upload" @selected(request('kind') === 'upload')>Uploaded</option>
                <option value="generated" @selected(request('kind') === 'generated')>Generated</option>
            </select>
        </label>
        <div style="display:flex;gap:8px;align-items:end;">
            <button class="btn" type="submit">🔎 Filter</button>
            <a class="btn btn-ghost" href="{{ route('admin.file-center') }}">Reset</a>
        </div>
    </form>
</div>

<div class="card" style="margin-bottom:14px;">
    <h3 style="margin-top:0;">📄 System Samples</h3>
    <div style="display:flex;gap:8px;flex-wrap:wrap;">
        <a class="btn-small btn-info" href="{{ route('admin.file-center.sample', 'leads_sample.csv') }}">Download Lead Sample</a>
        <a class="btn-small btn-info" href="{{ route('admin.file-center.sample', 'contacts_sample.csv') }}">Download Contact Sample</a>
        <a class="btn-small btn-info" href="{{ route('admin.file-center.sample', 'contacts_advanced.csv') }}">Download Advanced Contact Sample</a>
    </div>
</div>

<div class="card" style="padding:0;overflow:hidden;">
    <div style="padding:13px 15px;border-bottom:1px solid var(--c-border,#ddd);display:flex;justify-content:space-between;gap:12px;align-items:center;">
        <strong>{{ number_format($files->total()) }} managed files</strong>
        <a href="{{ route('admin.audit-logs', ['category' => 'download']) }}" class="btn-small">🛡️ View Download Audit</a>
    </div>
    <div class="file-table-wrap">
        <table class="file-table">
            <thead><tr><th>File</th><th>Kind / Source</th><th>Uploaded / Created</th><th>Who</th><th>Size</th><th>Downloads</th><th>Action</th></tr></thead>
            <tbody>
            @forelse($files as $file)
                <tr>
                    <td><strong>{{ $file->original_name }}</strong><br><span class="muted mono">{{ $file->sha256 }}</span></td>
                    <td>{{ ucfirst($file->file_kind) }}<br><span class="muted">{{ $file->source_label ?: '—' }}</span></td>
                    <td>{{ optional($file->created_at)->format('d M Y') }}<br><span class="muted">{{ optional($file->created_at)->format('h:i:s A') }}</span></td>
                    <td>{{ $file->uploader?->name ?? 'System' }}<br><span class="muted">{{ $file->uploader?->email ?? '—' }}</span></td>
                    <td>{{ number_format($file->size_bytes) }} bytes<br><span class="muted">{{ $file->mime_type ?: '—' }}</span></td>
                    <td><strong>{{ (int)($downloadCounts[$file->id] ?? 0) }}</strong></td>
                    <td><a class="btn-small btn-info" href="{{ route('admin.file-center.download', $file->id) }}">⬇ Download</a></td>
                </tr>
            @empty
                <tr><td colspan="7" style="padding:28px;text-align:center;" class="muted">No managed files found.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    @if($files->hasPages())
        <div style="padding:13px 15px;border-top:1px solid var(--c-border,#ddd);">{{ $files->links() }}</div>
    @endif
</div>

<style>
.file-filter-grid{display:grid;grid-template-columns:2fr 1fr 1fr auto;gap:10px;align-items:end}
.file-filter-grid label{font-size:12px;font-weight:600;display:grid;gap:5px}.file-filter-grid input,.file-filter-grid select{width:100%;min-height:40px;padding:8px 10px;border:1px solid var(--c-border,#ddd);border-radius:8px;background:var(--c-surface,#fff);color:inherit}
.file-table-wrap{overflow:auto}.file-table{width:100%;border-collapse:collapse;min-width:1050px;font-size:12px}.file-table th,.file-table td{padding:10px 12px;border-bottom:1px solid var(--c-border,#e5e5e5);vertical-align:top;text-align:left}.file-table th{font-size:11px;text-transform:uppercase;letter-spacing:.04em;white-space:nowrap;background:var(--c-surface-2,#f7f7f7)}.mono{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:10px;word-break:break-all}
@media(max-width:800px){.file-filter-grid{grid-template-columns:1fr 1fr}}@media(max-width:520px){.file-filter-grid{grid-template-columns:1fr}}
</style>
@endsection
