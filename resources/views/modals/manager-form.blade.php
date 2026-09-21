@php
    $action = $manager
        ? url("/settings/managers/save/{$manager->id}")
        : url("/settings/managers/save");
@endphp

<form method="POST" action="{{ $action }}" data-ajax>
    @csrf

    <div class="field">
        <label>Full Name *</label>
        <input type="text" name="name" class="input" required maxlength="100"
               value="{{ old('name', $manager->name ?? '') }}">
    </div>

    <div class="field">
        <label>Email * <span class="muted" style="font-weight:400;">(used for login)</span></label>
        <input type="email" name="email" class="input" required maxlength="150"
               value="{{ old('email', $manager->email ?? '') }}">
    </div>

    <div class="field">
        <label>
            Password {{ $manager ? '' : '*' }}
            @if ($manager)
                <span class="muted" style="font-weight:400;">— leave blank to keep current</span>
            @endif
        </label>
        <input type="password" name="password" class="input" minlength="6"
               {{ $manager ? '' : 'required' }}
               placeholder="{{ $manager ? '••••••••' : 'Minimum 6 characters' }}">
    </div>

    <p class="muted" style="font-size:12px;">
        ℹ️ To assign this manager to a team, go to <strong>Settings → Teams</strong> and pick them in the Manager dropdown.
    </p>

    <button type="submit" class="btn btn-block">
        {{ $manager ? '💾 Update Manager' : '➕ Create Manager' }}
    </button>
</form>