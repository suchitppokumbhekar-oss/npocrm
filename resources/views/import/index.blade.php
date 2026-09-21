@extends('layouts.app')

@section('title', 'Import Leads — NPO CRM')

@section('content')

    <a href="{{ url('/') }}" class="back-link">← Back to dashboard</a>

    <div class="card">
        <h2>📥 Import Leads</h2>
        <p class="muted" style="font-size:11px;margin-top:4px;">
            Max 10MB. Headers on first row.
            · <a href="{{ asset('samples/leads_sample.csv') }}" style="font-weight:600;" download>
                📥 Download sample CSV
            </a>
        </p>

        @if ($errors->any())
            <div class="alert alert-error" style="margin:var(--s-3) 0;">
                {{ $errors->first() }}
            </div>
        @endif

        <form method="POST" action="/import/preview" enctype="multipart/form-data" style="margin-top:var(--s-3);">
            @csrf

            <div class="field">
                <label>CSV File *</label>
                <input type="file" name="csv_file" accept=".csv,text/csv" required>
                <p class="muted" style="font-size:11px;margin-top:4px;">
                    Max 10 MB. First row should contain column headers.
                </p>
            </div>

            <button type="submit" class="btn">📥 Upload & Preview</button>
        </form>

        <hr style="border:0;border-top:1px dashed var(--c-border);margin:var(--s-4) 0;">

        <h3 style="font-size:14px;">📋 Expected Column Headers</h3>
        <p class="muted" style="font-size:12px;margin-bottom:var(--s-2);">
            Column names are flexible — we auto-detect common variations.
        </p>

        <div class="table-wrap">
            <table>
                <tr>
                    <th>Field</th>
                    <th>Required?</th>
                    <th>Accepted headers</th>
                </tr>
                <tr>
                    <td><strong>Name</strong></td>
                    <td><span class="badge red">Yes</span></td>
                    <td><code>Name</code>, <code>Customer</code>, <code>Customer Name</code>, <code>Full Name</code>, <code>Client</code></td>
                </tr>
                <tr>
                    <td><strong>Phone</strong></td>
                    <td><span class="badge red">Yes</span></td>
                    <td><code>Phone</code>, <code>Mobile</code>, <code>Contact</code>, <code>Mobile Number</code></td>
                </tr>
                <tr>
                    <td>Email</td>
                    <td><span class="badge blue">No</span></td>
                    <td><code>Email</code>, <code>E-mail</code></td>
                </tr>
                <tr>
                    <td>Source</td>
                    <td><span class="badge blue">No</span></td>
                    <td><code>Source</code>, <code>Lead Source</code>, <code>Channel</code></td>
                </tr>
                <tr>
                    <td>Budget (₹)</td>
                    <td><span class="badge blue">No</span></td>
                    <td><code>Budget</code>, <code>Price</code></td>
                </tr>
                <tr>
                    <td>Project</td>
                    <td><span class="badge blue">No</span></td>
                    <td><code>Project</code>, <code>Project Name</code></td>
                </tr>
                <tr>
                    <td>Notes</td>
                    <td><span class="badge blue">No</span></td>
                    <td><code>Notes</code>, <code>Remarks</code>, <code>Comment</code></td>
                </tr>
            </table>
        </div>
    </div>

    @if ($recentBatches->isNotEmpty())
        <div class="card">
            <h3>📜 Recent Imports</h3>

            <div class="table-wrap">
                <table>
                    <tr>
                        <th>Date</th>
                        <th>File</th>
                        <th>By</th>
                        <th>Total</th>
                        <th>Imported</th>
                        <th>Skipped</th>
                        <th>Failed</th>
                        <th>Actions</th>
                    </tr>
                    @foreach ($recentBatches as $b)
                        <tr>
                            <td>{{ $b->created_at?->format('d M Y, H:i') }}</td>
                            <td><span class="muted" style="font-size:12px;">{{ $b->filename }}</span></td>
                            <td>{{ $b->user?->name ?? '—' }}</td>
                            <td>{{ $b->total_rows }}</td>
                            <td><span class="badge green">{{ $b->imported_rows }}</span></td>
                            <td><span class="badge orange">{{ $b->skipped_rows }}</span></td>
                            <td>
                                @if ($b->failed_rows > 0)
                                    <span class="badge red">{{ $b->failed_rows }}</span>
                                @else
                                    <span class="muted">0</span>
                                @endif
                            </td>
                            <td>
                                <a href="{{ url('/import/summary/' . $b->id) }}" class="btn-small btn-info">View</a>
                                @if ($b->error_log)
                                    <a href="{{ url('/import/' . $b->id . '/errors.csv') }}" class="btn-small btn-ghost"
                                       style="background:transparent;color:var(--c-text);border:1.5px solid var(--c-border);">
                                        ⬇ Errors
                                    </a>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </table>
            </div>
        </div>
    @endif

@endsection