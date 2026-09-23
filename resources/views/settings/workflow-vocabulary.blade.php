@extends('layouts.app')

@section('content')
<div class="container" style="max-width:1180px;margin:0 auto;padding:20px;">
    <div style="display:flex;justify-content:space-between;gap:16px;align-items:flex-start;flex-wrap:wrap;margin-bottom:20px;">
        <div>
            <div class="muted" style="font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;">
                Super Admin · Workflow Vocabulary
            </div>
            <h1 style="margin:4px 0 6px;">{{ $user->name }}</h1>
            <p class="muted" style="margin:0;max-width:760px;">
                Change what this employee sees without changing the canonical CRM meaning.
                Automation, reporting and history continue to use the canonical key.
            </p>
        </div>
        <a href="{{ route('settings.index') }}" class="btn btn-secondary">← Settings</a>
    </div>

    @if(session('success'))
        <div class="alert alert-success" style="margin-bottom:16px;">{{ session('success') }}</div>
    @endif

    @if($errors->any())
        <div class="alert alert-danger" style="margin-bottom:16px;">
            {{ $errors->first() }}
        </div>
    @endif

    <form method="POST" action="{{ route('settings.workflow-vocabulary.save', $user->id) }}">
        @csrf

        <div class="card" style="padding:0;overflow:hidden;">
            <div style="padding:16px 18px;border-bottom:1px solid var(--c-border,#ddd);">
                <strong>Workflow Outcomes</strong>
                <div class="muted" style="font-size:12px;margin-top:4px;">
                    Employee wording is presentation only. Canonical behavior shown below remains authoritative.
                </div>
            </div>

            <div style="overflow-x:auto;">
                <table style="width:100%;border-collapse:collapse;min-width:900px;">
                    <thead>
                        <tr style="text-align:left;">
                            <th style="padding:12px;">Canonical outcome</th>
                            <th style="padding:12px;">System behavior</th>
                            <th style="padding:12px;min-width:240px;">Employee sees</th>
                            <th style="padding:12px;width:90px;">Visible</th>
                            <th style="padding:12px;width:110px;">Order</th>
                        </tr>
                    </thead>
                    <tbody>
                    @foreach($outcomes as $outcome)
                        @php
                            $override = $outcomeOverrides->get($outcome->key);
                            $visible = $override ? (bool) $override->is_visible : true;
                            $next = $outcome->nextActionType?->label;
                            $delay = (int) $outcome->next_action_delay_hours;
                        @endphp
                        <tr style="border-top:1px solid var(--c-border,#eee);vertical-align:top;">
                            <td style="padding:12px;">
                                <strong>{{ $outcome->label }}</strong>
                                <div class="muted" style="font-size:11px;margin-top:3px;">
                                    {{ $outcome->key }}
                                </div>
                            </td>

                            <td style="padding:12px;font-size:12px;line-height:1.5;">
                                <div>
                                    {{ $outcome->is_connected ? 'Counts as connected' : 'Attempt / not connected' }}
                                </div>
                                @if($next)
                                    <div>
                                        Next: <strong>{{ $next }}</strong>
                                        @if($delay > 0)
                                            · {{ $delay }}h
                                        @endif
                                    </div>
                                @endif
                                @if($outcome->suggestedStatus)
                                    <div>Suggests: <strong>{{ $outcome->suggestedStatus->label }}</strong></div>
                                @endif
                                @if($outcome->context_action_key)
                                    <div class="muted">Context: {{ $outcome->context_action_key }}</div>
                                @endif
                            </td>

                            <td style="padding:12px;">
                                <input
                                    type="text"
                                    class="input"
                                    name="outcomes[{{ $outcome->key }}][display_label]"
                                    value="{{ old('outcomes.'.$outcome->key.'.display_label', $override?->display_label) }}"
                                    maxlength="150"
                                    placeholder="{{ $outcome->label }}"
                                >
                            </td>

                            <td style="padding:12px;text-align:center;">
                                <input type="hidden"
                                       name="outcomes[{{ $outcome->key }}][is_visible]"
                                       value="0">
                                <input type="checkbox"
                                       name="outcomes[{{ $outcome->key }}][is_visible]"
                                       value="1"
                                       @checked(old('outcomes.'.$outcome->key.'.is_visible', $visible))>
                            </td>

                            <td style="padding:12px;">
                                <input
                                    type="number"
                                    class="input"
                                    name="outcomes[{{ $outcome->key }}][sort_order]"
                                    value="{{ old('outcomes.'.$outcome->key.'.sort_order', $override?->sort_order) }}"
                                    min="0"
                                    max="100000"
                                    placeholder="{{ $outcome->sort_order }}"
                                >
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        <div class="card" style="padding:0;overflow:hidden;margin-top:18px;">
            <div style="padding:16px 18px;border-bottom:1px solid var(--c-border,#ddd);">
                <strong>Lost Reasons</strong>
                <div class="muted" style="font-size:12px;margin-top:4px;">
                    Wording, visibility and order may differ by employee. Nurture eligibility is canonical system behavior and cannot be changed here.
                </div>
            </div>

            @foreach($lostReasons as $groupKey => $reasonOptions)
                <div style="padding:12px 18px;background:var(--c-bg-soft,#f7f7f7);border-bottom:1px solid var(--c-border,#ddd);">
                    <strong>{{ $groupKey === "nurture" ? "Nurture eligible" : "Permanently closed" }}</strong>
                    <div class="muted" style="font-size:11px;margin-top:2px;">
                        {{ $groupKey === "nurture"
                            ? "These reasons may create future reactivation work when a nurture date is supplied."
                            : "These reasons do not permit nurture/reactivation scheduling." }}
                    </div>
                </div>

                <div style="overflow-x:auto;">
                    <table style="width:100%;border-collapse:collapse;min-width:780px;">
                        <thead>
                            <tr style="text-align:left;">
                                <th style="padding:12px;">Canonical reason</th>
                                <th style="padding:12px;">System behavior</th>
                                <th style="padding:12px;min-width:240px;">Employee sees</th>
                                <th style="padding:12px;width:90px;">Visible</th>
                                <th style="padding:12px;width:110px;">Order</th>
                            </tr>
                        </thead>
                        <tbody>
                        @foreach($reasonOptions as $index => $reason)
                            @php
                                $override = $lostReasonOverrides->get($reason["key"]);
                                $visible = $override ? (bool) $override->is_visible : true;
                            @endphp
                            <tr style="border-top:1px solid var(--c-border,#eee);vertical-align:top;">
                                <td style="padding:12px;">
                                    <strong>{{ $reason["label"] }}</strong>
                                    <div class="muted" style="font-size:11px;margin-top:3px;">
                                        {{ $reason["key"] }}
                                    </div>
                                </td>

                                <td style="padding:12px;font-size:12px;line-height:1.5;">
                                    @if($groupKey === "nurture")
                                        <strong>Nurture eligible</strong>
                                        <div class="muted">May schedule reactivation work.</div>
                                    @else
                                        <strong>Permanently closed</strong>
                                        <div class="muted">Nurture/reactivation not allowed.</div>
                                    @endif
                                </td>

                                <td style="padding:12px;">
                                    <input
                                        type="text"
                                        class="input"
                                        name="lost_reasons[{{ $reason["key"] }}][display_label]"
                                        value="{{ old("lost_reasons.".$reason["key"].".display_label", $override?->display_label) }}"
                                        maxlength="150"
                                        placeholder="{{ $reason["label"] }}"
                                    >
                                </td>

                                <td style="padding:12px;text-align:center;">
                                    <input type="hidden"
                                           name="lost_reasons[{{ $reason["key"] }}][is_visible]"
                                           value="0">
                                    <input type="checkbox"
                                           name="lost_reasons[{{ $reason["key"] }}][is_visible]"
                                           value="1"
                                           @checked(old("lost_reasons.".$reason["key"].".is_visible", $visible))>
                                </td>

                                <td style="padding:12px;">
                                    <input
                                        type="number"
                                        class="input"
                                        name="lost_reasons[{{ $reason["key"] }}][sort_order]"
                                        value="{{ old("lost_reasons.".$reason["key"].".sort_order", $override?->sort_order) }}"
                                        min="0"
                                        max="100000"
                                        placeholder="{{ $index }}"
                                    >
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            @endforeach
        </div>
        <div style="display:flex;justify-content:flex-end;margin-top:16px;">
            <button type="submit" class="btn">Save Workflow Vocabulary</button>
        </div>
    </form>
</div>
@endsection
