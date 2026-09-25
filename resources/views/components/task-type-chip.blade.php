@props(['actionType'])

@php
    $key = (string) $actionType;

    // Map every known action type to [icon, short label, tone]
    $meta = match (true) {
        str_contains($key, 'whatsapp')      => ['💬', 'WhatsApp', 'wa'],

        $key === 'visit_reminder'           => ['🏠', 'Confirm Visit', 'visit'],
        $key === 'visit_feedback_call'      => ['🏠', 'Visit Outcome', 'visit'],
        $key === 'visit_outcome_call'       => ['🏠', 'Post-Visit Feedback', 'visit'],
        $key === 'confirm_site_visit'       => ['🏠', 'Confirm Visit', 'visit'],
        $key === 'post_visit_call'          => ['🏠', 'Post-Visit Call', 'visit'],

        $key === 'thank_you_call'           => ['✅', 'Thank You', 'book'],
        $key === 'booking_confirmation'     => ['✅', 'Booking Confirm', 'book'],

        str_contains($key, 'reactivation')  => ['🔄', 'Reactivate', 'react'],

        str_contains($key, 'brokerage')     => ['💰', 'Brokerage', 'money'],
        str_contains($key, 'negotiation')   => ['💰', 'Negotiation', 'money'],

        str_contains($key, 'send_budget')   => ['📤', 'Send Budget', 'send'],
        str_contains($key, 'send_location') => ['📤', 'Send Location', 'send'],
        str_contains($key, 'send_details')  => ['📤', 'Send Details', 'send'],
        str_contains($key, 'send_brochure') => ['📤', 'Send Brochure', 'send'],

        $key === 'check_shared_agent'       => ['👥', 'Check Co-Agent', 'check'],
        $key === 'check_site_team'          => ['🏢', 'Check Site Team', 'check'],

        $key === 'verify_contact'           => ['✔️', 'Verify Contact', 'verify'],

        $key === 'retry_call'               => ['📞', 'Retry Call', 'call'],
        $key === 'followup_call'            => ['📞', 'Follow-up Call', 'call'],
        $key === 'call'                     => ['📞', 'Call', 'call'],

        default                             => ['📞', 'Task', 'call'],
    };

    [$icon, $label, $tone] = $meta;
@endphp

<span {{ $attributes->merge(['class' => 'task-type-chip tt-' . $tone]) }}
      title="{{ $actionType }}">
    <span class="tt-icon">{{ $icon }}</span>
    <span class="tt-label">{{ $label }}</span>
</span>