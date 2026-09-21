@props(['labels', 'max' => 3, 'showIcons' => true, 'clickable' => true])

@php
    $all = collect($labels);
    $shown = $all->take($max);
    $remaining = max(0, $all->count() - $max);

    // Icon + tone per label group
    $groupMeta = [
        'property_type'   => ['icon' => '🏠', 'tone' => 'pt'],
        'buyer_type'      => ['icon' => '👤', 'tone' => 'bt'],
        'payment'         => ['icon' => '💰', 'tone' => 'pay'],
        'property_status' => ['icon' => '🏗️', 'tone' => 'ps'],
        'special'         => ['icon' => '⭐', 'tone' => 'sp'],
    ];

    $meta = fn ($lbl) => $groupMeta[$lbl->group_key] ?? ['icon' => '', 'tone' => 'default'];
@endphp

@if ($all->isNotEmpty())
    <div class="lead-labels-row">
        @foreach ($shown as $lbl)
            @php $m = $meta($lbl); @endphp

            @if ($clickable)
                <a href="{{ url('/leads?label_id=' . $lbl->id) }}"
                   class="lead-label-chip ll-{{ $m['tone'] }}"
                   title="See all leads with {{ $lbl->group_label }}: {{ $lbl->label }}">
                    @if ($showIcons && $m['icon'])
                        <span class="ll-icon">{{ $m['icon'] }}</span>
                    @endif
                    <span class="ll-text">{{ $lbl->label }}</span>
                </a>
            @else
                <span class="lead-label-chip ll-{{ $m['tone'] }}"
                      title="{{ $lbl->group_label }}: {{ $lbl->label }}">
                    @if ($showIcons && $m['icon'])
                        <span class="ll-icon">{{ $m['icon'] }}</span>
                    @endif
                    <span class="ll-text">{{ $lbl->label }}</span>
                </span>
            @endif
        @endforeach

        @if ($remaining > 0)
            <span class="lead-label-more"
                  title="{{ $all->slice($max)->pluck('label')->implode(', ') }}">
                +{{ $remaining }}
            </span>
        @endif
    </div>
@endif