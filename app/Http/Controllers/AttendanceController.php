<?php

namespace App\Http\Controllers;

use App\Models\Activity;
use App\Models\Agent;
use App\Models\Attendance;
use App\Models\Followup;
use App\Models\OfficeLocation;
use App\Models\User;
use App\Services\AccessService;
use App\Services\TeamService;
use Illuminate\Http\Request;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class AttendanceController extends Controller
{
    public function __construct(private AccessService $access, private TeamService $teams) {}

    private function attendanceScope(Request $request): array
    {
        $role = session('user_role');
        $userId = (int) session('user_id');
        $authorizedAgentIds = $this->access->visibleAgentIds();

        if ($role !== 'team_manager') {
            return [null, $authorizedAgentIds];
        }

        $scope = $request->input('scope') === 'delegated' ? 'delegated' : 'team';

        if ($scope === 'delegated') {
            return [$scope, $authorizedAgentIds];
        }

        $teamAgentIds = $this->teams->agentIdsForManager($userId);
        $teamAgentIds = array_values(array_intersect($teamAgentIds, $authorizedAgentIds));

        return [$scope, $teamAgentIds];
    }

    /* ============================================================
       CHECK IN — must be within office radius
       ============================================================ */
    public function checkIn(Request $request)
    {
        $user = User::find(session('user_id'));

        if (! $user || ! $user->requiresAttendance()) {
            return back()->with('info', 'Attendance is not required for your role.');
        }

        $agent = Agent::where('user_id', $user->id)->first();
        if (! $agent) {
            return back()->withErrors(['attendance' => 'You have no agent record.']);
        }

        // Already checked in today?
        $existing = Attendance::where('agent_id', $agent->id)
            ->where('shift_date', now()->toDateString())
            ->first();

        if ($existing && $existing->isCheckedIn()) {
            return back()->with('info', 'You are already checked in.');
        }

        $validated = $request->validate([
            'lat'      => 'required|numeric|between:-90,90',
            'lng'      => 'required|numeric|between:-180,180',
            'accuracy' => 'nullable|numeric|min:0',
        ]);

        $office = OfficeLocation::active()->first();
        if (! $office) {
            return back()->withErrors([
                'attendance' => 'No office location is configured. Contact admin.',
            ]);
        }

        $distance = $this->haversineMeters(
            (float) $validated['lat'],
            (float) $validated['lng'],
            (float) $office->latitude,
            (float) $office->longitude
        );

        if ($distance > $office->radius_meters) {
            return back()->withErrors([
                'attendance' => sprintf(
                    'You are %dm from the office. Check-in requires being within %dm.',
                    $distance,
                    $office->radius_meters
                ),
            ]);
        }

        Attendance::updateOrCreate(
            ['agent_id' => $agent->id, 'shift_date' => now()->toDateString()],
            [
                'checked_in_at'         => now(),
                'checked_in_lat'        => $validated['lat'],
                'checked_in_lng'        => $validated['lng'],
                'checked_in_accuracy_m' => $validated['accuracy'] ?? null,
                'checked_in_distance_m' => $distance,
                'checked_in_ip'         => $request->ip(),
            ]
        );

        return redirect('/')->with(
            'success',
            sprintf('✅ Checked in at %s · %dm from office', now()->format('H:i'), $distance)
        );
    }

    /* ============================================================
       ATTENDANCE REPORTS — payroll employees only
       ============================================================ */
    public function index(Request $request)
    {
        $role = session('user_role');
        if (! in_array($role, ['admin', 'team_manager'], true)) {
            abort(403, 'Only managers can view attendance.');
        }
        [$workScope, $visibleAgentIds] = $this->attendanceScope($request);

        $view = $request->input('view', 'daily');
        $view = in_array($view, ['daily', 'monthly'], true) ? $view : 'daily';

        $payrollAgents = Agent::with('user')
            ->whereHas('user', fn ($q) => $q->where('is_on_payroll', 1))
            ->whereIn('id', $visibleAgentIds)
            ->get()
            ->sortBy(fn ($a) => $a->user?->name ?? '')
            ->values();

        $today = now()->toDateString();
        $selectedDate = $request->input('date', $today);
        try {
            $selectedDate = Carbon::parse($selectedDate)->toDateString();
        } catch (\Throwable $e) {
            $selectedDate = $today;
        }

        $selectedMonth = $request->input('month', now()->format('Y-m'));
        try {
            $month = Carbon::createFromFormat('Y-m', $selectedMonth)->startOfMonth();
        } catch (\Throwable $e) {
            $month = now()->startOfMonth();
        }
        $selectedMonth = $month->format('Y-m');

        $office = OfficeLocation::active()->first();

        $dailyAttendance = Attendance::where('shift_date', $selectedDate)
            ->whereIn('agent_id', $visibleAgentIds)
            ->get()
            ->keyBy('agent_id');

        $monthStart = $month->copy()->startOfMonth();
        $monthEnd = $month->copy()->endOfMonth();
        $reportEnd = $month->isSameMonth(now()) ? now()->startOfDay() : $monthEnd;
        if ($reportEnd->lt($monthStart)) {
            $reportEnd = $monthStart->copy()->subDay();
        }

        $monthlyAttendance = Attendance::whereBetween('shift_date', [
                $monthStart->toDateString(),
                $monthEnd->toDateString(),
            ])
            ->whereIn('agent_id', $visibleAgentIds)
            ->get()
            ->groupBy('agent_id');

        $dailyStats = [
            'total' => $payrollAgents->count(),
            'present' => $payrollAgents->filter(fn ($agent) => ($att = $dailyAttendance->get($agent->id)) && $att->isCheckedIn())->count(),
            'checked_out' => $payrollAgents->filter(fn ($agent) => ($att = $dailyAttendance->get($agent->id)) && $att->isCheckedOut())->count(),
        ];
        $dailyStats['absent'] = max(0, $dailyStats['total'] - $dailyStats['present']);
        $dailyStats['open'] = max(0, $dailyStats['present'] - $dailyStats['checked_out']);

        $calendarDays = $reportEnd->lt($monthStart) ? 0 : $monthStart->diffInDays($reportEnd) + 1;
        $monthlyRows = $payrollAgents->map(function ($agent) use ($monthlyAttendance, $calendarDays) {
            $records = $monthlyAttendance->get($agent->id, collect());
            $present = $records->filter(fn ($att) => $att->isCheckedIn())->count();
            $checkedOut = $records->filter(fn ($att) => $att->isCheckedOut())->count();
            $manual = $records->filter(fn ($att) => $att->override_by_user_id !== null)->count();
            $minutes = $records->sum(function ($att) {
                if (! $att->checked_in_at) return 0;
                $end = $att->checked_out_at ?? now();
                return $att->checked_in_at->diffInMinutes($end);
            });

            return (object) [
                'agent' => $agent,
                'records' => $records,
                'present' => $present,
                'absent' => max(0, $calendarDays - $present),
                'open' => max(0, $present - $checkedOut),
                'manual' => $manual,
                'minutes' => $minutes,
                'attendance_percent' => $calendarDays > 0 ? round(($present / $calendarDays) * 100, 1) : 0,
            ];
        });

        return view('attendance.index', [
            'agents' => $payrollAgents,
            'attendanceByAgent' => $dailyAttendance,
            'today' => $today,
            'selectedDate' => $selectedDate,
            'selectedMonth' => $selectedMonth,
            'view' => $view,
            'workScope' => $workScope,
            'office' => $office,
            'dailyStats' => $dailyStats,
            'monthStart' => $monthStart,
            'monthEnd' => $monthEnd,
            'calendarDays' => $calendarDays,
            'monthlyRows' => $monthlyRows,
        ]);
    }

    /* ============================================================
       CSV EXPORT — payroll attendance only
       ============================================================ */
    public function export(Request $request)
    {
        $role = session('user_role');
        if (! in_array($role, ['admin', 'team_manager'], true)) {
            abort(403, 'Only managers can export attendance.');
        }
        [$workScope, $visibleAgentIds] = $this->attendanceScope($request);

        $type = $request->input('type', 'daily');
        if ($type === 'monthly') {
            try {
                $month = Carbon::createFromFormat('Y-m', $request->input('month', now()->format('Y-m')))->startOfMonth();
            } catch (\Throwable $e) {
                $month = now()->startOfMonth();
            }
            $start = $month->copy()->startOfMonth();
            $end = $month->copy()->endOfMonth();
            $reportEnd = $month->isSameMonth(now()) ? now()->startOfDay() : $end;
            $calendarDays = $reportEnd->lt($start) ? 0 : $start->diffInDays($reportEnd) + 1;

            $agents = Agent::with('user')
                ->whereIn('id', $visibleAgentIds)
                ->whereHas('user', fn ($q) => $q->where('is_on_payroll', 1))
                ->get()->sortBy(fn ($a) => $a->user?->name ?? '')->values();
            $attendance = Attendance::whereBetween('shift_date', [$start->toDateString(), $end->toDateString()])
                ->whereIn('agent_id', $visibleAgentIds)
                ->get()
                ->groupBy('agent_id');

            return response()->streamDownload(function () use ($agents, $attendance, $calendarDays, $month) {
                $out = fopen('php://output', 'w');
                fputcsv($out, ['Month', 'Employee', 'Email', 'Days in Period', 'Present Days', 'Absent Days', 'Open Shifts', 'Manual Days', 'Attendance %', 'Worked Hours']);
                foreach ($agents as $agent) {
                    $records = $attendance->get($agent->id, collect());
                    $present = $records->filter(fn ($att) => $att->isCheckedIn())->count();
                    $checkedOut = $records->filter(fn ($att) => $att->isCheckedOut())->count();
                    $manual = $records->filter(fn ($att) => $att->override_by_user_id !== null)->count();
                    $minutes = $records->sum(function ($att) {
                        if (! $att->checked_in_at) return 0;
                        return $att->checked_in_at->diffInMinutes($att->checked_out_at ?? now());
                    });
                    fputcsv($out, [
                        $month->format('Y-m'),
                        $agent->user?->name ?? '',
                        $agent->user?->email ?? '',
                        $calendarDays,
                        $present,
                        max(0, $calendarDays - $present),
                        max(0, $present - $checkedOut),
                        $manual,
                        $calendarDays > 0 ? round(($present / $calendarDays) * 100, 1) : 0,
                        sprintf('%d:%02d', intdiv($minutes, 60), $minutes % 60),
                    ]);
                }
                fclose($out);
            }, 'payroll-attendance-' . $month->format('Y-m') . '.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
        }

        try {
            $date = Carbon::parse($request->input('date', now()->toDateString()))->toDateString();
        } catch (\Throwable $e) {
            $date = now()->toDateString();
        }
        $agents = Agent::with('user')
            ->whereIn('id', $visibleAgentIds)
            ->whereHas('user', fn ($q) => $q->where('is_on_payroll', 1))
            ->get()->sortBy(fn ($a) => $a->user?->name ?? '')->values();
        $attendance = Attendance::where('shift_date', $date)
            ->whereIn('agent_id', $visibleAgentIds)
            ->get()
            ->keyBy('agent_id');

        return response()->streamDownload(function () use ($agents, $attendance, $date) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Date', 'Employee', 'Email', 'Status', 'Check-in', 'Check-out', 'Worked Hours', 'Check-in Distance (m)', 'Manual']);
            foreach ($agents as $agent) {
                $att = $attendance->get($agent->id);
                $status = ! $att ? 'Absent' : ($att->isCheckedOut() ? 'Present - Checked Out' : ($att->isCheckedIn() ? 'Present - Open' : 'Absent'));
                $minutes = $att && $att->checked_in_at ? $att->checked_in_at->diffInMinutes($att->checked_out_at ?? now()) : 0;
                fputcsv($out, [
                    $date,
                    $agent->user?->name ?? '',
                    $agent->user?->email ?? '',
                    $status,
                    $att?->checked_in_at?->format('H:i:s') ?? '',
                    $att?->checked_out_at?->format('H:i:s') ?? '',
                    $att && $att->checked_in_at ? sprintf('%d:%02d', intdiv($minutes, 60), $minutes % 60) : '',
                    $att?->checked_in_distance_m ?? '',
                    $att && $att->override_by_user_id ? 'Yes' : 'No',
                ]);
            }
            fclose($out);
        }, 'payroll-attendance-' . $date . '.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /* ============================================================
       MANUAL CHECK-IN — bypass radius with a reason
       ============================================================ */
    public function manualCheckIn(Request $request)
    {
        $role = session('user_role');
        if (! in_array($role, ['admin', 'team_manager'], true)) {
            return back()->withErrors(['attendance' => 'Only managers can do manual check-in.']);
        }

        $validated = $request->validate([
            'agent_id' => 'required|integer|exists:agents,id',
            'reason'   => 'required|string|min:3|max:500',
            'lat'      => 'nullable|numeric|between:-90,90',
            'lng'      => 'nullable|numeric|between:-180,180',
        ]);

        $visibleAgentIds = $this->access->visibleAgentIds();
        if (! in_array((int) $validated['agent_id'], $visibleAgentIds, true)) {
            return back()->withErrors(['attendance' => 'You are not authorized to manage attendance for this employee.']);
        }
        $agent = Agent::with('user')->findOrFail($validated['agent_id']);

        if (! $agent->user?->is_on_payroll) {
            return back()->withErrors(['attendance' => 'Manual attendance is only available for employees marked On payroll.']);
        }

        // Already checked in today?
        $existing = Attendance::where('agent_id', $agent->id)
            ->where('shift_date', now()->toDateString())
            ->first();

        if ($existing && $existing->isCheckedIn()) {
            return back()->with('info', 'Agent is already checked in today.');
        }

        // Resolve coordinates: custom lat/lng, else office, else 0
        $lat = $validated['lat'] ?? null;
        $lng = $validated['lng'] ?? null;

        if ($lat === null || $lng === null) {
            $office = OfficeLocation::active()->first();
            $lat = $office?->latitude ?? 0;
            $lng = $office?->longitude ?? 0;
        }

        Attendance::updateOrCreate(
            ['agent_id' => $agent->id, 'shift_date' => now()->toDateString()],
            [
                'checked_in_at'         => now(),
                'checked_in_lat'        => $lat,
                'checked_in_lng'        => $lng,
                'checked_in_accuracy_m' => null,
                'checked_in_distance_m' => null,
                'checked_in_ip'         => $request->ip(),
                'override_by_user_id'   => session('user_id'),
                'override_reason'       => 'Manual check-in: ' . $validated['reason'],
            ]
        );

        return back()->with('success', '✅ ' . ($agent->user?->name ?? 'Agent') . ' manually checked in.');
    }

    /* ============================================================
       CHECK OUT — allowed from anywhere
       ============================================================ */
    public function checkOut(Request $request)
    {
        $user = User::find(session('user_id'));

        if (! $user || ! $user->requiresAttendance()) {
            return back()->with('info', 'Attendance is not required for your role.');
        }

        $agent = Agent::where('user_id', $user->id)->first();
        if (! $agent) {
            return back()->withErrors(['attendance' => 'You have no agent record.']);
        }

        $today = Attendance::where('agent_id', $agent->id)
            ->where('shift_date', now()->toDateString())
            ->first();

        if (! $today || ! $today->isCheckedIn()) {
            return back()->withErrors(['attendance' => 'You have not checked in today.']);
        }

        if ($today->isCheckedOut()) {
            return back()->with('info', 'You are already checked out.');
        }

        // Count tasks for the record
        $tasksCompleted = Followup::where('agent_id', $agent->id)
            ->where('status', 'done')
            ->whereDate('updated_at', now()->toDateString())
            ->count();

        $tasksOverdue = Followup::where('agent_id', $agent->id)
            ->where('status', 'pending')
            ->where('scheduled_for', '<', now())
            ->count();

        // Block checkout if overdue tasks remain (unless override)
        $forceOverride = $request->boolean('force');
        if ($tasksOverdue > 0 && ! $forceOverride) {
            return back()->withErrors([
                'attendance' => sprintf(
                    '%d overdue tasks remain. Complete them or ask a manager to override your checkout.',
                    $tasksOverdue
                ),
            ]);
        }

        $updates = [
            'checked_out_at'         => now(),
            'checked_out_lat'        => $request->input('lat'),
            'checked_out_lng'        => $request->input('lng'),
            'checked_out_accuracy_m' => $request->input('accuracy'),
            'checked_out_ip'         => $request->ip(),
            'tasks_completed'        => $tasksCompleted,
            'tasks_overdue_at_exit'  => $tasksOverdue,
        ];

        if ($forceOverride && $tasksOverdue > 0) {
            $updates['override_by_user_id'] = $user->id;
            $updates['override_reason']     = $request->input('override_reason', 'Self check-out with pending tasks');
        }

        $today->update($updates);

        return redirect('/')->with(
            'success',
            '✅ Checked out · ' . $today->fresh()->durationLabel() . ' worked'
        );
    }

    /* ============================================================
       MANAGER OVERRIDE — check out someone with a reason
       ============================================================ */
    public function forceCheckOut(Request $request)
    {
        $role = session('user_role');
        if (! in_array($role, ['admin', 'team_manager'], true)) {
            abort(403, 'Only managers can override.');
        }

        $validated = $request->validate([
            'attendance_id'   => 'required|integer|exists:attendances,id',
            'override_reason' => 'required|string|max:500',
        ]);

        $attendance = Attendance::findOrFail($validated['attendance_id']);
        $visibleAgentIds = $this->access->visibleAgentIds();
        if (! in_array((int) $attendance->agent_id, $visibleAgentIds, true)) {
            abort(403, 'You are not authorized to manage attendance for this employee.');
        }

        if ($attendance->isCheckedOut()) {
            return back()->with('info', 'Already checked out.');
        }

        $attendance->update([
            'checked_out_at'        => now(),
            'override_by_user_id'   => session('user_id'),
            'override_reason'       => $validated['override_reason'],
        ]);

        return back()->with('success', '✅ Forced checkout recorded.');
    }

    /* ============================================================
       Haversine distance in meters
       ============================================================ */
    private function haversineMeters(float $lat1, float $lng1, float $lat2, float $lng2): int
    {
        $R    = 6371000;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a    = sin($dLat / 2) ** 2
              + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
        return (int) round($R * 2 * atan2(sqrt($a), sqrt(1 - $a)));
    }
}