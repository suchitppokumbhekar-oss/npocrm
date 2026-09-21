@extends('layouts.app')

@section('title', $agent->user?->name.' — '.$label)

@section('content')
<div class="container" style="max-width:1000px;margin:0 auto;padding:16px;">
    <div class="card" style="margin-bottom:14px;">
        <a href="{{ url('/admin/work-summary?period='.$period) }}" style="font-size:13px;">← Back to work summary</a>
        <h2 style="margin:8px 0 4px;">{{ $agent->user?->name ?? 'Agent' }} · {{ $label }}</h2>
        <div class="muted" style="font-size:12px;">{{ $from->format('d M Y, h:i A') }} → {{ $to->format('d M Y, h:i A') }} · {{ $rows->count() }} record{{ $rows->count() === 1 ? '' : 's' }}</div>
    </div>

    <div class="card">
        @forelse($rows as $row)
            <a href="{{ url('/leads/'.$row->lead_id.'?return_to='.urlencode(url()->full())) }}" style="display:block;padding:12px 4px;border-bottom:1px solid #eef2f7;text-decoration:none;">
                <div style="display:flex;justify-content:space-between;gap:12px;align-items:flex-start;">
                    <div style="min-width:0;">
                        <strong>{{ $row->name }}</strong>
                        @if($row->project)<span class="muted"> · {{ $row->project }}</span>@endif
                        <div class="muted" style="font-size:12px;margin-top:4px;">{{ $row->detail }}</div>
                    </div>
                    <span class="muted" style="font-size:11px;white-space:nowrap;">{{ $row->at->format('d M, h:i A') }}</span>
                </div>
            </a>
        @empty
            <div class="muted">No CRM records found for this metric and period.</div>
        @endforelse
    </div>
</div>
@endsection
