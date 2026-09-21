<form method="POST" action="/assign-project" data-ajax>
    @csrf
    <input type="hidden" name="lead_id" value="{{ $lead->id }}">

    <div style="padding:10px 12px;border:1px solid #fed7aa;background:#fff7ed;border-radius:10px;margin-bottom:14px;font-size:12px;line-height:1.45;">
        <strong>🔧 Super Admin correction only</strong><br>
        This changes the existing project assignment. It does <strong>not</strong> create a new project workstream and it does not erase the existing lead history.
    </div>

    <p class="muted" style="margin-bottom:12px;">
        Correct project for <strong>{{ $lead->customer_name }}</strong><br>
        Current project: <strong>{{ $lead->project?->name ?? 'Not assigned' }}</strong>
        @if ($lead->agent?->user?->name)
            · Owner: <strong>{{ $lead->agent->user->name }}</strong>
        @endif
    </p>

    <div class="field">
        <label>Correct project</label>
        <select name="project_id" class="input" required>
            <option value="">Select project…</option>
            @foreach ($projects as $proj)
                <option value="{{ $proj->id }}" @selected($lead->project_id == $proj->id)>
                    {{ $proj->name }}
                </option>
            @endforeach
        </select>
    </div>

    <div class="field" style="margin-top:12px;">
        <label>Why is this being corrected? *</label>
        <textarea name="reason" class="input" rows="4" maxlength="1000" required placeholder="Example: Pyramid Centria was assigned by mistake; the enquiry is actually for XYZ project."></textarea>
        <div class="muted" style="font-size:11px;margin-top:4px;">The reason is recorded in the lead timeline and Super Admin audit trail.</div>
    </div>

    <button type="submit" class="btn btn-block" onclick="return confirm('Correct the existing project assignment? The change will be recorded in the lead history and audit trail.');">
        🔧 Correct Project Assignment
    </button>
</form>
