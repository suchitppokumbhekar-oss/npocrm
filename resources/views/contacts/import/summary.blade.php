@extends('layouts.app')

@section('title', 'Import Summary — Contacts')

@section('content')

<a href="{{ url('/contacts/import') }}" class="back-link">← Back to import</a>

<div class="card">
@php
    $importMeta = json_decode($batch->error_log, true) ?? [];
    $skippedDnd = array_is_list($importMeta) ? 0 : (int) ($importMeta['skipped_dnd'] ?? 0);
    $skippedDuplicates = max(0, (int) $batch->skipped - $skippedDnd);
@endphp


    <h2 style="margin:0 0 6px;">✅ Import Complete</h2>
    <p class="muted" style="margin:0;font-size:13px;">
        File: <strong>{{ $batch->filename }}</strong> · {{ $batch->created_at?->format('d M Y, H:i') }}
    </p>

    <div class="grid-stats" style="margin-top:var(--s-3);">
        <x-stat-card label="📋 Total Rows" :value="$batch->total_rows" />
        <x-stat-card label="✅ Imported" :value="$batch->imported" variant="green" />
        <x-stat-card label="⏭️ Skipped duplicates" :value="$skippedDuplicates" variant="orange" />
        <x-stat-card label="🚫 Skipped DND contacts" :value="$skippedDnd" variant="red" />
        <x-stat-card label="❌ Failed" :value="$batch->failed" variant="{{ $batch->failed > 0 ? 'red' : 'green' }}" />
    </div>

    <div style="margin-top:var(--s-3);display:flex;gap:var(--s-2);flex-wrap:wrap;">
        <a href="{{ url('/contacts') }}" class="btn-small btn-info">View Contacts</a>
        <a href="{{ url('/contacts/dialer') }}" class="btn-small"
           style="background:var(--c-primary);color:#fff;">📞 Start Calling</a>
        @if ($batch->failed > 0)
            <a href="{{ url('/contacts/import/'.$batch->id.'/errors.csv') }}" class="btn-small"
               style="background:transparent;color:var(--c-text);border:1.5px solid var(--c-border);">
                📥 Download Errors CSV
            </a>
        @endif
    </div>
</div>

@endsection