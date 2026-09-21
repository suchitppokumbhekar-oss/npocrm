@php
    $contact = \App\Models\Contact::findOrFail(request('contact'));
    $outcomes = \App\Models\Config\CallOutcome::active()->orderBy('sort_order')->get();
@endphp

<form method="POST" action="{{ url('/calls/log') }}" data-ajax>
    @csrf
    <input type="hidden" name="contact_id" value="{{ $contact->id }}">

    <p class="muted" style="margin-bottom:12px;">
        Logging call for <strong>{{ $contact->name }}</strong> · {{ $contact->phone }}
    </p>

    <div class="field">
        <label>Outcome *</label>
        <select name="outcome_key" class="input" required>
            <option value="">— Pick outcome —</option>
            @foreach ($outcomes as $o)
                <option value="{{ $o->key }}">{{ $o->label }}</option>
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