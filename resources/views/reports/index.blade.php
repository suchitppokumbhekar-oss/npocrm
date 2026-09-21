@extends('layouts.app')

@section('title', 'Reports — NPO CRM')

@section('content')

    <div class="reports-page">

    <a href="{{ url('/') }}" class="back-link">← Back to dashboard</a>

    <div class="card" style="padding:0;overflow:hidden;">
        <div class="settings-head">
            <h2>📊 Reports</h2>
            <p class="muted" style="font-size:13px;margin-top:4px;">
                Business insights — leads, conversions, agents, projects, brokerage.
            </p>
        </div>

        @include('partials.reports-tabs', ['currentReport' => $tab])
    </div>

    {{-- ============================================================ --}}
    {{-- CONVERSION --}}
    {{-- ============================================================ --}}
    @if ($tab === 'conversion')
        @php $c = $conversion; @endphp

        <div class="card">
            <div class="section-head">
                <h3>🎯 Conversion Funnel</h3>
            </div>

            <div class="grid-stats" style="margin-bottom:var(--s-3);">
                <x-stat-card label="📋 Total Leads"   :value="$c['total_leads']" />
                <x-stat-card label="🎉 Booked"        :value="$c['booked_count'] . ' (' . $c['booked_pct'] . '%)'" variant="green" />
                <x-stat-card label="🚫 Lost"          :value="$c['lost_count'] . ' (' . $c['lost_pct'] . '%)'" variant="red" />
                <x-stat-card label="✅ Conversion"    :value="$c['booked_pct'] . '%'" variant="green" />
            </div>

            <div class="funnel">
                @foreach ($c['steps'] as $step)
                    @php
                        $barWidth = max($step['pct'], 3);
                        $color = $step['color'] ?? 'blue';
                    @endphp
                    <div class="funnel-row">
                        <div class="funnel-label">{{ $step['label'] }}</div>
                        <div class="funnel-track">
                            <div class="funnel-fill {{ $color }}" style="width:{{ $barWidth }}%;"></div>
                        </div>
                        <div class="funnel-count">
                            <strong>{{ $step['count'] }}</strong>
                            <span class="muted">{{ $step['pct'] }}%</span>
                        </div>
                        @if (isset($step['drop']) && $step['drop'] > 0)
                            <div class="funnel-drop">
                                <span style="color:var(--c-danger);font-size:11px;">
                                    ↓ {{ $step['drop'] }} lost ({{ $step['drop_pct'] }}%)
                                </span>
                            </div>
                        @else
                            <div class="funnel-drop"></div>
                        @endif
                    </div>
                @endforeach
            </div>
        </div>

    {{-- ============================================================ --}}
    {{-- SOURCES --}}
    {{-- ============================================================ --}}
    @elseif ($tab === 'sources')
        <div class="card">
            <h3>📥 Lead Sources</h3>

            @if (count($sources) === 0)
                <p class="muted">No data yet.</p>
            @else
                <div class="table-wrap">
                    <table>
                        <tr>
                            <th>Source</th>
                            <th>Total</th>
                            <th>Active</th>
                            <th>Booked</th>
                            <th>Lost</th>
                            <th>Conv %</th>
                            <th>Booked Value</th>
                            <th>Brokerage</th>
                        </tr>
                        @foreach ($sources as $row)
                            <tr>
                                <td><strong>{{ $row['label'] }}</strong></td>
                                <td>{{ $row['total'] }}</td>
                                <td>{{ $row['active'] }}</td>
                                <td><span class="badge green">{{ $row['booked'] }}</span></td>
                                <td><span class="badge red">{{ $row['lost'] }}</span></td>
                                <td>
                                    <strong style="color:var(--c-primary);">{{ $row['conv_pct'] }}%</strong>
                                    <br><span class="muted" style="font-size:11px;">{{ $row['lost_pct'] }}% lost</span>
                                </td>
                                <td>{{ inr($row['booked_value'], 0) }}</td>
                                <td><strong>{{ inr($row['brokerage'], 0) }}</strong></td>
                            </tr>
                        @endforeach
                    </table>
                </div>
            @endif
        </div>

    {{-- ============================================================ --}}
    {{-- AGENTS --}}
    {{-- ============================================================ --}}
    @elseif ($tab === 'agents')
        <div class="card">
            <h3>👤 Agent Performance</h3>

            @if (count($agents) === 0)
                <p class="muted">No agents yet.</p>
            @else
                <div class="table-wrap">
                    <table>
                                                <tr>
                            <x-sortable-th label="Agent"           field="name" />
                            <x-sortable-th label="Load"            field="load"           align="right" />
                            <x-sortable-th label="Assigned"        field="total_assigned" align="right" />
                            <x-sortable-th label="Active"          field="active_leads"   align="right" />
                            <x-sortable-th label="Booked"          field="booked"         align="right" />
                            <x-sortable-th label="Conv %"          field="conv_pct"       align="right" />
                            <x-sortable-th label="Activities (30d)" field="activities_30" align="right" />
                            <x-sortable-th label="Pending"         field="pending_tasks"  align="right" />
                            <x-sortable-th label="Overdue"         field="overdue_tasks"  align="right" />
                            <x-sortable-th label="Booked Value"    field="booked_value"   align="right" />
                            <x-sortable-th label="Brokerage"       field="brokerage"      align="right" />
                        </tr>
                        @foreach ($agents as $row)
                            <tr>
                                <td><strong>{{ $row['name'] }}</strong></td>
                                <td>
                                    <span class="badge {{ $row['load'] >= $row['max_load'] ? 'red' : ($row['load'] > 0 ? 'orange' : 'blue') }}">
                                        {{ $row['load'] }}/{{ $row['max_load'] }}
                                    </span>
                                </td>
                                <td>{{ $row['total_assigned'] }}</td>
                                <td>{{ $row['active_leads'] }}</td>
                                <td><span class="badge green">{{ $row['booked'] }}</span></td>
                                <td><strong style="color:var(--c-primary);">{{ $row['conv_pct'] }}%</strong></td>
                                <td>{{ $row['activities_30'] }}</td>
                                <td>{{ $row['pending_tasks'] }}</td>
                                <td>
                                    @if ($row['overdue_tasks'] > 0)
                                        <span class="badge red">{{ $row['overdue_tasks'] }}</span>
                                    @else
                                        <span class="muted">0</span>
                                    @endif
                                </td>
                                <td>{{ inr($row['booked_value'], 0) }}</td>
                                <td><strong>{{ inr($row['brokerage'], 0) }}</strong></td>
                            </tr>
                                           @endforeach
                </table>
            </div>

            @include('partials.pagination', ['paginator' => $agents])
        @endif
    </div>

    {{-- ============================================================ --}}
    {{-- PROJECTS --}}
    {{-- ============================================================ --}}
    @elseif ($tab === 'projects')
        <div class="card">
            <h3>🏗️ Project Performance</h3>

            @if (count($projects) === 0)
                <p class="muted">No projects yet.</p>
            @else
                <div class="table-wrap">
                    <table>
                                                <tr>
                            <x-sortable-th label="Project"          field="name" />
                            <x-sortable-th label="Location"         field="location" />
                            <x-sortable-th label="Total"            field="total"      align="right" />
                            <x-sortable-th label="Active"           field="active"     align="right" />
                            <x-sortable-th label="Booked"           field="booked"     align="right" />
                            <x-sortable-th label="Conv %"           field="conv_pct"   align="right" />
                            <x-sortable-th label="Upcoming Visits"  field="visits"     align="right" />
                            <x-sortable-th label="Booked Value"     field="booked_value" align="right" />
                            <x-sortable-th label="Brokerage"        field="brokerage"  align="right" />
                        </tr>
                        @foreach ($projects as $row)
                            <tr>
                                <td><strong>{{ $row['name'] }}</strong></td>
                                <td><span class="muted" style="font-size:12px;">{{ $row['location'] ?? '—' }}</span></td>
                                <td>{{ $row['total'] }}</td>
                                <td>{{ $row['active'] }}</td>
                                <td><span class="badge green">{{ $row['booked'] }}</span></td>
                                <td><strong style="color:var(--c-primary);">{{ $row['conv_pct'] }}%</strong></td>
                                <td>{{ $row['upcoming_visits'] }}</td>
                                <td>{{ inr($row['booked_value'], 0) }}</td>
                                <td><strong>{{ inr($row['brokerage'], 0) }}</strong></td>
                            </tr>
                                            @endforeach
                </table>
            </div>

            @include('partials.pagination', ['paginator' => $projects])
        @endif
    </div>
    @endif

</div>{{-- .reports-page --}}

@endsection