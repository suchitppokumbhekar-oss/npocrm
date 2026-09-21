@props(['date' => null, 'fallback' => '—', 'withYear' => false])
@php
    $value = $date instanceof \Carbon\Carbon ? $date : ($date ? \Carbon\Carbon::parse($date) : null);
    if (!$value) { echo e($fallback); return; }
    $time = $value->format('g:i A');
    if ($value->isToday()) echo 'Today, ' . e($time);
    elseif ($value->isTomorrow()) echo 'Tomorrow, ' . e($time);
    elseif ($value->isYesterday()) echo 'Yesterday, ' . e($time);
    else echo e($value->format($withYear ? 'd M Y, g:i A' : 'd M, g:i A'));
@endphp
