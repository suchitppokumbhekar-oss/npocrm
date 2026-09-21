@extends('layouts.app')

@section('content')
<div style="max-width:1200px;margin:0 auto;padding:24px 18px 48px;">
    <div style="margin-bottom:20px;">
        <div style="font-size:12px;color:#6b7280;margin-bottom:5px;">SUPER ADMIN</div>
        <h1 style="margin:0;font-size:26px;">Delegated Access</h1>
        <p style="margin:7px 0 0;color:#6b7280;max-width:820px;line-height:1.5;">Control exactly what an Admin or Team Manager can see and do. Delegated access never grants Super Admin authority.</p>
    </div>

    @if(session('success'))<div style="padding:12px 14px;border:1px solid #bbf7d0;background:#f0fdf4;border-radius:10px;margin-bottom:16px;">{{ session('success') }}</div>@endif
    @if(session('error'))<div style="padding:12px 14px;border:1px solid #fecaca;background:#fef2f2;border-radius:10px;margin-bottom:16px;">{{ session('error') }}</div>@endif
    @if($errors->any())<div style="padding:12px 14px;border:1px solid #fecaca;background:#fef2f2;border-radius:10px;margin-bottom:16px;"><strong>Please correct:</strong><ul style="margin:7px 0 0 18px;">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif

    <div style="display:grid;grid-template-columns:minmax(0,1.3fr) minmax(300px,.7fr);gap:18px;align-items:start;">
        <section style="border:1px solid #e5e7eb;border-radius:14px;padding:18px;background:#fff;">
            <h2 style="margin:0 0 5px;font-size:18px;">{{ $editing ? 'Edit delegation' : 'Create delegation' }}</h2>
            <p style="margin:0 0 18px;color:#6b7280;font-size:13px;">Choose the person, scope and permissions. Saving replaces the selected profile's current configuration.</p>
            <form method="POST" action="{{ $editing ? route('settings.delegated-access.save',$editing->id) : route('settings.delegated-access.save') }}">
                @csrf
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;">
                    <label><span style="display:block;font-size:12px;font-weight:700;margin-bottom:6px;">Admin / Team Manager</span>
                        <select name="user_id" required style="width:100%;padding:10px;border:1px solid #d1d5db;border-radius:9px;background:#fff;">
                            <option value="">Select user…</option>
                            @foreach($users as $user)
                                <option value="{{ $user->id }}" @selected(old('user_id', $editing?->user_id) == $user->id)>{{ $user->name }} — {{ $user->email }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label><span style="display:block;font-size:12px;font-weight:700;margin-bottom:6px;">Profile name</span>
                        <input name="name" value="{{ old('name', $editing?->name) }}" required maxlength="150" placeholder="e.g. Mumbai Sales Oversight" style="width:100%;padding:10px;border:1px solid #d1d5db;border-radius:9px;box-sizing:border-box;">
                    </label>
                </div>

                @php
                    $selectedTeams = old('team_ids', $editing ? $editing->teams->pluck('id')->all() : []);
                    $selectedIncludes = old('include_user_ids', $editing ? $editing->userRules->where('access_type','include')->pluck('user_id')->all() : []);
                    $selectedExcludes = old('exclude_user_ids', $editing ? $editing->userRules->where('access_type','exclude')->pluck('user_id')->all() : []);
                    $selectedPermissions = old('permissions', $editing ? $editing->permissions->pluck('permission_key')->all() : []);
                @endphp

                <div style="margin-top:20px;">
                    <div style="font-weight:700;font-size:14px;margin-bottom:5px;">Teams in scope</div>
                    <div style="font-size:12px;color:#6b7280;margin-bottom:9px;">Active members of selected teams become visible within the delegated scope.</div>
                    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:8px;">
                        @foreach($teams as $team)
                            <label style="display:flex;gap:8px;align-items:center;padding:9px;border:1px solid #e5e7eb;border-radius:9px;"><input type="checkbox" name="team_ids[]" value="{{ $team->id }}" @checked(in_array($team->id,$selectedTeams))><span>{{ $team->name }}</span></label>
                        @endforeach
                    </div>
                </div>

                <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-top:20px;">
                    <div><div style="font-weight:700;font-size:14px;margin-bottom:5px;">Explicitly include users</div><div style="font-size:12px;color:#6b7280;margin-bottom:8px;">Adds users even if their team is not selected.</div>
                        <select name="include_user_ids[]" multiple size="9" style="width:100%;padding:7px;border:1px solid #d1d5db;border-radius:9px;">
                            @foreach($selectableUsers as $user)<option value="{{ $user->id }}" @selected(in_array($user->id,$selectedIncludes))>{{ $user->name }} — {{ $user->email }}</option>@endforeach
                        </select>
                    </div>
                    <div><div style="font-weight:700;font-size:14px;margin-bottom:5px;">Explicitly exclude users</div><div style="font-size:12px;color:#6b7280;margin-bottom:8px;">Exclusion wins over team membership.</div>
                        <select name="exclude_user_ids[]" multiple size="9" style="width:100%;padding:7px;border:1px solid #d1d5db;border-radius:9px;">
                            @foreach($selectableUsers as $user)<option value="{{ $user->id }}" @selected(in_array($user->id,$selectedExcludes))>{{ $user->name }} — {{ $user->email }}</option>@endforeach
                        </select>
                    </div>
                </div>

                <div style="margin-top:20px;"><div style="font-weight:700;font-size:14px;margin-bottom:5px;">Permissions</div><div style="font-size:12px;color:#6b7280;margin-bottom:9px;">Permissions control actions; scope controls where those actions apply.</div>
                    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:8px;">
                        @foreach($permissions as $permission)<label style="display:flex;gap:8px;align-items:center;padding:9px;border:1px solid #e5e7eb;border-radius:9px;"><input type="checkbox" name="permissions[]" value="{{ $permission }}" @checked(in_array($permission,$selectedPermissions))><span style="font-size:13px;">{{ $permission }}</span></label>@endforeach
                    </div>
                </div>

                <div style="margin-top:20px;display:flex;gap:10px;align-items:center;flex-wrap:wrap;"><button type="submit" style="border:0;border-radius:9px;padding:11px 16px;background:#111827;color:#fff;font-weight:700;cursor:pointer;">{{ $editing ? 'Save Changes' : 'Create & Activate' }}</button><span style="font-size:12px;color:#6b7280;">No Super Admin account can be selected here.</span></div>
            </form>
        </section>

        <section style="border:1px solid #e5e7eb;border-radius:14px;padding:18px;background:#fff;">
            <h2 style="margin:0 0 5px;font-size:18px;">Existing profiles</h2>
            <p style="margin:0 0 14px;color:#6b7280;font-size:13px;">Deactivate immediately removes delegated access while preserving the configuration.</p>
            @forelse($profiles as $profile)
                <div style="border-top:1px solid #e5e7eb;padding:13px 0;">
                    <div style="display:flex;justify-content:space-between;gap:10px;align-items:start;"><div><strong>{{ $profile->user->name }}</strong><div style="font-size:12px;color:#6b7280;">{{ $profile->user->email }}</div><div style="font-size:12px;margin-top:5px;">{{ $profile->name }}</div></div><span style="font-size:11px;padding:4px 7px;border-radius:999px;background:{{ $profile->is_active ? '#dcfce7' : '#f3f4f6' }};color:{{ $profile->is_active ? '#166534' : '#6b7280' }};">{{ $profile->is_active ? 'ACTIVE' : 'INACTIVE' }}</span></div>
                    <div style="font-size:11px;color:#6b7280;margin-top:7px;">{{ $profile->teams->count() }} teams · {{ $profile->userRules->where('access_type','include')->count() }} includes · {{ $profile->userRules->where('access_type','exclude')->count() }} excludes · {{ $profile->permissions->count() }} permissions</div>
                    <div style="display:flex;gap:8px;margin-top:10px;"><a href="{{ route('settings.delegated-access',$profile->id) }}" style="padding:7px 10px;border:1px solid #d1d5db;background:#fff;border-radius:8px;text-decoration:none;color:inherit;">Edit</a><form method="POST" action="{{ route('settings.delegated-access.toggle',$profile->id) }}" style="margin:0;">@csrf<button type="submit" style="padding:7px 10px;border:1px solid #d1d5db;background:#fff;border-radius:8px;cursor:pointer;">{{ $profile->is_active ? 'Deactivate' : 'Activate' }}</button></form></div>
                </div>
            @empty
                <div style="padding:20px 0;color:#6b7280;font-size:13px;">No delegated profiles exist yet.</div>
            @endforelse
        </section>
    </div>
</div>
@endsection
