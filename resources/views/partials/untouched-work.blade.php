@if (($untouchedLeadCount ?? 0) > 0)
<details id="untouched-work" class="dash-section dash-untouched" open>
    <summary class="dash-section-head">
        <span class="dsh-arrow">▸</span>
        <span class="dsh-icon">🔥</span>
        <span class="dsh-title">NEW LEADS — WORK NOW</span>
        <span class="dsh-count">{{ $untouchedLeadCount }}</span>
    </summary>

    <div class="dash-section-body">
        @foreach ($untouchedLeads as $lead)
            @php
                $firstTask = $lead->followups->first();

                $workUrl = $firstTask
                    ? url('/leads/' . $lead->id) . '?' . http_build_query([
                        'focus_work' => 1,
                        'focus_followup_id' => $firstTask->id,
                        'return_to' => '/#untouched-work',
                    ]) . '#pending-tasks'
                    : url('/leads/' . $lead->id) . '?' . http_build_query([
                        'return_to' => '/#untouched-work',
                    ]) . '#pending-tasks';
            @endphp

            <a class="untouched-work-row" href="{{ $workUrl }}">
                <div class="untouched-work-info">
                    <strong>{{ $lead->customer_name }}</strong>
                    <span>
                        🏗️ {{ $lead->project?->name ?? 'No project' }}
                        · 📣 {{ $lead->source ?: ($lead->intake_source ?: 'Source not specified') }}
                    </span>
                    @if ($lead->budget)
                        <span>💰 ₹{{ number_format((float) $lead->budget) }}</span>
                    @endif
                    @if ($lead->origin_note)
                        <span class="untouched-work-note">📝 {{ $lead->origin_note }}</span>
                    @endif
                </div>

                <div class="untouched-work-action">
                    <small>⏱ {{ $lead->created_at?->diffForHumans() }}</small>
                    <b>WORK →</b>
                </div>
            </a>
        @endforeach
    </div>
</details>

<style>
.dash-untouched{
    border-color:#f59e0b;
    border-left:5px solid #ea580c;
    background:#fffaf0;
}
.dash-untouched>.dash-section-head{
    background:#fff3d6;
    color:#7c2d12;
}
.dash-untouched .dsh-count{
    background:#ea580c;
    color:#fff;
}
.untouched-work-row{
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:10px;
    padding:11px;
    border-bottom:1px solid var(--c-border);
    background:var(--c-surface);
    color:var(--c-text);
    text-decoration:none;
}
.untouched-work-row:last-child{border-bottom:0}
.untouched-work-info{
    display:flex;
    flex:1;
    min-width:0;
    flex-direction:column;
    gap:3px;
    font-size:12px;
}
.untouched-work-info>strong{font-size:14px}
.untouched-work-note{
    overflow:hidden;
    text-overflow:ellipsis;
    white-space:nowrap;
    color:var(--c-text-2);
}
.untouched-work-action{
    display:flex;
    flex:0 0 auto;
    flex-direction:column;
    align-items:flex-end;
    gap:5px;
}
.untouched-work-action small{
    font-size:10px;
    color:var(--c-text-2);
}
.untouched-work-action b{
    padding:7px 9px;
    border-radius:8px;
    background:#ea580c;
    color:#fff;
    font-size:12px;
}
</style>
@endif
