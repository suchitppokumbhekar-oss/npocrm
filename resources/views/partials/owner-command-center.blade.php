@if (($isSuperAdmin ?? false) && !empty($ownerCommandCenter))
@php
    $occ = $ownerCommandCenter;
    $occCounts = $occ['counts'] ?? [];
    $critical = (int) ($occCounts['critical'] ?? 0);
    $attention = (int) ($occCounts['attention'] ?? 0);
    $untouched = (int) ($occCounts['untouched'] ?? 0);
    $followups = (int) ($occCounts['followups'] ?? 0);
    $unassigned = (int) ($occCounts['unassigned'] ?? 0);
    $workforce = (int) ($occCounts['workforce'] ?? 0);
    $ownerRows = collect([
        $occ['lead_attention']['unassigned'] ?? [],
        $occ['lead_attention']['untouched'] ?? [],
        $occ['followup_attention'] ?? [],
        $occ['workforce_attention'] ?? [],
    ])->flatMap(fn ($group) => collect($group)
        ->sortByDesc(fn ($row) => ($row['severity'] ?? '') === 'critical' ? 2 : 1)
        ->take(3))
        ->sortByDesc(fn ($row) => ($row['severity'] ?? '') === 'critical' ? 2 : 1)
        ->take(12)
        ->values();
@endphp

<section class="owner-command-center" aria-label="Owner Command Center">
    <div class="owner-command-head">
        <div>
            <div class="owner-command-kicker">👑 SUPER ADMIN · OWNER COMMAND CENTER</div>
            <h2>What requires your attention now</h2>
            <p>Live CRM exceptions based on configured company rules. Counts are complete; the queue below is intentionally limited to the highest-priority items.</p>
        </div>
        <div class="owner-command-generated">Updated {{ $occ['generated_at']?->format('h:i A') ?? now()->format('h:i A') }}</div>
    </div>

    <div class="owner-command-stats">
        <div class="owner-stat owner-stat-critical"><span>Critical</span><strong>{{ number_format($critical) }}</strong><small>Act now</small></div>
        <div class="owner-stat owner-stat-attention"><span>Attention</span><strong>{{ number_format($attention) }}</strong><small>Needs review</small></div>
        <div class="owner-stat"><span>First contact breached</span><strong>{{ number_format($untouched) }}</strong><small>{{ $occ['thresholds']['first_contact_minutes'] ?? 15 }} min SLA</small></div>
        <div class="owner-stat"><span>Overdue follow-ups</span><strong>{{ number_format($followups) }}</strong><small>{{ $occ['thresholds']['followup_escalation_hours'] ?? 4 }}h escalation rule</small></div>
        <div class="owner-stat"><span>Unassigned leads</span><strong>{{ number_format($unassigned) }}</strong><small>{{ $occ['thresholds']['unassigned_minutes'] ?? 5 }} min SLA</small></div>
        <div class="owner-stat"><span>Workforce</span><strong>{{ number_format($workforce) }}</strong><small>Checked-in inactivity</small></div>
    </div>

    <div class="owner-command-queue">
        <div class="owner-command-queue-head"><strong>Priority intervention queue</strong><span>Showing {{ $ownerRows->count() }} actionable exception{{ $ownerRows->count() === 1 ? '' : 's' }}</span></div>
        @forelse ($ownerRows as $row)
            @php
                $isCritical = ($row['severity'] ?? '') === 'critical';
                $ageMinutes = (int) ($row['age_working_minutes'] ?? 0);
                $ageLabel = $ageMinutes >= 60 ? floor($ageMinutes / 60) . 'h ' . ($ageMinutes % 60) . 'm working time' : $ageMinutes . 'm working time';
            @endphp
            <div class="owner-command-row">
                <span class="owner-severity {{ $isCritical ? 'critical' : 'attention' }}">{{ $isCritical ? 'CRITICAL' : 'ATTENTION' }}</span>
                <div class="owner-command-main">
                    <strong>{{ $row['lead_name'] ?? $row['responsible_name'] ?? 'CRM exception' }}</strong>
                    <div>{{ $row['reason'] ?? 'Requires owner review.' }}</div>
                    <small>
                        @if (!empty($row['project'])){{ $row['project'] }} · @endif
                        @if (!empty($row['responsible_name']))Responsible: {{ $row['responsible_name'] }} · @endif
                        {{ $ageLabel }}
                    </small>
                </div>
                @php
                    $nudgeType = !empty($row['followup_id'])
                        ? 'followup'
                        : (!empty($row['lead_id']) && !empty($row['responsible_agent_id'])
                            ? 'lead_attention'
                            : null);
                    $nudgeId = $nudgeType === 'followup'
                        ? ($row['followup_id'] ?? null)
                        : ($row['lead_id'] ?? null);
                @endphp
                <div class="owner-command-actions">
                    @if (!empty($row['url']))
                        <a class="owner-command-open" href="{{ url($row['url']) }}">Open Lead →</a>
                    @endif
                    @if ($nudgeType && $nudgeId)
                        <button type="button" class="owner-command-whatsapp" onclick="window.NpoWhatsApp && window.NpoWhatsApp.chooseNudge('{{ $nudgeType }}','{{ $nudgeId }}')"><svg class="owner-whatsapp-icon" viewBox="0 0 32 32" aria-hidden="true"><path fill="currentColor" d="M16.04 3A12.9 12.9 0 0 0 5.1 22.75L3 29l6.46-2.03A12.98 12.98 0 1 0 16.04 3Zm0 23.6a10.7 10.7 0 0 1-5.45-1.49l-.39-.23-3.83 1.2 1.25-3.72-.25-.39A10.67 10.67 0 1 1 16.04 26.6Zm5.86-8.02c-.32-.16-1.9-.94-2.2-1.05-.29-.11-.5-.16-.71.16-.21.32-.82 1.05-1 1.26-.19.21-.37.24-.69.08-.32-.16-1.35-.5-2.57-1.59-.95-.85-1.59-1.89-1.78-2.21-.18-.32-.02-.49.14-.65.14-.14.32-.37.48-.56.16-.18.21-.32.32-.53.11-.21.05-.4-.03-.56-.08-.16-.71-1.72-.98-2.35-.26-.62-.52-.54-.71-.55h-.61c-.21 0-.56.08-.85.4-.29.32-1.11 1.08-1.11 2.64 0 1.56 1.14 3.06 1.3 3.27.16.21 2.24 3.42 5.42 4.79.76.33 1.35.52 1.81.67.76.24 1.45.21 2 .13.61-.09 1.9-.78 2.17-1.53.27-.75.27-1.4.19-1.53-.08-.14-.29-.22-.61-.38Z"/></svg><span>WhatsApp Responsible</span></button>
                    @endif
                    @if (!empty($row['responsible_phone']))
                        <a class="owner-command-call" href="tel:{{ phone_tel($row['responsible_phone']) }}">📞 Call Responsible</a>
                    @endif
                </div>
            </div>
        @empty
            <div class="owner-command-empty">✅ No owner intervention is required right now.</div>
        @endforelse
    </div>
</section>

<style>
.owner-command-center{margin:0 0 18px;padding:18px;border:1px solid #d7dce5;border-radius:16px;background:#fff;box-shadow:0 8px 24px rgba(15,23,42,.06)}
.owner-command-head{display:flex;justify-content:space-between;gap:16px;align-items:flex-start;margin-bottom:14px}.owner-command-kicker{font-size:11px;font-weight:800;letter-spacing:.08em;color:#7c2d12}.owner-command-head h2{margin:4px 0 4px;font-size:21px}.owner-command-head p{margin:0;color:#64748b;font-size:12px;max-width:760px}.owner-command-generated{font-size:11px;color:#64748b;white-space:nowrap}
.owner-command-stats{display:grid;grid-template-columns:repeat(6,minmax(0,1fr));gap:8px;margin-bottom:14px}.owner-stat{padding:11px;border:1px solid #e2e8f0;border-radius:12px;background:#f8fafc}.owner-stat span,.owner-stat small{display:block;font-size:10px;color:#64748b}.owner-stat strong{display:block;font-size:23px;line-height:1.15;margin:3px 0}.owner-stat-critical{background:#fff1f2;border-color:#fecdd3}.owner-stat-critical strong{color:#be123c}.owner-stat-attention{background:#fff7ed;border-color:#fed7aa}.owner-stat-attention strong{color:#c2410c}
.owner-command-queue{border:1px solid #e2e8f0;border-radius:12px;overflow:hidden}.owner-command-queue-head{display:flex;justify-content:space-between;gap:10px;padding:10px 12px;background:#f8fafc;font-size:12px}.owner-command-queue-head span{color:#64748b}.owner-command-row{display:flex;align-items:center;gap:10px;padding:10px 12px;border-top:1px solid #eef2f7}.owner-severity{font-size:9px;font-weight:800;padding:4px 6px;border-radius:999px;min-width:65px;text-align:center}.owner-severity.critical{background:#ffe4e6;color:#be123c}.owner-severity.attention{background:#ffedd5;color:#c2410c}.owner-command-main{flex:1;min-width:0;font-size:12px}.owner-command-main>div{color:#475569;margin-top:2px}.owner-command-main small{display:block;color:#64748b;margin-top:3px}.owner-command-open{font-size:11px;font-weight:700;white-space:nowrap;text-decoration:none}.owner-command-empty{padding:18px;text-align:center;color:#166534;font-size:13px}.owner-command-actions{display:flex;align-items:center;gap:7px;flex-wrap:wrap}.owner-command-whatsapp{display:inline-flex;align-items:center;gap:5px;border:1px solid #bbf7d0;background:#f0fdf4;color:#166534;border-radius:8px;padding:6px 9px;font-size:11px;font-weight:700;cursor:pointer}.owner-whatsapp-icon{width:15px;height:15px;flex:0 0 15px}.owner-command-call{border:1px solid #bfdbfe;background:#eff6ff;color:#1d4ed8;border-radius:8px;padding:6px 9px;font-size:11px;font-weight:700;text-decoration:none;white-space:nowrap}
@media(max-width:900px){.owner-command-stats{grid-template-columns:repeat(2,minmax(0,1fr))}.owner-command-head{display:block}.owner-command-generated{margin-top:8px}.owner-command-row{align-items:flex-start;flex-wrap:wrap}.owner-command-main{min-width:calc(100% - 80px)}.owner-command-open{margin-left:75px}}
</style>
@endif
