@extends('layouts.app')

@section('title', 'Preview Import — Contacts')

@section('content')

<a href="{{ url('/contacts/import') }}" class="back-link">← Back to import</a>

<div class="card">
    <h2 style="margin:0 0 6px;">🔍 Review &amp; Map Import</h2>
    <p class="muted" style="margin:0;font-size:13px;">
        File: <strong>{{ $filename }}</strong>
        @if(!empty($sourceSheet)) · Worksheet: <strong>{{ $sourceSheet }}</strong> @endif
        · {{ $totalRows }} row{{ $totalRows === 1 ? '' : 's' }} detected.
    </p>

    <div class="alert alert-info" style="margin-top:var(--s-3);">
        <strong>Nothing has been imported yet.</strong>
        Choose which source column supplies each CRM field. Name and Phone are required; Email and Source are optional.
        The Pitch Project applies to the whole list and is not taken from the source file.
    </div>

    @if ($errors->any())
        <div class="alert alert-danger" style="margin-top:var(--s-2);">
            @foreach ($errors->all() as $error)
                <div>{{ $error }}</div>
            @endforeach
        </div>
    @endif

    <form method="POST" action="{{ url('/contacts/import/execute') }}">
        @csrf

        <h3 style="margin-top:var(--s-3);">Column Mapping</h3>
        <p class="muted" style="font-size:12px;">
            The CRM has suggested mappings from the column names and sample values. Review them before importing.
            Select <strong>— none —</strong> when a source field should not be imported.
        </p>

        <div class="table-wrap" style="margin-top:var(--s-2);">
            <table>
                <tr>
                    <th>CRM field</th>
                    <th>Source column</th>
                    <th>Required</th>
                </tr>
                @foreach (['name' => 'Name', 'phone' => 'Phone', 'email' => 'Email', 'source' => 'Source'] as $field => $label)
                    <tr>
                        <td><strong>{{ $label }}</strong></td>
                        <td>
                            <select name="mapping[{{ $field }}]" class="input" data-required="{{ in_array($field, ['name','phone'], true) ? '1' : '0' }}">
                                <option value="">— none —</option>
                                @foreach ($headers as $i => $h)
                                    <option value="{{ $i }}" @selected(($mapping[$field] ?? null) === $i)>
                                        {{ $i + 1 }}: {{ $h !== '' ? $h : '(blank header)' }}
                                    </option>
                                @endforeach
                            </select>
                        </td>
                        <td>
                            @if (in_array($field, ['name','phone'], true))
                                <span class="badge red">Required</span>
                            @else
                                <span class="muted">Optional</span>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </table>
        </div>

        <div id="mapping-warning" class="alert alert-danger" style="display:none;margin-top:var(--s-2);"></div>

        <div class="alert alert-info" style="margin-top:var(--s-3);">
            🎯 <strong>Pitch Project:</strong> {{ $pitchProject?->name ?? '—' }}
            <span class="muted"> — this is the project being pitched. When a Lead is created, this is the default Lead Project; the caller can choose another authorized project if the customer wants something different.</span>
            @if($assignedAgent)
                <br><strong>Caller:</strong> {{ $assignedAgent->user?->name ?? 'Agent #'.$assignedAgent->id }}
            @elseif(session('user_role') === 'agent')
                <br><strong>Caller:</strong> Me
            @else
                <br><strong>Caller:</strong> Unassigned
            @endif
        </div>

        <h3 style="margin-top:var(--s-3);">Data Preview</h3>
        <div class="table-wrap">
            <table>
                <tr>
                    @foreach ($headers as $h)
                        <th>{{ $h !== '' ? $h : '(blank)' }}</th>
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

        <div style="margin-top:var(--s-3);display:flex;gap:var(--s-2);flex-wrap:wrap;">
            <button id="contact-import-execute" type="submit" class="btn-small btn-info">
                📥 Import {{ $totalRows }} Contacts
            </button>
            <a href="{{ url('/contacts/import') }}" class="btn-small"
               style="background:transparent;color:var(--c-text);border:1.5px solid var(--c-border);">Cancel</a>
        </div>
    </form>
</div>

<script>
(function () {
    const form = document.querySelector('form[action="{{ url('/contacts/import/execute') }}"]');
    const warning = document.getElementById('mapping-warning');
    const button = document.getElementById('contact-import-execute');
    const required = Array.from(document.querySelectorAll('select[data-required="1"]'));

    function validateMapping() {
        const missing = required.filter(select => !select.value).map(select => {
            const field = select.name.match(/mapping\[([^\]]+)/);
            return field ? field[1] : 'required field';
        });

        if (missing.length) {
            warning.style.display = 'block';
            warning.textContent = 'Please map the required field(s) before importing: ' + missing.join(', ') + '.';
            button.disabled = true;
            return false;
        }

        warning.style.display = 'none';
        warning.textContent = '';
        button.disabled = false;
        return true;
    }

    required.forEach(select => select.addEventListener('change', validateMapping));
    form.addEventListener('submit', function (event) {
        if (!validateMapping()) event.preventDefault();
    });
    validateMapping();
})();
</script>

@endsection
