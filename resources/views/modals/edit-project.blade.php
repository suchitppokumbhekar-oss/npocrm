<form method="POST" action="/projects/{{ $project->id }}/update" data-ajax>
    @csrf

    <p class="muted" style="margin-bottom:var(--s-3);font-size:13px;">
        Editing project <strong>{{ $project->name }}</strong>
    </p>

    <div class="field">
        <label>Project Name *</label>
        <input type="text" name="name" class="input" required maxlength="255"
               value="{{ $project->name }}">
    </div>

    <div class="field">
        <label>Location *</label>
        <input type="text" name="location" class="input" required maxlength="255"
               value="{{ $project->location }}"
               placeholder="e.g. Navi Mumbai">
    </div>

    <div class="field">
        <label>RERA Number</label>
        <input type="text" name="rera_number" class="input" maxlength="255"
               value="{{ $project->rera_number }}">
    </div>

    <div class="field">
        <label>Status *</label>
        <select name="status" class="input" required>
            <option value="active"   @selected($project->status === 'active')>✅ Active</option>
            <option value="inactive" @selected($project->status === 'inactive')>⏸ Inactive</option>
        </select>
        <p class="muted" style="font-size:11px;margin-top:4px;">
            Inactive projects don't appear in Add Lead / Add Project dropdowns.
        </p>
    </div>

    <button type="submit" class="btn btn-block">💾 Save Changes</button>
</form>