@extends('layouts.app')

@section('title', 'Payroll Attendance — NPO CRM')

@section('content')

    <a href="{{ url('/') }}" class="back-link">← Back to Dashboard</a>

    <div class="card">
        <div class="section-head" style="gap:10px;flex-wrap:wrap;">
            <div>
                <h3 style="margin-bottom:4px;">🕒 Payroll Attendance</h3>
                <p class="muted" style="margin:0;font-size:12px;">Attendance is maintained only for employees marked <strong>On payroll</strong>. Use these records for salary calculation.</p>
            </div>
            <span class="badge blue">{{ $agents->count() }} payroll employees</span>
        </div>

        @if (session('success'))
            <div class="alert alert-success" style="margin-bottom:var(--s-3);">{{ session('success') }}</div>
        @endif
        @if (session('info'))
            <div class="alert" style="margin-bottom:var(--s-3);background:#e0f2fe;border-left:3px solid #0284c7;padding:10px 14px;border-radius:6px;">{{ session('info') }}</div>
        @endif
        @if (session('errors') && session('errors')->has('attendance'))
            <div class="alert alert-error" style="margin-bottom:var(--s-3);">{{ session('errors')->first('attendance') }}</div>
        @endif

        <nav class="settings-tabs" style="margin-bottom:var(--s-3);">
            <a href="{{ url('/attendance?view=daily&date=' . $selectedDate) }}" class="tab {{ $view === 'daily' ? 'active' : '' }}">📅 Daily Attendance</a>
            <a href="{{ url('/attendance?view=monthly&month=' . $selectedMonth) }}" class="tab {{ $view === 'monthly' ? 'active' : '' }}">📊 Monthly Payroll</a>
        </nav>

        @if ($agents->isEmpty())
            <div class="alert" style="background:#fff7ed;border-left:3px solid #f97316;padding:12px 14px;border-radius:6px;">
                No employees are currently marked <strong>On payroll</strong>. Attendance will appear here when that checkbox is enabled in the employee record.
            </div>
        @elseif ($view === 'daily')
            <form method="GET" action="{{ url('/attendance') }}" style="display:flex;gap:10px;align-items:end;flex-wrap:wrap;margin-bottom:var(--s-3);">
                <input type="hidden" name="view" value="daily">
                <div class="field" style="margin:0;min-width:180px;">
                    <label>Date</label>
                    <input type="date" name="date" class="input" value="{{ $selectedDate }}" max="{{ $today }}">
                </div>
                <button class="btn-small btn-info" type="submit">🔎 View Day</button>
                <a class="btn-small btn-ghost" href="{{ url('/attendance?view=daily&date=' . $today) }}">Today</a>
                <a class="btn-small btn-view" href="{{ url('/attendance/export?type=daily&date=' . $selectedDate) }}">📥 Export Daily CSV</a>
            </form>

            <div class="stats-grid" style="margin-bottom:var(--s-3);">
                <div class="card" style="margin:0;padding:12px;"><div class="muted" style="font-size:11px;">Payroll Employees</div><strong style="font-size:22px;">{{ $dailyStats['total'] }}</strong></div>
                <div class="card" style="margin:0;padding:12px;"><div class="muted" style="font-size:11px;">Present</div><strong style="font-size:22px;">{{ $dailyStats['present'] }}</strong></div>
                <div class="card" style="margin:0;padding:12px;"><div class="muted" style="font-size:11px;">Absent / Not In</div><strong style="font-size:22px;">{{ $dailyStats['absent'] }}</strong></div>
                <div class="card" style="margin:0;padding:12px;"><div class="muted" style="font-size:11px;">Still Working</div><strong style="font-size:22px;">{{ $dailyStats['open'] }}</strong></div>
            </div>

            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Employee</th>
                            <th>Status</th>
                            <th>Check-in</th>
                            <th>Check-out</th>
                            <th>Worked</th>
                            <th>Attendance</th>
                            <th style="width:220px;">Manager Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($agents as $agent)
                            @php
                                $att = $attendanceByAgent->get($agent->id);
                                $isIn = $att?->isCheckedIn() ?? false;
                                $isOut = $att?->isCheckedOut() ?? false;
                                $isManual = $att && $att->override_by_user_id;
                            @endphp
                            <tr>
                                <td>
                                    <strong>{{ $agent->user?->name ?? '(no user)' }}</strong>
                                    @if ($agent->user?->email)<div class="muted" style="font-size:11px;">{{ $agent->user->email }}</div>@endif
                                </td>
                                <td>
                                    @if ($isOut)
                                        <span class="badge" style="background:#e2e8f0;color:#475569;">🏁 Present · Out</span>
                                    @elseif ($isIn)
                                        @if ($isManual)<span class="badge orange">🔶 Manual</span>@else<span class="badge green">🟢 Present · In</span>@endif
                                    @else
                                        <span class="badge red">🔴 Absent</span>
                                    @endif
                                </td>
                                <td class="muted" style="font-size:12px;">
                                    {{ $att?->checked_in_at?->format('H:i') ?? '—' }}
                                    @if ($att?->checked_in_distance_m !== null)<div style="font-size:11px;">{{ $att->checked_in_distance_m }}m from office</div>@endif
                                </td>
                                <td class="muted" style="font-size:12px;">{{ $att?->checked_out_at?->format('H:i') ?? '—' }}</td>
                                <td class="muted" style="font-size:12px;">{{ $att?->durationLabel() ?? '—' }}</td>
                                <td class="muted" style="font-size:12px;">
                                    @if ($att && $att->override_by_user_id)
                                        Manager override
                                    @elseif ($att)
                                        GPS check-in
                                    @else
                                        No record
                                    @endif
                                </td>
                                <td class="row-actions">
                                    @if (! $isIn)
                                        <button class="btn-small" type="button" data-modal="attendance-manual"
                                                data-agent-id="{{ $agent->id }}" data-agent-name="{{ $agent->user?->name ?? 'Employee' }}"
                                                style="background:#22c55e;color:#fff;">📍 Manual Check-In</button>
                                    @elseif ($isIn && ! $isOut)
                                        <form method="POST" action="{{ url('/attendance/force-check-out') }}" style="display:inline;">
                                            @csrf
                                            <input type="hidden" name="attendance_id" value="{{ $att->id }}">
                                            <input type="hidden" name="override_reason" value="Force checkout by {{ session('user_name', 'admin') }}">
                                            <button class="btn-small" type="submit" onclick="return confirm('Force checkout for this employee?');" style="background:#e74c3c;color:#fff;">⏹ Force Out</button>
                                        </form>
                                    @else
                                        <span class="muted" style="font-size:11px;">Complete</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @else
            <form method="GET" action="{{ url('/attendance') }}" style="display:flex;gap:10px;align-items:end;flex-wrap:wrap;margin-bottom:var(--s-3);">
                <input type="hidden" name="view" value="monthly">
                <div class="field" style="margin:0;min-width:180px;">
                    <label>Month</label>
                    <input type="month" name="month" class="input" value="{{ $selectedMonth }}">
                </div>
                <button class="btn-small btn-info" type="submit">🔎 View Month</button>
                <a class="btn-small btn-view" href="{{ url('/attendance/export?type=monthly&month=' . $selectedMonth) }}">📥 Export Payroll CSV</a>
            </form>

            <div class="alert" style="background:#f8fafc;border-left:3px solid #64748b;padding:10px 14px;border-radius:6px;margin-bottom:var(--s-3);">
                <strong>{{ $monthStart->format('F Y') }}</strong> · {{ $calendarDays }} calendar day{{ $calendarDays === 1 ? '' : 's' }} in the reporting period.
                For the current month, only days through today are included. This report shows attendance data; salary rules such as weekly offs, approved leave, half-days and deductions should be applied according to your payroll policy.
            </div>

            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Employee</th>
                            <th>Present</th>
                            <th>Absent</th>
                            <th>Open</th>
                            <th>Manual</th>
                            <th>Attendance %</th>
                            <th>Total Worked</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($monthlyRows as $row)
                            <tr>
                                <td>
                                    <strong>{{ $row->agent->user?->name ?? '(no user)' }}</strong>
                                    @if ($row->agent->user?->email)<div class="muted" style="font-size:11px;">{{ $row->agent->user->email }}</div>@endif
                                </td>
                                <td><strong>{{ $row->present }}</strong></td>
                                <td>{{ $row->absent }}</td>
                                <td>{{ $row->open }}</td>
                                <td>{{ $row->manual }}</td>
                                <td><strong>{{ number_format($row->attendance_percent, 1) }}%</strong></td>
                                <td>{{ intdiv($row->minutes, 60) }}h {{ str_pad($row->minutes % 60, 2, '0', STR_PAD_LEFT) }}m</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    {{-- Manager Manual Check-In --}}
    <div class="modal-overlay" id="modal-attendance-manual" style="display:none;">
        <div class="modal-box" style="max-width:520px;">
            <div class="modal-head">
                <h3>📍 Manual Check-In</h3>
                <button class="modal-close" type="button" data-modal-close>&times;</button>
            </div>
            <form method="POST" action="{{ url('/attendance/manual-check-in') }}" class="modal-body">
                @csrf
                <input type="hidden" name="agent_id" id="mc-agent-id">
                <p class="muted" style="font-size:13px;margin-bottom:12px;">Checking in <strong id="mc-agent-name">—</strong>. Use this only for an approved exception, such as a site visit, with a clear reason.</p>
                <div class="field"><label>Reason *</label><input type="text" name="reason" required maxlength="500" placeholder="e.g. Site visit — location shared on WhatsApp"></div>
                <div class="field"><label>Latitude (optional)</label><input type="text" name="lat" placeholder="e.g. 19.0173" inputmode="decimal"></div>
                <div class="field"><label>Longitude (optional)</label><input type="text" name="lng" placeholder="e.g. 73.0372" inputmode="decimal"></div>
                <button type="submit" class="btn btn-block" style="background:#22c55e;">✅ Confirm Check-In</button>
            </form>
        </div>
    </div>

    <script>
    (function () {
        document.querySelectorAll('[data-modal="attendance-manual"]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                document.getElementById('mc-agent-id').value = btn.dataset.agentId;
                document.getElementById('mc-agent-name').textContent = btn.dataset.agentName;
                document.getElementById('modal-attendance-manual').style.display = 'flex';
            });
        });
        var overlay = document.getElementById('modal-attendance-manual');
        if (!overlay) return;
        overlay.addEventListener('click', function (e) { if (e.target === overlay) overlay.style.display = 'none'; });
        overlay.querySelectorAll('[data-modal-close]').forEach(function (x) { x.addEventListener('click', function () { overlay.style.display = 'none'; }); });
    })();
    </script>

@endsection
