@extends('layouts.app')

@section('content')
<div class="container" style="max-width:1200px;margin:24px auto;padding:0 16px;">
    <h1>Contact Duplicate Audit</h1>
    <p class="muted">Duplicate groups are based on the CRM's normalized phone number. This tool does not treat different projects or callers as duplicates. It only merges separate Contact records sharing the same normalized phone.</p>

    @if(session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
    @endif
    @if($errors->any())
        <div class="alert alert-danger">{{ $errors->first() }}</div>
    @endif

    @if($groups->isEmpty())
        <div class="card"><p>No duplicate Contact records were found.</p></div>
    @else
        @foreach($groups as $group)
            <div class="card" style="margin:16px 0;padding:16px;">
                <div style="display:flex;justify-content:space-between;gap:12px;align-items:center;flex-wrap:wrap;">
                    <div>
                        <strong>Phone: {{ $group['phone'] }}</strong>
                        <div class="muted">{{ $group['contacts']->count() }} Contact records · {{ $group['promoted_count'] }} promoted Lead(s)</div>
                    </div>
                    @if($group['safe'])
                        <form method="post" action="{{ route('admin.contactDuplicates.merge') }}" onsubmit="return confirm('Merge this duplicate Contact group? Calls and assignment history will be re-homed to the survivor.');">
                            @csrf
                            <input type="hidden" name="phone" value="{{ $group['phone'] }}">
                            <button type="submit">Merge duplicates</button>
                        </form>
                    @else
                        <strong>Manual review required</strong>
                    @endif
                </div>
                <table style="width:100%;margin-top:12px;border-collapse:collapse;">
                    <thead><tr><th>ID</th><th>Name</th><th>Status</th><th>Pitch Project</th><th>Caller</th><th>Promoted Lead</th></tr></thead>
                    <tbody>
                    @foreach($group['contacts'] as $contact)
                        <tr>
                            <td>{{ $contact->id }} @if($contact->id === $group['survivor']->id)<strong>(survivor)</strong>@endif</td>
                            <td>{{ $contact->name }}</td>
                            <td>{{ $contact->status }}</td>
                            <td>{{ optional($contact->project)->name ?? '—' }}</td>
                            <td>{{ optional(optional($contact->agent)->user)->name ?? '—' }}</td>
                            <td>{{ $contact->promoted_to_lead_id ? '#'.$contact->promoted_to_lead_id : '—' }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @endforeach
    @endif
</div>
@endsection
