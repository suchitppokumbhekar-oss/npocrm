@php
    $attendanceUser = \App\Models\User::find(session('user_id'));
    $requiresAttendance = $attendanceUser?->requiresAttendance() ?? false;

    $todayAttendance = null;
    if ($requiresAttendance) {
        $agent = \App\Models\Agent::where('user_id', $attendanceUser->id)->first();
        if ($agent) {
            $todayAttendance = \App\Models\Attendance::where('agent_id', $agent->id)
                ->where('shift_date', now()->toDateString())
                ->first();
        }
    }

    $isCheckedIn  = $todayAttendance?->isCheckedIn() ?? false;
    $isCheckedOut = $todayAttendance?->isCheckedOut() ?? false;
@endphp

@if ($requiresAttendance)
    <div class="attendance-banner
        {{ $isCheckedOut ? 'is-done' : ($isCheckedIn ? 'is-in' : 'is-out') }}">

        @if ($isCheckedOut)
            <div class="ab-left">
                <span class="ab-icon">✅</span>
                <div>
                    <div class="ab-title">Shift complete</div>
                    <div class="ab-sub">
                        In at {{ $todayAttendance->checked_in_at->format('H:i') }}
                        · Out at {{ $todayAttendance->checked_out_at->format('H:i') }}
                        · {{ $todayAttendance->durationLabel() }}
                    </div>
                </div>
            </div>

        @elseif ($isCheckedIn)
            <div class="ab-left ab-left-compact">
                <span class="ab-icon">✓</span>
                <div class="ab-title">Checked in {{ $todayAttendance->checked_in_at->format('H:i') }} · {{ $todayAttendance->durationLabel() }}</div>
            </div>

            <form method="POST" action="/attendance/check-out" class="ab-form"
                  id="checkout-form">
                @csrf
                <input type="hidden" name="lat" id="co-lat">
                <input type="hidden" name="lng" id="co-lng">
                <input type="hidden" name="accuracy" id="co-accuracy">
                <button type="submit" class="ab-btn ab-btn-out" id="co-btn">
                    🔴 Check Out
                </button>
            </form>

        @else
            <div class="ab-left">
                <span class="ab-icon">🔴</span>
                <div>
                    <div class="ab-title">You are not checked in</div>
                    <div class="ab-sub">Check in to start your shift. Location is required.</div>
                </div>
            </div>

            <form method="POST" action="/attendance/check-in" class="ab-form"
                  id="checkin-form">
                @csrf
                <input type="hidden" name="lat" id="ci-lat">
                <input type="hidden" name="lng" id="ci-lng">
                <input type="hidden" name="accuracy" id="ci-accuracy">
                <button type="submit" class="ab-btn ab-btn-in" id="ci-btn">
                    🟢 Check In
                </button>
            </form>
        @endif
    </div>


    @push('scripts')
    <script>
    (function () {
        function attachGeo(formId, btnId, latId, lngId, accId) {
            var btn = document.getElementById(btnId);
            var form = document.getElementById(formId);
            if (!btn || !form) return;

            btn.addEventListener('click', function (e) {
                e.preventDefault();

                if (!navigator.geolocation) {
                    alert('Your browser does not support location. Please use a mobile device.');
                    return;
                }

                var original = btn.textContent;
                btn.disabled = true;
                btn.textContent = '📍 Getting location…';

                navigator.geolocation.getCurrentPosition(
                    function (pos) {
                        document.getElementById(latId).value = pos.coords.latitude;
                        document.getElementById(lngId).value = pos.coords.longitude;
                        document.getElementById(accId).value = pos.coords.accuracy;
                        form.submit();
                    },
                    function (err) {
                        btn.disabled = false;
                        btn.textContent = original;
                        var msg = 'Location required. ';
                        if (err.code === 1) msg += 'Please allow location access in your browser.';
                        else if (err.code === 2) msg += 'Location unavailable. Try moving to an open area.';
                        else if (err.code === 3) msg += 'Location request timed out. Try again.';
                        alert(msg);
                    },
                    { enableHighAccuracy: true, timeout: 15000, maximumAge: 0 }
                );
            });
        }

        attachGeo('checkin-form',  'ci-btn', 'ci-lat', 'ci-lng', 'ci-accuracy');
        attachGeo('checkout-form', 'co-btn', 'co-lat', 'co-lng', 'co-accuracy');
    })();
    </script>
    @endpush
@endif