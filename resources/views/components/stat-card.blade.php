@props(['label', 'value', 'variant' => 'default'])

<div class="stat {{ $variant !== 'default' ? $variant : '' }}">
    <h3>{{ $label }}</h3>
    <p>{{ $value }}</p>
</div>