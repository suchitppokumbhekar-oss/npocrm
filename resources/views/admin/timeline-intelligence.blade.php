@extends('layouts.app')

@section('content')
<style>@media(max-width:760px){.page-wrap .card > div[style*='grid-template-columns:1.1fr .8fr .8fr .9fr']{grid-template-columns:1fr!important;gap:8px!important;}}</style>
<div class="page-wrap" style="max-width:1250px;margin:0 auto;padding:18px 14px 40px;">
    <div style="display:flex;justify-content:space-between;gap:12px;align-items:flex-start;flex-wrap:wrap;margin-bottom:16px;">
        <div>
            <div class="eyebrow">SUPER ADMIN TEST</div>
            <h1 style="margin:4px 0 4px;">🧠 Lead Intelligence</h1>
            <p class="muted" style="margin:0;max-width:820px;">Customer understanding built from documented CRM data: what we know, what changed, what is still unknown, and what work is currently due. Signals are supporting evidence, not the intelligence itself.</p>
        </div>
        <form method="GET" style="display:flex;gap:8px;align-items:center;">
            <input name="q" value="{{ request('q') }}" placeholder="Search customer or phone" style="min-width:220px;">
            <button class="btn-small" type="submit">Search</button>
        </form>
    </div>

    <div class="card" style="padding:12px;margin-bottom:16px;background:var(--c-surface-2);">
        <strong>How this test thinks</strong>
        <span class="muted" style="margin-left:8px;">Timeline → documented facts → requirement history → information gaps → operational context. Every fact can be traced back to its source.</span>
    </div>

    @if ($rows->isEmpty())
        <div class="card" style="padding:28px;text-align:center;">No active leads matched the test.</div>
    @else
        <div style="display:grid;gap:10px;">
        @foreach ($rows as $row)
            @php($a = $row['analysis'])
            @php($snapshot = $a['snapshot'])
            <a href="{{ route('admin.timeline-intelligence.lead', $row['lead']->id) }}" class="card" style="display:block;text-decoration:none;color:inherit;padding:14px;">
                <div style="display:flex;justify-content:space-between;gap:12px;align-items:flex-start;">
                    <div>
                        <strong style="font-size:16px;">{{ $row['lead']->customer_name ?: 'Unnamed customer' }}</strong>
                        <div class="muted" style="font-size:12px;margin-top:3px;">Lead #{{ $row['lead']->id }} · {{ $row['lead']->project?->name ?: 'No project' }} · {{ $row['lead']->status_label }}</div>
                    </div>
                    @if ($row['urgent']) <span class="badge" style="background:#fff1f0;color:#a8071a;">ATTENTION</span> @endif
                </div>

                <div style="display:grid;grid-template-columns:1.1fr .8fr .8fr .9fr;gap:14px;margin-top:13px;">
                    <div>
                        <div class="muted" style="font-size:11px;text-transform:uppercase;">What we know</div>
                        @if (empty($snapshot))
                            <div class="muted" style="font-size:12px;margin-top:4px;">No explicit requirement facts yet.</div>
                        @else
                            @foreach (array_slice($snapshot, 0, 3) as $key => $fact)
                                <div style="margin-top:4px;font-size:12px;"><span class="muted">{{ ucfirst(str_replace('_',' ', $key)) }}:</span> <strong>{{ $fact['value'] }}</strong></div>
                            @endforeach
                        @endif
                    </div>
                    <div>
                        <div class="muted" style="font-size:11px;text-transform:uppercase;">What changed</div>
                        @if (!empty($a['changes']))
                            @foreach (array_slice($a['changes'], 0, 2) as $change)
                                <div style="margin-top:4px;font-size:12px;"><strong>{{ $change['label'] }}</strong> {{ $change['from'] }} → {{ $change['to'] }}</div>
                            @endforeach
                        @else
                            <div class="muted" style="font-size:12px;margin-top:4px;">No documented change detected.</div>
                        @endif
                    </div>
                    <div>
                        <div class="muted" style="font-size:11px;text-transform:uppercase;">Information readiness</div>
                        <div style="margin-top:4px;font-size:12px;">{{ str_replace('_',' ', $a['information_readiness']['summary']['readiness_state']) }}</div>
                        <div class="muted" style="font-size:11px;margin-top:3px;">{{ $a['information_readiness']['summary']['customer_provided'] }} provided · {{ $a['information_readiness']['summary']['needs_confirmation'] }} confirm · {{ $a['information_readiness']['summary']['unknown'] }} unknown</div>
                    </div>
                    <div>
                        <div class="muted" style="font-size:11px;text-transform:uppercase;">Still unknown</div>
                        @if (!empty($a['unknowns']))
                            <div style="margin-top:4px;font-size:12px;">{{ count($a['unknowns']) }} information gap{{ count($a['unknowns']) === 1 ? '' : 's' }}</div>
                            <div class="muted" style="font-size:11px;margin-top:3px;">{{ implode(' · ', array_slice(array_column($a['unknowns'], 'label'), 0, 2)) }}</div>
                        @else
                            <div style="margin-top:4px;font-size:12px;">No high-priority gap detected</div>
                        @endif
                    </div>
                </div>

                <div style="display:flex;justify-content:space-between;gap:12px;align-items:center;flex-wrap:wrap;margin-top:13px;padding-top:10px;border-top:1px solid var(--c-border);">
                    <div><span class="muted" style="font-size:11px;text-transform:uppercase;">Next action</span> <strong style="margin-left:5px;">{{ $a['next_action']['label'] }}</strong></div>
                    <div class="muted" style="font-size:11px;">{{ $a['metrics']['activities'] }} timeline events · {{ $a['metrics']['pending_tasks'] }} pending · {{ count($a['signals']) }} signals →</div>
                </div>
            </a>
        @endforeach
        </div>
        <div style="margin-top:14px;">@include('partials.pagination', ['paginator' => $leads])</div>
    @endif
</div>
@endsection
