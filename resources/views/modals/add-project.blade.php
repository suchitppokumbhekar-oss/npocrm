<form method="POST" action="/projects" data-ajax>
    @csrf

    <div class="field">
        <label>Project Name *</label>
        <input type="text" name="name" class="input" required maxlength="255">
    </div>

    <div class="field">
        <label>Location *</label>
        <input type="text" name="location" class="input" required maxlength="255"
               placeholder="e.g. Navi Mumbai">
    </div>

    <div class="field">
        <label>RERA Number</label>
        <input type="text" name="rera_number" class="input" maxlength="255">
    </div>

    <button type="submit" class="btn btn-block">➕ Add Project</button>
</form>