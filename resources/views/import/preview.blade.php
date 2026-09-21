@extends('layouts.app')

@section('title', 'Preview Import — NPO CRM')

@section('content')

    <a href="{{ url('/import') }}" class="back-link">← Back to Import</a>

    <div class="card">
        <h2>📥 Preview Import</h2>
        <p class="muted" style="font-size:13px;">
            <strong>{{ $totalRows }}</strong> rows detected in <em>{{ $filename }}</em>.
            Here's a preview of the first 10 rows. Confirm to import.
        </p>

        <div class="table-wrap" style="margin-top:var(--s-3);">
            <table>
                <tr>
                    @foreach ($headers as $h)
                        <th>{{ $h }}</th>
                    @endforeach
                </tr>
                @foreach ($preview as $row)
                    <tr>
                        @foreach ($headers as $i => $h)
                            <td>{{ $row[$i] ?? '' }}</td>
                        @endforeach
                    </tr>
                @endforeach
            </table>
        </div>
    </div>

    <div class="card">
        <h3>⚙️ Import Options</h3>

        <form method="POST" action="/import/execute">
            @csrf

            <div class="field">
                <label style="display:flex;align-items:center;gap:8px;cursor:pointer;">
                    <input type="checkbox" name="skip_duplicates" value="1" checked
                           style="width:auto;min-height:auto;">
                    <span>Skip leads with a phone number that already exists</span>
                </label>
                <p class="muted" style="font-size:11px;margin-top:4px;margin-left:26px;">
                    Recommended. Prevents duplicates from re-imports.
                </p>
            </div>

            <div style="background:var(--c-surface-2);padding:var(--s-3);border-radius:8px;font-size:13px;margin-bottom:var(--s-3);">
                <strong>Detected mapping:</strong>
                <ul style="margin:6px 0 0 18px;">
                    @foreach (['name','phone','email','source','budget','project','notes'] as $field)
                        @if (isset($mapping[$field]))
                            <li>
                                <strong>{{ ucfirst($field) }}</strong> ← column
                                <code>{{ $headers[$mapping[$field]] }}</code>
                            </li>
                        @endif
                    @endforeach
                </ul>
                <p class="muted" style="font-size:11px;margin-top:8px;">
                    Every imported lead will be auto-assigned to the least-loaded active agent.
                    Duplicate phone numbers (if skip is enabled) will be skipped, not merged.
                </p>
            </div>

            <button type="submit" class="btn">📥 Import Now</button>
        </form>
    </div>

@endsection