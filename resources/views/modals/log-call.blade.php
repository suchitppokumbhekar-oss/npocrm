@php
    $contact = \App\Models\Contact::findOrFail(request('contact'));
    $activeWork = $contact->followups()
        ->where('status', 'pending')
        ->orderBy('scheduled_for')
        ->first();
    $actionKey = $activeWork?->action_type ?: 'call';
    $canonicalOutcomes = app(\App\Services\SettingsService::class)
        ->callOutcomesForContactWork($contact, $actionKey);
    $outcomes = app(\App\Services\WorkflowPresentationService::class)
        ->presentOutcomes($canonicalOutcomes, (int) session('user_id'));
@endphp

<form method="POST" action="{{ url('/calls/log') }}" data-ajax>
    @csrf
    <input type="hidden" name="contact_id" value="{{ $contact->id }}">

    <p class="muted" style="margin-bottom:12px;">
        Logging call for <strong>{{ $contact->name }}</strong> · {{ phone_display($contact->phone) }}
    </p>

    <div class="field">
        <label>Outcome *</label>
        <select name="outcome_key" class="input" required>
            <option value="">— Pick outcome —</option>
            @foreach ($outcomes as $o)
                <option value="{{ $o['key'] }}">{{ $o['display_label'] }}</option>
            @endforeach
        </select>
    </div>

    <div class="field">
        <label>Duration (seconds)</label>
        <input type="number" name="duration_seconds" class="input" min="0" max="86400" value="0" required>
    </div>

    <div class="field">
        <label>Notes</label>
        <textarea name="notes" class="input" rows="3" maxlength="2000"></textarea>
    </div>

    <button type="submit" class="btn btn-block">💾 Log Call</button>
</form>