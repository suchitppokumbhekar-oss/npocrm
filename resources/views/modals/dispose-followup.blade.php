@php
    $followup = $followup ?? null;
    $lead = $lead ?? $followup?->lead;
@endphp

@if (!$followup || !$lead)
    <div class="alert alert-error">This future follow-up is no longer available.</div>
@else
<form method="POST" action="{{ route('followups.dispose') }}" data-ajax>
    @csrf
    <input type="hidden" name="followup_id" value="{{ $followup->id }}">
    <div class="task-context" style="margin-bottom:14px;">
        <div class="task-context-row"><span class="label">👤 Lead</span><span class="value"><strong>{{ $lead->customer_name }}</strong></span></div>
        <div class="task-context-row"><span class="label">📌 Task</span><span class="value">{{ app(\App\Services\SettingsService::class)->actionTypeByKey($followup->action_type)?->label ?? $followup->action_type }}</span></div>
        <div class="task-context-row"><span class="label">🕒 Scheduled</span><span class="value"><x-relative-date :date="$followup->scheduled_for" :with-year="true" /></span></div>
    </div>
    <div class="field">
        <label>Why are you disposing this future task? *</label>
        <textarea name="reason" class="input" rows="4" required maxlength="1000" placeholder="Example: Customer requested no further follow-up for this date; replaced by a new scheduled action."></textarea>
    </div>
    <div style="background:#fff8e8;border:1px solid #f2dfac;border-radius:8px;padding:10px 12px;font-size:12px;color:#6b5516;margin-bottom:14px;">
        This cancels only this future follow-up. It will remain in the timeline with the reason.
    </div>
    <button type="submit" class="btn btn-block btn-danger">Cancel Future Task</button>
</form>
@endif
