@props(['counts' => [], 'scope' => null])

@php
    $settings   = app(\App\Services\SettingsService::class);
    $statuses   = $settings->statuses();
    $maxCount   = ! empty($counts) ? max($counts) : 1;
    $hasAnyLead = ! empty($counts) && array_sum($counts) > 0;
@endphp

<div class="card">
    <h3>📊 Lead Status</h3>

    @if (! $hasAnyLead)
        <p class="muted">No leads yet.</p>
    @else
        @foreach ($statuses as $status)
            @php
                $count = $counts[$status->key] ?? 0;
                if ($count === 0) continue;
                $width = ($count / $maxCount) * 100;
            @endphp
            <a href="{{ url('/leads?status=' . urlencode($status->key) . ($scope ? '&scope=' . urlencode($scope) : '')) }}"
               class="chart-bar chart-bar-link"
               title="See all {{ $count }} {{ $status->label }} lead{{ $count === 1 ? '' : 's' }}">
                <span class="chart-label">{{ $status->label }}</span>
                <div class="chart-track">
                    <div class="chart-fill {{ $status->color }}" style="width:{{ $width }}%;"></div>
                </div>
                <span style="font-weight:bold;min-width:30px;">{{ $count }}</span>
            </a>
        @endforeach
    @endif
</div>