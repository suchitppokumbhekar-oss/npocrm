@extends('layouts.app')

@section('title', 'Contact Assignment Reconciliation')
@section('content')
<a href="{{ url('/contacts') }}" class="back-link">← Back to contacts</a>

@if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif

<div class="card">
  <h2 style="margin:0 0 6px;">🔎 Contact Assignment Reconciliation</h2>
  <p class="muted" style="font-size:13px;margin:0;">Only active Contact → Pitch Project → Caller assignments where the caller is no longer eligible for that project are shown. Clearing an invalid assignment preserves the Contact, calls, import history and completed assignment history.</p>
</div>

<form method="POST" action="{{ route('admin.contactAssignmentReconcile') }}">
 @csrf
 <input type="hidden" name="mode" value="clear">
 <div class="card">
  <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;">
   <strong>{{ count($invalid) }} invalid active assignment{{ count($invalid)===1?'':'s' }}</strong>
   @if($invalid)<button class="btn-small btn-warning" type="submit" onclick="return confirm('Clear the selected invalid active assignments? Contacts and call/import history will be preserved.')">Clear selected invalid assignments</button>@endif
  </div>
  @if(!$invalid)
    <p class="muted" style="margin-top:12px;">No invalid active Contact assignments found.</p>
  @else
  <div class="table-wrap" style="margin-top:12px;"><table>
   <tr><th><input type="checkbox" id="all-invalid"></th><th>Contact</th><th>Pitch Project</th><th>Current Caller</th><th>Assigned</th><th>Reason</th></tr>
   @foreach($invalid as $a)
   <tr>
    <td><input type="checkbox" name="assignment_ids[]" value="{{ $a->id }}" class="invalid-check"></td>
    <td><a href="{{ url('/contacts/'.$a->contact_id) }}">{{ $a->contact?->name ?? 'Contact #'.$a->contact_id }}</a></td>
    <td>{{ $a->project?->name ?? '—' }}</td>
    <td>{{ $a->agent?->user?->name ?? '—' }}</td>
    <td>{{ $a->assigned_at?->format('d M Y, H:i') ?? '—' }}</td>
    <td class="muted">Caller is not currently eligible for this project.</td>
   </tr>
   @endforeach
  </table></div>
  @endif
 </div>
</form>
<script>document.getElementById('all-invalid')?.addEventListener('change',function(){document.querySelectorAll('.invalid-check').forEach(function(x){x.checked=this.checked},this)});</script>
@endsection
