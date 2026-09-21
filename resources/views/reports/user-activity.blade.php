@extends('layouts.app')

@section('title', 'User Activity — NPO CRM')

@section('content')
    <a href="{{ url('/reports') }}" class="back-link">← Back to Reports</a>

    <div class="card">
        <div class="section-head">
            <h3>⏱️ User Activity</h3>
            <form method="GET" action="{{ url('/reports/user-activity') }}" style="display:flex;gap:8px;align-items:center;">
                <label style="font-size:12px;margin:0;">Date</label>
                <input type="date" name="date" value="{{ $date }}" max="{{ now()->toDateString() }}"
                       style="padding:6px 10px;font-size:13px;">
                <button type="submit" class="btn-small">Go</button>
            </form>
        </div>

        <p class="muted" style="font-size:12px;margin-bottom:var(--s-3);">
            Active time counts only gaps between actions under 5 minutes. Idle time (tab open, walked away) is not counted.
        </p>

        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>User</th>
                        <th style="width:90px;">Today</th>
                        <th style="width:90px;">First hit</th>
                        <th style="width:90px;">Last hit</th>
                        <th style="width:70px;">Views</th>
                        <th style="width:110px;">7-day total</th>
                        <th style="width:80px;">Active days</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($users as $u)
                        @php
                            $s = $sessions->get($u->id);
                            $w = $weekly->get($u->id);
                        @endphp
                        <tr>
                            <td>
                                <strong>{{ $u->name }}</strong>
                                <div class="muted" style="font-size:11px;">{{ $u->role }}</div>
                            </td>
                            <td>
                                @if ($s)
                                    <span class="badge {{ $s->total_active_seconds >= 3600 ? 'green' : ($s->total_active_seconds >= 900 ? 'blue' : '') }}">
                                        {{ $s->duration_label }}
                                    </span>
                                @else
                                    <span class="muted">—</span>
                                @endif
                            </td>
                            <td class="muted" style="font-size:12px;">{{ $s?->started_at?->format('H:i') ?? '—' }}</td>
                            <td class="muted" style="font-size:12px;">{{ $s?->last_activity_at?->format('H:i') ?? '—' }}</td>
                            <td class="muted" style="font-size:12px;">{{ $s?->page_views ?? 0 }}</td>
                            <td>
                                @if ($w)
                                    @php
                                        $sec = (int) $w->total_secs;
                                        $lbl = $sec < 3600 ? intdiv($sec, 60) . 'm' : sprintf('%dh %02dm', intdiv($sec, 3600), intdiv($sec % 3600, 60));
                                    @endphp
                                    <strong>{{ $lbl }}</strong>
                                    <div class="muted" style="font-size:11px;">{{ $w->total_views }} views</div>
                                @else
                                    <span class="muted">—</span>
                                @endif
                            </td>
                            <td class="muted" style="font-size:12px;">{{ $w->active_days ?? 0 }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
@endsection