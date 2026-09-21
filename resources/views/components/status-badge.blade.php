@props(['status'])

@php
    $settings = app(\App\Services\SettingsService::class);

    if ($status instanceof \App\Enums\LeadStatus) {
        $key = $status->value;
    } else {
        $key = (string) $status;
    }

    $label = $settings->statusLabel($key);
    $color = $settings->statusColor($key);
@endphp

<span class="badge {{ $color }}">{{ $label }}</span>