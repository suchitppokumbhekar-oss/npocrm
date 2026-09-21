@php
    $settings    = app(\App\Services\SettingsService::class);
    $actionTypes = $settings->actionTypes();

    $statusKey = $lead->statusKey();

    // Universal task types — relevant at any stage
    $universalKeys = [
        'followup_call', 'call', 'whatsapp_followup', 'retry_call',
        'send_details', 'send_brochure', 'verify_contact',
        'send_budget_options', 'send_location_opts',
        'check_shared_agent', 'check_site_team',
    ];

    // Stage-specific task types
    $stageSpecific = [
        'new'             => [],
        'contacted'       => [],
        'visit_scheduled' => ['confirm_site_visit', 'visit_reminder'],
        'visit_done'      => ['visit_feedback_call', 'visit_outcome_call', 'post_visit_call'],
        'negotiation'     => ['negotiation_followup'],
        'booking'         => ['thank_you_call', 'booking_confirmation', 'brokerage_followup'],
        'lost'            => ['reactivation_call'],
    ];

    $recommendedKeys = array_merge($universalKeys, $stageSpecific[$statusKey] ?? []);

    $recommended = $actionTypes->whereIn('key', $recommendedKeys);
    $others      = $actionTypes->whereNotIn('key', $recommendedKeys);

    $statusLabel = $settings->statusLabel($statusKey);
@endphp

<form method="POST" action="/followups" data-ajax>
    @csrf
    <input type="hidden" name="lead_id" value="{{ $lead->id }}">
    @if (request()->filled('return_to'))
        <input type="hidden" name="return_to" value="{{ request('return_to') }}">
    @endif

    <p class="muted" style="margin-bottom:12px;">
        Schedule for <strong>{{ $lead->customer_name }}</strong>
        · <span class="badge {{ $settings->statusColor($statusKey) }}" style="font-size:11px;">{{ $statusLabel }}</span>
    </p>

    <div class="field">
        <label>When *</label>
        <input type="datetime-local" name="scheduled_for" class="input" required>
    </div>

    <div class="field">
        <label>Task Type *</label>
        <select name="action_type" class="input" required>
            @if ($recommended->isNotEmpty())
                <optgroup label="✅ Recommended for this stage">
                    @foreach ($recommended as $t)
                        <option value="{{ $t->key }}">{{ $t->label }}</option>
                    @endforeach
                </optgroup>
            @endif

            @if ($others->isNotEmpty())
                <optgroup label="⚠️ Other task types (may not match lead stage)">
                    @foreach ($others as $t)
                        <option value="{{ $t->key }}">{{ $t->label }}</option>
                    @endforeach
                </optgroup>
            @endif
        </select>

        <p class="muted" style="font-size:11px;margin-top:4px;">
            Lead is at <strong>{{ $statusLabel }}</strong>.
            Recommended tasks fit this stage. Anything else is available under "Other types"
            — use those only when you know why.
        </p>
    </div>

    <button type="submit" class="btn btn-block">📆 Schedule Follow-up</button>
</form>