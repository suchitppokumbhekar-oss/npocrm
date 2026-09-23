@extends('layouts.app')

@section('title', 'Payroll Attendance — NPO CRM')

@section('content')

    @php
        $isTeamManager = session('user_role') === 'team_manager';
        $scopeLabel = $workScope === 'delegated' ? 'All Delegated' : 'My Team';
        $scopeQuery = $isTeamManager ? '&scope=' . $workScope : '';
    @endphp

    <a href="{{ url('/') }}" class="back-link">← Back to Dashboard</a>

    <div class="card attendance-shell">
        <div class="attendance-page-head">
            <div>
                <h3>Attendance</h3>
                <p class="muted">Monitor payroll attendance for employees in your current scope.</p>
            </div>
            <div class="attendance-head-count">
                <strong>{{ $agents->count() }}</strong>
                <span>{{ $isTeamManager ? $scopeLabel : 'Payroll employees' }}</span>
            </div>
        </div>

        @if (session('success'))
            <div class="alert alert-success attendance-alert">{{ session('success') }}</div>
        @endif
        @if (session('info'))
            <div class="alert attendance-alert attendance-alert-info">{{ session('info') }}</div>
        @endif
        @if (session('errors') && session('errors')->has('attendance'))
            <div class="alert alert-error attendance-alert">{{ session('errors')->first('attendance') }}</div>
        @endif

        @if ($isTeamManager)
            <div class="task-scope-tabs attendance-scope-tabs">
                <a href="{{ url('/attendance?view=' . $view . '&scope=team' . ($view === 'daily' ? '&date=' . $selectedDate : '&month=' . $selectedMonth)) }}" class="task-scope-tab {{ $workScope === 'team' ? 'active' : '' }}">
                    <strong>My Team</strong>
                    <small>Employees in teams you directly manage</small>
                </a>
                <a href="{{ url('/attendance?view=' . $view . '&scope=delegated' . ($view === 'daily' ? '&date=' . $selectedDate : '&month=' . $selectedMonth)) }}" class="task-scope-tab {{ $workScope === 'delegated' ? 'active' : '' }}">
                    <strong>All Delegated</strong>
                    <small>All employees within your delegated authority</small>
                </a>
            </div>
        @endif

        <nav class="attendance-period-tabs">
            <a href="{{ url('/attendance?view=daily&date=' . $selectedDate . $scopeQuery) }}" class="{{ $view === 'daily' ? 'active' : '' }}">Daily</a>
            <a href="{{ url('/attendance?view=monthly&month=' . $selectedMonth . $scopeQuery) }}" class="{{ $view === 'monthly' ? 'active' : '' }}">Monthly</a>
        </nav>

        @if ($agents->isEmpty())
            <div class="alert" style="background:#fff7ed;border-left:3px solid #f97316;padding:12px 14px;border-radius:6px;">
                No employees are currently marked <strong>On payroll</strong>. Attendance will appear here when that checkbox is enabled in the employee record.
            </div>
        @elseif ($view === 'daily')
            <div class="attendance-toolbar">
                <form method="GET" action="{{ url('/attendance') }}" class="attendance-date-form">
                    <input type="hidden" name="view" value="daily">
                    @if ($isTeamManager)<input type="hidden" name="scope" value="{{ $workScope }}">@endif
                    <div class="field attendance-date-field">
                        <label>Date</label>
                        <input type="date" name="date" class="input" value="{{ $selectedDate }}" max="{{ $today }}">
                    </div>
                    <button class="btn-small btn-info" type="submit">View Day</button>
                </form>
                <div class="attendance-toolbar-actions">
                    <a class="btn-small btn-ghost" href="{{ url('/attendance?view=daily&date=' . $today . $scopeQuery) }}">Today</a>
                    <a class="btn-small btn-view" href="{{ url('/attendance/export?type=daily&date=' . $selectedDate . $scopeQuery) }}">Export CSV</a>
                </div>
            </div>

            <div class="attendance-metrics">
                <div class="attendance-metric"><span>Employees</span><strong>{{ $dailyStats['total'] }}</strong></div>
                <div class="attendance-metric"><span>Present</span><strong>{{ $dailyStats['present'] }}</strong></div>
                <div class="attendance-metric"><span>Absent / Not In</span><strong>{{ $dailyStats['absent'] }}</strong></div>
                <div class="attendance-metric"><span>Still Working</span><strong>{{ $dailyStats['open'] }}</strong></div>
            </div>

            <div class="attendance-register-head">
                <div><strong>Daily Register</strong><span>{{ $selectedDate }}</span></div>
                <span>{{ $agents->count() }} employees</span>
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

            <div class="attendance-mobile-list">
                @foreach ($agents as $agent)
                    @php
                        $att = $attendanceByAgent->get($agent->id);
                        $isIn = $att?->isCheckedIn() ?? false;
                        $isOut = $att?->isCheckedOut() ?? false;
                        $isManual = $att && $att->override_by_user_id;
                    @endphp
                    <article class="attendance-mobile-card">
                        <div class="attendance-mobile-head">
                            <div>
                                <strong>{{ $agent->user?->name ?? '(no user)' }}</strong>
                                @if ($agent->user?->email)<div class="muted attendance-mobile-email">{{ $agent->user->email }}</div>@endif
                            </div>
                            <div>
                                @if ($isOut)
                                    <span class="badge" style="background:#e2e8f0;color:#475569;">🏁 Present · Out</span>
                                @elseif ($isIn)
                                    @if ($isManual)<span class="badge orange">🔶 Manual</span>@else<span class="badge green">🟢 Present · In</span>@endif
                                @else
                                    <span class="badge red">🔴 Absent</span>
                                @endif
                            </div>
                        </div>

                        <div class="attendance-mobile-grid">
                            <div><span>Check-in</span><strong>{{ $att?->checked_in_at?->format('H:i') ?? '—' }}</strong></div>
                            <div><span>Check-out</span><strong>{{ $att?->checked_out_at?->format('H:i') ?? '—' }}</strong></div>
                            <div><span>Worked</span><strong>{{ $att?->durationLabel() ?? '—' }}</strong></div>
                            <div>
                                <span>Attendance</span>
                                <strong>
                                    @if ($att && $att->override_by_user_id)
                                        Manager override
                                    @elseif ($att)
                                        GPS check-in
                                    @else
                                        No record
                                    @endif
                                </strong>
                            </div>
                        </div>

                        @if ($att?->checked_in_distance_m !== null)
                            <div class="muted attendance-mobile-distance">{{ $att->checked_in_distance_m }}m from office</div>
                        @endif

                        <div class="attendance-mobile-actions">
                            @if (! $isIn)
                                <button class="btn-small" type="button" data-modal="attendance-manual"
                                        data-agent-id="{{ $agent->id }}" data-agent-name="{{ $agent->user?->name ?? 'Employee' }}"
                                        style="background:#22c55e;color:#fff;">📍 Manual Check-In</button>
                            @elseif ($isIn && ! $isOut)
                                <form method="POST" action="{{ url('/attendance/force-check-out') }}">
                                    @csrf
                                    <input type="hidden" name="attendance_id" value="{{ $att->id }}">
                                    <input type="hidden" name="override_reason" value="Force checkout by {{ session('user_name', 'admin') }}">
                                    <button class="btn-small" type="submit" onclick="return confirm('Force checkout for this employee?');" style="background:#e74c3c;color:#fff;">⏹ Force Out</button>
                                </form>
                            @else
                                <span class="muted" style="font-size:12px;">✓ Attendance complete</span>
                            @endif
                        </div>
                    </article>
                @endforeach
            </div>
        @else
            <div class="attendance-toolbar">
                <form method="GET" action="{{ url('/attendance') }}" class="attendance-date-form">
                    <input type="hidden" name="view" value="monthly">
                    @if ($isTeamManager)<input type="hidden" name="scope" value="{{ $workScope }}">@endif
                    <div class="field attendance-date-field">
                        <label>Month</label>
                        <input type="month" name="month" class="input" value="{{ $selectedMonth }}">
                    </div>
                    <button class="btn-small btn-info" type="submit">View Month</button>
                </form>
                <div class="attendance-toolbar-actions">
                    <a class="btn-small btn-view" href="{{ url('/attendance/export?type=monthly&month=' . $selectedMonth . $scopeQuery) }}">Export CSV</a>
                </div>
            </div>

            <div class="attendance-report-note">
                <div><strong>{{ $monthStart->format('F Y') }}</strong><span>{{ $calendarDays }} calendar day{{ $calendarDays === 1 ? '' : 's' }} included</span></div>
                <p>Attendance record only. Weekly offs, approved leave, half-days and payroll deductions should be applied according to payroll policy.</p>
            </div>

            <div class="attendance-register-head">
                <div><strong>Monthly Register</strong><span>{{ $monthStart->format('F Y') }}</span></div>
                <span>{{ $agents->count() }} employees</span>
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

            <div class="attendance-mobile-list">
                @foreach ($monthlyRows as $row)
                    <article class="attendance-mobile-card">
                        <div class="attendance-mobile-head">
                            <div>
                                <strong>{{ $row->agent->user?->name ?? '(no user)' }}</strong>
                                @if ($row->agent->user?->email)<div class="muted attendance-mobile-email">{{ $row->agent->user->email }}</div>@endif
                            </div>
                            <span class="badge blue">{{ number_format($row->attendance_percent, 1) }}%</span>
                        </div>

                        <div class="attendance-mobile-grid">
                            <div><span>Present</span><strong>{{ $row->present }}</strong></div>
                            <div><span>Absent</span><strong>{{ $row->absent }}</strong></div>
                            <div><span>Open</span><strong>{{ $row->open }}</strong></div>
                            <div><span>Manual</span><strong>{{ $row->manual }}</strong></div>
                        </div>

                        <div class="attendance-mobile-total">
                            <span>Total worked</span>
                            <strong>{{ intdiv($row->minutes, 60) }}h {{ str_pad($row->minutes % 60, 2, '0', STR_PAD_LEFT) }}m</strong>
                        </div>
                    </article>
                @endforeach
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
