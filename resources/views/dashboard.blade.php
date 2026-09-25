@php
    $isSuperAdminDashboard =
        app(\App\Services\SuperAdminService::class)->isSuperAdmin();
@endphp

@if ($isSuperAdminDashboard)
    @include('dashboard-super-admin')
@else
    @include('dashboard-live-operational')
@endif
