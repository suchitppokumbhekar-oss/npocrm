@if ($contact->calls->isEmpty())
    <p class="muted">No calls logged yet.</p>
@else
    <div class="table-wrap"><table>
        <tr><th>When</th><th>Agent</th><th>Outcome</th><th>Connected</th><th>Duration</th><th>Notes</th></tr>
        @foreach ($contact->calls->sortByDesc('called_at') as $call)
            <tr>
                <td class="muted" style="font-size:12px;">{{ $call->called_at?->format('d M Y, H:i') }}</td>
                <td>{{ $call->agent?->user?->name ?? '—' }}</td>
                <td>{{ ucfirst(str_replace('_',' ', $call->outcome_key ?? '—')) }}</td>
                <td>@if ($call->connected)<span class="badge green">Yes</span>@else<span class="badge red">No</span>@endif</td>
                <td>{{ $call->duration_label }}</td>
                <td class="muted" style="font-size:12px;">{{ \Illuminate\Support\Str::limit($call->notes, 80) ?? '—' }}</td>
            </tr>
        @endforeach
    </table></div>
@endif
