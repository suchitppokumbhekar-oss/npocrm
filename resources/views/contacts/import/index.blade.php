@extends('layouts.app')

@section('title', 'Import Contacts — NPO CRM')

@section('content')

<a href="{{ url('/contacts') }}" class="back-link">← Back to contacts</a>

<div class="card">
    <h2 style="margin:0 0 6px;">📥 Import Contacts</h2>
    <p class="muted" style="margin:0;font-size:13px;line-height:1.55;">
        Upload the file exactly as you received it. The CRM accepts <strong>CSV, XLS and XLSX</strong>, analyzes the columns,
        and lets you confirm the mapping before anything is imported. You do <strong>not</strong> need to convert or rearrange your file.
    </p>

    <form id="contact-import-form" method="POST" action="{{ url('/contacts/import/preview') }}" enctype="multipart/form-data" style="margin-top:var(--s-3);">
        @csrf
        <input type="hidden" name="original_filename" id="original_filename" value="">
        <input type="hidden" name="source_sheet" id="source_sheet" value="">

        <div class="field">
            <label>Source File *</label>
            <input id="contact-import-file" type="file" name="csv_file" class="input" accept=".csv,.txt,.xls,.xlsx" required>
            <p class="muted" style="font-size:11px;margin-top:4px;">
                Max 20MB. For Excel files, the selected worksheet is converted internally for the preview; your original file is not modified.
                · <a href="{{ url('/contacts/import/sample.csv') }}" style="font-weight:600;" download>📥 Download sample CSV</a>
            </p>
        </div>

        <div id="excel-sheet-panel" class="alert alert-info" style="display:none;margin-top:var(--s-3);">
            <strong>Excel workbook detected.</strong>
            <div style="margin-top:8px;">
                <label for="excel-sheet-select">Choose the worksheet to import</label>
                <select id="excel-sheet-select" class="input" style="margin-top:4px;"></select>
            </div>
            <p id="excel-sheet-note" class="muted" style="font-size:11px;margin:6px 0 0;"></p>
        </div>

        <div id="excel-status" class="alert" style="display:none;margin-top:var(--s-3);"></div>

        <div class="grid-stats" style="margin-top:var(--s-3);">
            <div class="field">
                <label>Pitch Project *</label>
                <select name="pitch_project_id" class="input" required>
                    <option value="">Select project</option>
                    @foreach ($projects as $project)
                        <option value="{{ $project->id }}">{{ $project->name }}</option>
                    @endforeach
                </select>
                <p class="muted" style="font-size:11px;margin-top:4px;">This is the project the caller will pitch. It becomes the default Lead Project during conversion.</p>
            </div>
            <div class="field">
                <label>Assign caller</label>
                <select name="assigned_agent_id" class="input">
                    <option value="">@if(session('user_role') === 'agent') Me @else Unassigned @endif</option>
                    @foreach ($agents as $agent)
                        <option value="{{ $agent->id }}">{{ $agent->user?->name ?? 'Agent #'.$agent->id }}</option>
                    @endforeach
                </select>
                <p class="muted" style="font-size:11px;margin-top:4px;">Only callers within your existing authorization scope are shown.</p>
            </div>
        </div>

        <div id="import-help" class="alert alert-info" style="margin-top:var(--s-3);">
            <strong>What happens next?</strong>
            <span class="muted">The CRM will show your detected columns and a sample of the data. You can change the mapping before importing.</span>
        </div>

        <button id="contact-import-submit" type="submit" class="btn-small btn-info" style="margin-top:var(--s-2);">
            🔍 Upload &amp; Analyze
        </button>
    </form>
</div>

<div class="card">
    <h3>📜 Recent Imports</h3>
    @if ($recent->isEmpty())
        <p class="muted">No imports yet.</p>
    @else
        <div class="table-wrap">
            <table>
                <tr>
                    <th>File</th>
                    <th>By</th>
                    <th>Rows</th>
                    <th>Imported</th>
                    <th>Skipped</th>
                    <th>Failed</th>
                    <th>When</th>
                    <th></th>
                </tr>
                @foreach ($recent as $b)
                    <tr>
                        <td><strong>{{ $b->filename }}</strong></td>
                        <td>{{ $b->user?->name ?? '—' }}</td>
                        <td>{{ $b->total_rows }}</td>
                        <td><span class="badge green">{{ $b->imported }}</span></td>
                        <td>{{ $b->skipped }}</td>
                        <td>
                            @if ($b->failed > 0)
                                <span class="badge red">{{ $b->failed }}</span>
                            @else
                                <span class="muted">0</span>
                            @endif
                        </td>
                        <td><span class="muted" style="font-size:12px;">{{ $b->created_at?->format('d M Y, H:i') }}</span></td>
                        <td><a href="{{ url('/contacts/import/summary/'.$b->id) }}" class="btn-small btn-info">View</a></td>
                    </tr>
                @endforeach
            </table>
        </div>
    @endif
</div>

{{-- SheetJS is pinned to the current official 0.20.3 standalone build. --}}
<script src="https://cdn.sheetjs.com/xlsx-0.20.3/package/dist/xlsx.full.min.js"></script>
<script>
(function () {
    const form = document.getElementById('contact-import-form');
    const input = document.getElementById('contact-import-file');
    const sheetPanel = document.getElementById('excel-sheet-panel');
    const sheetSelect = document.getElementById('excel-sheet-select');
    const sheetNote = document.getElementById('excel-sheet-note');
    const status = document.getElementById('excel-status');
    const originalFilename = document.getElementById('original_filename');
    const sourceSheet = document.getElementById('source_sheet');
    const submit = document.getElementById('contact-import-submit');

    let workbook = null;
    let excelFile = null;
    let waitingForSheet = false;

    function showStatus(message, type) {
        status.style.display = 'block';
        status.className = 'alert ' + (type === 'error' ? 'alert-danger' : 'alert-info');
        status.textContent = message;
    }

    function clearStatus() {
        status.style.display = 'none';
        status.textContent = '';
    }

    function isExcel(file) {
        const name = (file?.name || '').toLowerCase();
        return name.endsWith('.xls') || name.endsWith('.xlsx');
    }

    function prepareExcelAndSubmit() {
        if (!workbook || !excelFile) return;

        const sheetName = sheetSelect.value || workbook.SheetNames[0];
        const worksheet = workbook.Sheets[sheetName];
        if (!worksheet) {
            showStatus('The selected worksheet could not be read. Please choose another worksheet.', 'error');
            return;
        }

        try {
            // Convert only the selected worksheet to a normalized CSV in memory.
            // The original Excel file remains untouched on the user's device.
            const csv = XLSX.utils.sheet_to_csv(worksheet, {
                FS: ',',
                RS: '\n',
                blankrows: false,
                raw: false,
            });

            if (!csv.trim()) {
                showStatus('The selected worksheet has no readable data.', 'error');
                return;
            }

            const normalized = new File(
                [csv],
                (excelFile.name || 'contacts') + '.normalized.csv',
                { type: 'text/csv' }
            );
            const transfer = new DataTransfer();
            transfer.items.add(normalized);
            input.files = transfer.files;

            originalFilename.value = excelFile.name || 'Excel import';
            sourceSheet.value = sheetName;
            waitingForSheet = false;
            submit.disabled = true;
            submit.textContent = '⏳ Preparing worksheet…';
            clearStatus();

            // Submit the normalized CSV through the existing secure server-side
            // mapping/preview pipeline.
            HTMLFormElement.prototype.submit.call(form);
        } catch (error) {
            showStatus('Could not read this Excel worksheet. Please check that the workbook is not password-protected or corrupted.', 'error');
            submit.disabled = false;
            submit.textContent = '🔍 Upload & Analyze';
        }
    }

    input.addEventListener('change', async function () {
        clearStatus();
        sheetPanel.style.display = 'none';
        workbook = null;
        excelFile = null;
        waitingForSheet = false;
        originalFilename.value = '';
        sourceSheet.value = '';

        const file = input.files && input.files[0];
        if (!file) return;

        originalFilename.value = file.name;

        if (!isExcel(file)) return;

        if (typeof XLSX === 'undefined') {
            showStatus('Excel support could not be loaded in this browser. Please refresh the page and try again.', 'error');
            return;
        }

        if (file.size > 20 * 1024 * 1024) {
            showStatus('This file is larger than the 20MB import limit.', 'error');
            return;
        }

        try {
            showStatus('Reading Excel workbook…', 'info');
            const buffer = await file.arrayBuffer();
            workbook = XLSX.read(buffer, {
                type: 'array',
                cellText: true,
                cellDates: false,
                cellNF: false,
                bookVBA: false,
            });
            excelFile = file;

            const names = workbook.SheetNames || [];
            if (!names.length) {
                showStatus('No worksheets were found in this workbook.', 'error');
                return;
            }

            sheetSelect.innerHTML = '';
            names.forEach(function (name) {
                const option = document.createElement('option');
                option.value = name;
                option.textContent = name;
                sheetSelect.appendChild(option);
            });

            sheetPanel.style.display = 'block';
            waitingForSheet = names.length > 1;
            sheetNote.textContent = names.length === 1
                ? 'One worksheet found. Click Analyze to continue.'
                : names.length + ' worksheets found. Select the one containing the contact data.';
            clearStatus();

            if (names.length === 1) {
                submit.textContent = '🔍 Analyze selected worksheet';
            } else {
                submit.textContent = '➡️ Continue with selected worksheet';
            }
        } catch (error) {
            workbook = null;
            excelFile = null;
            showStatus('This Excel file could not be read. It may be corrupted, password-protected, or an unsupported workbook variant.', 'error');
        }
    });

    form.addEventListener('submit', function (event) {
        const file = input.files && input.files[0];
        if (!file || !isExcel(file)) {
            originalFilename.value = file ? file.name : '';
            return;
        }

        // The native required-field validation must pass before we replace the
        // Excel file with its normalized CSV and submit programmatically.
        if (!form.checkValidity()) {
            return;
        }

        event.preventDefault();
        if (!workbook) {
            showStatus('Please wait for the Excel workbook to finish loading, then try again.', 'error');
            return;
        }

        if (waitingForSheet || (workbook.SheetNames || []).length > 1) {
            prepareExcelAndSubmit();
        } else {
            prepareExcelAndSubmit();
        }
    });
})();
</script>

@endsection
