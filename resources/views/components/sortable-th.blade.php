@props([
    'label',
    'field',
    'align' => 'left',
])

@php
    $currentSort = request('sort');
    $currentDir  = request('dir', 'asc');
    $isActive    = $currentSort === $field;
    $nextDir     = ($isActive && $currentDir === 'asc') ? 'desc' : 'asc';
    $url         = request()->fullUrlWithQuery(['sort' => $field, 'dir' => $nextDir]);
@endphp

<th style="text-align:{{ $align }};white-space:nowrap;">
    <a href="{{ $url }}"
       style="color:inherit;text-decoration:none;display:inline-flex;align-items:center;gap:4px;">
        {{ $label }}
        @if ($isActive)
            <span style="font-size:10px;">{{ $currentDir === 'asc' ? '▲' : '▼' }}</span>
        @else
            <span style="font-size:10px;color:#cbd5e1;">⇅</span>
        @endif
    </a>
</th>