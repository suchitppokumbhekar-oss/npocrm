@extends('layouts.app')

@section('title', 'Import Summary — NPO CRM')

@section('content')

    <a href="{{ url('/import') }}" class="back-link">← Back to Import</a>

    <div class="card">
        <h2>✅ Import Complete</h2>
        <p class="muted" style="font-size:13px;">
            File: <strong>{{ $batch->filename }}</strong>
            · By: {{ $batch->user?->name ?? '—' }}
            · {{ $batch->created_at?->format('d M Y, H:i') }}
        </p>

        <div class="grid-stats" style="margin-top:var(--s-3);">
            <x-stat-card label="📋 Total Rows" :value="$batch->total_rows" />
            <x-stat-card label="✅ Imported"   :value="$batch->imported_rows" variant="green" />
            <x-stat-card label="⏭️ Skipped"    :value="$batch->skipped_rows" variant="orange" />
            <x-stat-card label="❌ Failed"     :value="$batch->failed_rows" variant="red" />
        </div>

        <div style="margin-top:var(--s-3);display:flex;gap:var(--s-2);flex-wrap:wrap;">
            <a href="{{ url('/') }}" class="btn">🏠 Back to Dashboard</a>
            <a href="{{ url('/import') }}" class="btn btn-ghost"
               style="background:transparent;color:var(--c-text);border:1.5px solid var(--c-border);">
                📥 Import Another
            </a>
            @if ($batch->error_log)
                <a href="{{ url('/import/' . $batch->id . '/errors.csv') }}" class="btn btn-info">
                    ⬇ Download Error Log
                </a>
            @endif
        </div>
    </div>

    @if ($batch->failed_rows > 0 && $batch->error_log)
        <div class="card">
            <h3>❌ Failed Rows (first 20)</h3>
            @php $errors = array_slice(json_decode($batch->error_log, true) ?? [], 0, 20); @endphp

            <div class="table-wrap">
                <table>
                    <tr>
                        <th>Row #</th>
                        <th>Reason</th>
                        <th>Raw Data</th>
                    </tr>
                    @foreach ($errors as $e)
                        <tr>
                            <td>{{ $e['row'] ?? '—' }}</td>
                            <td>{{ $e['reason'] ?? '—' }}</td>
                            <td><span class="muted" style="font-size:11px;">
                                {{ is_array($e['data'] ?? null) ? implode(' | ', $e['data']) : '' }}
                            </span></td>
                        </tr>
                    @endforeach
                </table>
            </div>

            @if ($batch->failed_rows > 20)
                <p class="muted" style="margin-top:var(--s-2);font-size:12px;">
                    … +{{ $batch->failed_rows - 20 }} more. Download the error log for the full list.
                </p>
            @endif
        </div>
    @endif

@endsection