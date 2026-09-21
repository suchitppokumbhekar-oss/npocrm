@extends('layouts.app')

@section('content')
<div class="page-wrap" style="max-width:1180px;margin:0 auto;padding:18px 14px 40px;">
    @php
        $s = $analysis['snapshot'];
        $n = $analysis['next_action'];
        $origin = $analysis['origin'];
        $journey = $analysis['journey'];
        $objective = $analysis['conversation_objective'];
        $actionObjective = $analysis['action_objective'] ?? $objective;
    @endphp
    <div style="display:flex;justify-content:space-between;gap:12px;align-items:flex-start;flex-wrap:wrap;margin-bottom:16px;">
        <div>
            <div class="eyebrow">SUPER ADMIN TEST · LEAD #{{ $lead->id }}</div>
            <h1 style="margin:4px 0;">🧠 {{ $lead->customer_name ?: 'Unnamed customer' }}</h1>
            <div class="muted">{{ $lead->project?->name ?: 'No project' }} · {{ $lead->status_label }}</div>
        </div>
        <a class="btn-small" href="{{ route('leads.show', $lead->id) }}">Open normal lead view →</a>
    </div>

    <div class="card" style="padding:16px;margin-bottom:12px;border-left:4px solid var(--c-primary);">
        <div class="muted" style="font-size:11px;text-transform:uppercase;">Current next action</div>
        <div style="font-size:19px;font-weight:800;margin-top:4px;">{{ $n['label'] }}</div>
        <div style="margin-top:5px;">{{ $n['reason'] }}</div>
        <div style="display:flex;gap:7px;flex-wrap:wrap;margin-top:8px;">
            <span class="badge">{{ $n['confidence_label'] ?? 'EVIDENCE' }}</span>
            <span class="badge">Basis: {{ str_replace('_',' ', $n['basis'] ?? 'timeline') }}</span>
        </div>
        @if (!empty($n['evidence_activity_ids']))
            <div class="muted" style="font-size:11px;margin-top:6px;">Evidence: timeline #{{ implode(', #', $n['evidence_activity_ids']) }}</div>
        @endif
        @if ($n['scheduled_for'])
            <div class="muted" style="font-size:12px;margin-top:6px;">Scheduled: {{ \Carbon\Carbon::parse($n['scheduled_for'])->format('d M Y, h:i A') }}</div>
        @endif
    </div>


    <div class="card" style="padding:16px;margin-bottom:12px;border-left:4px solid var(--c-primary);">
        @php
            $ir = $analysis['information_readiness'];
        @endphp
        <div style="display:flex;justify-content:space-between;gap:10px;align-items:flex-start;flex-wrap:wrap;">
            <div>
                <h3 style="margin:0 0 6px;">🧾 Information readiness</h3>
                <div class="muted" style="font-size:12px;">What the CRM already knows, what the intake channel supplied, what came from the customer, and what still needs confirmation. Project facts and agent/CRM events are deliberately separated from customer preferences.</div>
            </div>
            <span class="badge">{{ str_replace('_',' ', $ir['summary']['readiness_state']) }}</span>
        </div>
        <div style="display:flex;gap:7px;flex-wrap:wrap;margin-top:10px;">
            <span class="badge">{{ $ir['summary']['customer_provided'] }} customer-provided</span>
            <span class="badge">{{ $ir['summary']['needs_confirmation'] }} need confirmation</span>
            <span class="badge">{{ $ir['summary']['unknown'] }} unknown</span>
            <span class="badge">Channel: {{ $ir['channel']['label'] }}</span>
        </div>
        <div style="display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:8px;margin-top:12px;">
            @foreach ($ir['gates'] as $gate)
                <div style="padding:9px;border:1px solid var(--c-border);border-radius:8px;">
                    <div class="muted" style="font-size:10px;text-transform:uppercase;">{{ $gate['label'] }}</div>
                    <div style="font-size:12px;font-weight:700;margin-top:3px;">{{ str_replace('_',' ', $gate['state']) }}</div>
                    <div class="muted" style="font-size:11px;margin-top:4px;">{{ $gate['text'] }}</div>
                </div>
            @endforeach
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px;margin-top:12px;">
            @foreach ([['title'=>'CRM already knows','items'=>$ir['system_known']],['title'=>'Channel provided','items'=>$ir['channel_provided']],['title'=>'Customer information','items'=>$ir['customer_information']] ] as $group)
                <div style="padding:10px;border:1px solid var(--c-border);border-radius:8px;">
                    <strong>{{ $group['title'] }}</strong>
                    @foreach ($group['items'] as $item)
                        <div style="padding:7px 0;border-bottom:1px solid var(--c-border);">
                            <div style="display:flex;justify-content:space-between;gap:6px;align-items:flex-start;">
                                <span style="font-size:11px;">{{ $item['label'] }}</span>
                                <span class="badge" style="font-size:9px;">{{ str_replace('_',' ', $item['state']) }}</span>
                            </div>
                            @if ($item['value'] !== null)<div style="font-size:12px;font-weight:700;margin-top:3px;word-break:break-word;">{{ $item['value'] }}</div>@endif
                            <div class="muted" style="font-size:10px;margin-top:3px;">{{ $item['basis'] }}</div>
                            @if (!empty($item['evidence_activity_ids']))<div class="muted" style="font-size:10px;margin-top:2px;">Evidence: #{{ implode(', #', $item['evidence_activity_ids']) }}</div>@endif
                            @if (!empty($item['note']))<div class="muted" style="font-size:10px;margin-top:2px;">{{ $item['note'] }}</div>@endif
                        </div>
                    @endforeach
                </div>
            @endforeach
        </div>
        <div style="margin-top:10px;padding:10px;border:1px dashed var(--c-border);border-radius:8px;">
            <strong style="font-size:12px;">Agent / CRM events</strong>
            <div style="display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:7px;margin-top:7px;">
                @foreach ($ir['agent_crm_events'] as $item)
                    <div><div class="muted" style="font-size:10px;text-transform:uppercase;">{{ $item['label'] }}</div><div style="font-size:11px;">{{ $item['value'] ?: 'Not documented' }}</div></div>
                @endforeach
            </div>
        </div>
    </div>

    <div class="card" style="padding:16px;margin-bottom:12px;">
        <h3 style="margin:0 0 8px;">🎯 Recommended Next Action Intelligence</h3>
        <div style="font-weight:700;">{{ $n['label'] }}</div>
        <div class="muted" style="font-size:12px;margin-top:4px;">{{ $n['reason'] }}</div>
        <div style="display:flex;gap:7px;flex-wrap:wrap;margin-top:8px;">
            <span class="badge">{{ $n['confidence_label'] ?? 'EVIDENCE' }}</span>
            <span class="badge">Basis: {{ str_replace('_',' ', $n['basis'] ?? 'timeline') }}</span>
        </div>
        @if (!empty($n['evidence_activity_ids']))
            <div class="muted" style="font-size:11px;margin-top:6px;">Evidence: timeline #{{ implode(', #', $n['evidence_activity_ids']) }}</div>
        @endif
    </div>

    <div class="card" style="padding:16px;margin-bottom:12px;">
        <h3 style="margin:0 0 10px;">🧭 Customer Journey</h3>
        <div style="font-size:18px;font-weight:800;">{{ $journey['current_label'] }}</div>
        <div class="muted" style="font-size:12px;margin-top:4px;">{{ $journey['reason'] }}</div>
        <div style="display:flex;gap:6px;overflow:auto;margin-top:14px;padding-bottom:4px;">
            @foreach ($journey['stages'] as $stage)
                <div style="min-width:125px;padding:9px;border:1px solid var(--c-border);border-radius:8px;opacity:{{ $stage['state'] === 'not_established' ? '.55' : '1' }};">
                    <div style="font-size:10px;text-transform:uppercase;" class="muted">{{ $stage['state'] === 'documented' ? 'Documented' : ($stage['state'] === 'in_progress' ? 'In progress' : 'Not established') }}</div>
                    <div style="font-size:12px;font-weight:700;margin-top:3px;">{{ $stage['label'] }}</div>
                </div>
            @endforeach
        </div>
        @if (!empty($journey['evidence_activity_ids']))
            <div class="muted" style="font-size:11px;margin-top:8px;">Journey evidence: timeline #{{ implode(', #', $journey['evidence_activity_ids']) }}</div>
        @endif
    </div>

    <div class="card" style="padding:16px;margin-bottom:12px;border-left:4px solid var(--c-primary);">
        <div class="muted" style="font-size:11px;text-transform:uppercase;">🎯 Action objective</div>
        <h3 style="margin:4px 0 6px;">{{ $actionObjective['title'] }}</h3>
        <div>{{ $actionObjective['text'] }}</div>
        <div style="display:flex;gap:7px;flex-wrap:wrap;margin-top:9px;">
            <span class="badge">{{ $actionObjective['evidence_label'] ?? 'EVIDENCE' }}</span>
            <span class="badge">Action: {{ $n['label'] }}</span>
        </div>
        @if (!empty($actionObjective['basis']))
            <div class="muted" style="font-size:12px;margin-top:9px;">Basis: {{ implode(' · ', $actionObjective['basis']) }}</div>
        @endif
        @if (!empty($actionObjective['questions']))
            <div style="margin-top:12px;"><strong>💬 Suggested questions</strong>
                <ul style="margin:6px 0 0 18px;">
                    @foreach ($actionObjective['questions'] as $question)
                        <li style="margin:4px 0;">{{ $question }}</li>
                    @endforeach
                </ul>
            </div>
        @endif
        @if (!empty($actionObjective['evidence_activity_ids']))
            <div class="muted" style="font-size:11px;margin-top:7px;">Evidence: timeline #{{ implode(', #', $actionObjective['evidence_activity_ids']) }}</div>
        @endif
        <div class="muted" style="font-size:11px;margin-top:9px;">The action objective enriches the CRM task; it does not replace, reschedule, or create a task.</div>
    </div>


    <div class="card" style="padding:16px;margin-bottom:12px;">
        <div style="display:flex;justify-content:space-between;gap:10px;align-items:flex-start;flex-wrap:wrap;">
            <div>
                <h3 style="margin:0 0 6px;">📊 Requirement maturity &amp; decision readiness</h3>
                <div class="muted" style="font-size:12px;">Confirmed requirements come only from explicit CRM evidence. Readiness is a workflow interpretation, not a claim about customer intent.</div>
            </div>
            <span class="badge">{{ $analysis['requirement_maturity']['decision_readiness']['label'] }}</span>
        </div>
        <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:10px;">
            <span class="badge">{{ $analysis['requirement_maturity']['confirmed_count'] }} confirmed</span>
            <span class="badge">{{ $analysis['requirement_maturity']['unknown_count'] }} unknown</span>
            @if ($analysis['requirement_maturity']['decision_readiness']['comparison_evidenced'])
                <span class="badge">Competing option evidenced</span>
            @endif
        </div>
        <div class="muted" style="font-size:12px;margin-top:8px;">{{ $analysis['requirement_maturity']['decision_readiness']['text'] }}</div>
        <div style="margin-top:12px;display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:7px;">
            @foreach ($analysis['requirement_maturity']['requirements'] as $requirement)
                <div style="padding:9px;border:1px solid var(--c-border);border-radius:8px;">
                    <div class="muted" style="font-size:10px;text-transform:uppercase;">{{ $requirement['label'] }}</div>
                    <div style="font-size:12px;font-weight:700;margin-top:4px;">{{ $requirement['state_label'] }}</div>
                    @if ($requirement['value'])
                        <div style="font-size:12px;margin-top:3px;">{{ $requirement['value'] }}</div>
                    @else
                        <div class="muted" style="font-size:11px;margin-top:3px;">Needs confirmation</div>
                    @endif
                    @if (!empty($requirement['evidence_activity_ids']))
                        <div class="muted" style="font-size:10px;margin-top:4px;">#{{ implode(', #', $requirement['evidence_activity_ids']) }}</div>
                    @endif
                </div>
            @endforeach
        </div>
    </div>

    <div class="card" style="padding:16px;margin-bottom:12px;border-left:4px solid var(--c-primary);">
        <div style="display:flex;justify-content:space-between;gap:10px;align-items:flex-start;flex-wrap:wrap;">
            <div>
                <h3 style="margin:0 0 6px;">🔗 Decision dependencies</h3>
                <div class="muted" style="font-size:12px;">These are documented gaps, constraints or dependencies that may need to be resolved before the next milestone. They are not claims about customer intent.</div>
            </div>
            <span class="badge">EVIDENCE-BACKED</span>
        </div>
        @forelse ($analysis['decision_dependencies'] as $dependency)
            <div style="padding:11px 0;border-bottom:1px solid var(--c-border);">
                <div style="display:flex;justify-content:space-between;gap:8px;align-items:center;flex-wrap:wrap;">
                    <strong>{{ $dependency['title'] }}</strong>
                    <span class="badge">{{ $dependency['evidence_label'] }}</span>
                </div>
                <div style="margin-top:4px;font-size:13px;">{{ $dependency['text'] }}</div>
                @if (!empty($dependency['evidence_activity_ids']))
                    <div class="muted" style="font-size:11px;margin-top:4px;">Evidence: timeline #{{ implode(', #', $dependency['evidence_activity_ids']) }}</div>
                @endif
            </div>
        @empty
            <div class="muted" style="margin-top:10px;">No decision dependency was deterministically identified from the available records.</div>
        @endforelse
    </div>

    <div class="card" style="padding:16px;margin-bottom:12px;">
        <h3 style="margin:0 0 10px;">🧾 Evidence hierarchy</h3>
        <div class="muted" style="font-size:12px;margin-bottom:10px;">Intelligence is ordered by how directly the CRM supports the statement. Level 4 is always a prompt to confirm, not a customer fact.</div>
        <div style="display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:8px;">
            @foreach ($analysis['evidence_hierarchy'] as $level)
                <div style="padding:9px;border:1px solid var(--c-border);border-radius:8px;">
                    <div style="font-size:10px;text-transform:uppercase;" class="muted">Level {{ $level['level'] }}</div>
                    <div style="font-size:12px;font-weight:700;margin-top:3px;">{{ $level['label'] }}</div>
                    <div class="muted" style="font-size:11px;margin-top:4px;">{{ $level['description'] }}</div>
                </div>
            @endforeach
        </div>
    </div>

    <div style="display:grid;grid-template-columns:1.2fr .8fr;gap:12px;margin-bottom:12px;">
        <div class="card" style="padding:16px;">
            <h3 style="margin:0 0 10px;">🧠 What we know</h3>
            <div class="muted" style="font-size:12px;margin-bottom:10px;">Only explicit CRM data is presented as customer knowledge. Each item shows its provenance.</div>
            @if (empty($s))
                <div class="muted">No structured customer facts were safely extracted from the available data yet.</div>
            @else
                @foreach ($s as $key => $fact)
                    <div style="padding:9px 0;border-bottom:1px solid var(--c-border);">
                        <div style="display:flex;justify-content:space-between;gap:8px;align-items:center;">
                            <div class="muted" style="font-size:11px;text-transform:uppercase;">{{ str_replace('_',' ', $key) }}</div>
                            <span class="badge">{{ $fact['confidence'] === 'structured' ? 'FACT · STRUCTURED' : 'FACT · TIMELINE' }}</span>
                        </div>
                        <div style="font-weight:700;margin-top:3px;">{{ $fact['value'] }}</div>
                        @if ($fact['activity_id'])
                            <a href="#activity-{{ $fact['activity_id'] }}" class="muted" style="font-size:11px;">Evidence: timeline #{{ $fact['activity_id'] }}</a>
                        @else
                            <span class="muted" style="font-size:11px;">Source: structured lead record</span>
                        @endif
                    </div>
                @endforeach
            @endif
        </div>

        <div class="card" style="padding:16px;">
            <h3 style="margin:0 0 10px;">📍 Lead origin</h3>
            <div style="display:grid;gap:9px;">
                <div><div class="muted" style="font-size:11px;text-transform:uppercase;">Source</div><strong>{{ $origin['source'] ?: 'Unknown' }}</strong></div>
                <div><div class="muted" style="font-size:11px;text-transform:uppercase;">Origin type</div><strong>{{ $origin['type'] ? str_replace('_',' ', $origin['type']) : 'Unknown' }}</strong></div>
                @if ($origin['note'])<div><div class="muted" style="font-size:11px;text-transform:uppercase;">Origin detail</div><div style="white-space:pre-wrap;font-size:13px;">{{ $origin['note'] }}</div></div>@endif
            </div>
        </div>
    </div>

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px;">
        <div class="card" style="padding:16px;">
            <h3 style="margin:0 0 10px;">🕰️ Requirement history</h3>
            @forelse ($analysis['snapshot_history'] as $key => $items)
                @if (count($items) > 1)
                    <div style="padding:8px 0;border-bottom:1px solid var(--c-border);">
                        <div class="muted" style="font-size:11px;text-transform:uppercase;">{{ str_replace('_',' ', $key) }}</div>
                        @foreach (array_slice($items, 0, 4) as $item)
                            <div style="font-size:12px;margin-top:4px;"><strong>{{ $item['value'] }}</strong> <span class="muted">{{ $item['source'] === 'lead' ? 'structured lead record' : ($item['activity_id'] ? 'timeline #'.$item['activity_id'] : 'timeline') }}</span></div>
                        @endforeach
                    </div>
                @endif
            @empty
            @endforelse
            @if (!collect($analysis['snapshot_history'])->contains(fn($items) => count($items) > 1))
                <div class="muted">No historical requirement values were found for comparison.</div>
            @endif
        </div>

        <div class="card" style="padding:16px;">
            <h3 style="margin:0 0 10px;">🔄 What changed</h3>
            @forelse ($analysis['changes'] as $change)
                <div style="padding:10px 0;border-bottom:1px solid var(--c-border);">
                    <strong>{{ $change['label'] }}</strong>
                    <div style="margin-top:3px;">{{ $change['from'] }} <span class="muted">→</span> <strong>{{ $change['to'] }}</strong></div>
                    <div class="muted" style="font-size:11px;margin-top:3px;">{{ $change['activity_id'] ? 'Evidence timeline #'.$change['activity_id'] : 'Structured lead record' }}</div>
                </div>
            @empty
                <div class="muted">No documented requirement change detected in the available timeline.</div>
            @endforelse
        </div>

        <div class="card" style="padding:16px;">
            <h3 style="margin:0 0 10px;">❓ What we still don't know</h3>
            @forelse ($analysis['unknowns'] as $unknown)
                <div style="padding:9px 0;border-bottom:1px solid var(--c-border);">
                    <strong>{{ $unknown['label'] }}</strong>
                    <div class="muted" style="font-size:12px;margin-top:3px;">{{ $unknown['reason'] }}</div>
                </div>
            @empty
                <div class="muted">No high-priority information gaps detected by the current deterministic checks.</div>
            @endforelse
        </div>
    </div>

    <div class="card" style="padding:16px;margin-bottom:12px;">
        <h3 style="margin:0 0 10px;">🚦 Signals — supporting evidence, not the whole intelligence</h3>
        @forelse ($analysis['signals'] as $signal)
            <div style="padding:9px 0;border-bottom:1px solid var(--c-border);">
                <div><span class="badge">{{ strtoupper($signal['severity']) }}</span> <strong>{{ $signal['title'] }}</strong></div>
                <div class="muted" style="font-size:12px;margin-top:4px;">{{ $signal['text'] }}</div>
                @if (!empty($signal['activityIds']))<div class="muted" style="font-size:11px;margin-top:3px;">Evidence timeline: #{{ implode(', #', $signal['activityIds']) }}</div>@endif
            </div>
        @empty
            <div class="muted">No special signals detected from the available records.</div>
        @endforelse
    </div>

    <div class="card" style="padding:16px;">
        <div style="display:flex;justify-content:space-between;align-items:center;gap:8px;flex-wrap:wrap;">
            <div>
                <h3 style="margin:0;">📜 Evidence timeline</h3>
                <div class="muted" style="font-size:12px;margin-top:3px;">This is the factual source behind the intelligence layer. Nothing here is replaced or rewritten.</div>
            </div>
            <span class="muted" style="font-size:11px;">{{ $analysis['metrics']['activities'] }} activities · {{ $analysis['metrics']['pending_tasks'] }} pending · {{ $analysis['metrics']['overdue_tasks'] }} overdue · {{ $analysis['metrics']['site_visits'] }} site visits</span>
        </div>
        <div style="margin-top:12px;display:grid;gap:8px;">
            @forelse ($activities as $item)
                <div id="activity-{{ $item->id }}" style="padding:10px;border:1px solid var(--c-border);border-radius:8px;scroll-margin-top:90px;">
                    <div style="display:flex;justify-content:space-between;gap:8px;">
                        <strong>{{ ucfirst(str_replace('_',' ', $item->type)) }} · {{ $item->outcome ?: 'Logged' }}</strong>
                        <span class="muted" style="font-size:11px;">{{ $item->logged_at ? $item->logged_at->diffForHumans() : '' }}</span>
                    </div>
                    @if ($item->notes)<div style="white-space:pre-wrap;margin-top:4px;font-size:13px;">{{ $item->notes }}</div>@endif
                </div>
            @empty
                <div class="muted">No activity timeline exists for this lead.</div>
            @endforelse
        </div>
        <div style="margin-top:14px;">@include('partials.pagination', ['paginator' => $activities])</div>
    </div>
</div>
@endsection
