@extends('layouts.app')

@section('title', 'Brokerage Report — NPO CRM')

@section('content')

    <a href="{{ url('/') }}" class="back-link">← Back to dashboard</a>

    {{-- ============================================================ --}}
    {{-- HEADER + STATS --}}
    {{-- ============================================================ --}}
    <div class="card">
        <div class="section-head">
            <div>
                <h2 style="margin:0;">💼 Brokerage Report</h2>
                <p class="muted" style="margin-top:4px;font-size:13px;">
                    Every confirmed booking and its commission status.
                </p>
            </div>

            <a href="{{ url('/export/leads?status=booking') }}" class="btn-small btn-info">
                📥 Export Bookings CSV
            </a>
        </div>

        <div class="grid-stats" style="margin-top:var(--s-3);">
            <x-stat-card label="🎯 Bookings"     :value="$totals['count']" />
            <x-stat-card label="🏢 Total Value"  :value="'₹' . number_format($totals['booking_value'], 0)" />
            <x-stat-card label="💼 Brokerage"    :value="'₹' . number_format($totals['brokerage'], 0)" variant="green" />
            <x-stat-card label="✅ Received"      :value="'₹' . number_format($totals['received'], 0)" variant="green" />
            <x-stat-card label="⏳ Pending"       :value="'₹' . number_format($totals['pending'], 0)" variant="orange" />
            <x-stat-card label="⚠️ Disputed"      :value="'₹' . number_format($totals['disputed'], 0)" variant="red" />
        </div>
    </div>

    {{-- ============================================================ --}}
    {{-- FILTERS --}}
    {{-- ============================================================ --}}
    <div class="card">
        <h3>🔍 Filters</h3>

        <form method="GET" action="{{ url('/reports/brokerage') }}">
            <div class="flex">
                <div class="flex-item">
                    <label>Brokerage Status</label>
                    <select name="brokerage_status" class="input">
                        <option value="">— Any —</option>
                        @foreach (['pending','invoiced','received','disputed'] as $bs)
                            <option value="{{ $bs }}" @selected($statusFilter === $bs)>
                                {{ ucfirst($bs) }}
                            </option>
                        @endforeach
                    </select>
                </div>

                <div class="flex-item">
                    <label>Agent</label>
                    <select name="agent_id" class="input">
                        <option value="">— Any —</option>
                        @foreach ($agents as $agent)
                            <option value="{{ $agent->id }}" @selected((string) $agentFilter === (string) $agent->id)>
                                {{ $agent->user?->name ?? ('Agent #' . $agent->id) }}
                            </option>
                        @endforeach
                    </select>
                </div>

                <div class="flex-item">
                    <label>Booked From</label>
                    <input type="date" name="from" class="input" value="{{ $fromFilter }}">
                </div>

                <div class="flex-item">
                    <label>Booked To</label>
                    <input type="date" name="to" class="input" value="{{ $toFilter }}">
                </div>
            </div>

            <div style="margin-top:var(--s-3);display:flex;gap:var(--s-2);flex-wrap:wrap;">
                <button type="submit" class="btn">Apply Filters</button>
                <a href="{{ url('/reports/brokerage') }}" class="btn btn-ghost"
                   style="background:transparent;color:var(--c-text);border:1.5px solid var(--c-border);">
                    Reset
                </a>
            </div>
        </form>
    </div>

    {{-- ============================================================ --}}
    {{-- BOOKINGS TABLE --}}
    {{-- ============================================================ --}}
    <div class="card">
        <h3>📋 Bookings ({{ number_format($bookings->total()) }})</h3>

        @if ($bookings->isEmpty())
            <p class="muted">No bookings match your filters.</p>
        @else
            <div class="table-wrap">
                <table>
                    <tr>
                        <th>Customer</th>
                        <th>Project</th>
                        <th>Agent</th>
                        <th>Booking ₹</th>
                        <th>Brokerage</th>
                        <th>Status</th>
                        <th>Booked</th>
                        <th>Actions</th>
                    </tr>
                    @foreach ($bookings as $lead)
                        <tr>
                            <td>
                                <a href="{{ url('/leads/' . $lead->id) }}"
                                   style="color:inherit;text-decoration:none;font-weight:600;">
                                    {{ $lead->customer_name }}
                                </a>
                            </td>
                            <td>{{ $lead->project?->name ?? '—' }}</td>
                            <td>{{ $lead->agent?->user?->name ?? '—' }}</td>
                            <td>₹{{ number_format((float) $lead->booking_amount, 0) }}</td>
                            <td>
                                <strong>₹{{ number_format((float) $lead->brokerage_amount, 0) }}</strong>
                                @if ($lead->brokerage_percentage)
                                    <br><span class="muted" style="font-size:11px;">
                                        {{ $lead->brokerage_percentage }}%
                                    </span>
                                @endif
                            </td>
                            <td>
                                @php
                                    $bs = $lead->brokerage_status ?? 'pending';
                                    $bc = [
                                        'pending'  => 'orange',
                                        'invoiced' => 'blue',
                                        'received' => 'green',
                                        'disputed' => 'red',
                                    ][$bs] ?? 'blue';
                                @endphp
                                <span class="badge {{ $bc }}">{{ ucfirst($bs) }}</span>
                            </td>
                            <td>{{ $lead->booking_date?->format('d M Y') ?? '—' }}</td>
                            <td>
                                <a href="{{ url('/leads/' . $lead->id) }}" class="btn-small btn-info">
                                    View
                                </a>
                            </td>
                        </tr>
                                        @endforeach
                </table>
            </div>

            @include('partials.pagination', ['paginator' => $bookings])
        @endif
    </div>

@endsection