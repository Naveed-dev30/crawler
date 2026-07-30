@extends('layouts/layoutMaster')

@section('title', 'Insights')

@section('vendor-script')
    <script src="{{ asset('assets/vendor/libs/apex-charts/apexcharts.js') }}"></script>
@endsection

@section('page-style')
    <style>
        .trend-arrow { display: inline-block; }
        .trend-up {
            width: 0; height: 0;
            border-left: 9px solid transparent;
            border-right: 9px solid transparent;
            border-bottom: 11px solid #71dd37;
        }
        .trend-down {
            width: 0; height: 0;
            border-left: 9px solid transparent;
            border-right: 9px solid transparent;
            border-top: 11px solid #ff3e1d;
        }
        .trend-even {
            width: 18px; height: 5px;
            border-radius: 3px;
            background: #a1acb8;
        }
        .trend-even-orange {
            width: 18px; height: 5px;
            border-radius: 3px;
            background: #ff9f43;
        }
        .insight-indicator { display: inline-flex; align-items: center; gap: 4px; }
        .skill-graph-btn { border: 0; background: transparent; padding: 2px 4px; cursor: pointer; color: #696cff; }
        .skill-scroll { max-height: 340px; overflow-y: auto; }
    </style>
@endsection

@section('content')
    <div class="d-flex flex-wrap justify-content-between align-items-end mb-3 gap-2">
        <div>
            <h4 class="page-title mb-1">Insights</h4>
            @if ($refreshedAt)
                <small class="text-muted">Refreshed: {{ $refreshedAt->format('Y-m-d H:i') }} · {{ $snapshotCount }} snapshots</small>
            @endif
        </div>
        <form method="GET" action="{{ route('insights') }}" class="row g-2 align-items-end">
            <div class="col-auto">
                <label class="form-label small mb-1">From</label>
                <input type="date" name="from" class="form-control form-control-sm"
                       value="{{ $from }}"
                       min="{{ optional($dateBounds['min'])->format('Y-m-d') }}"
                       max="{{ optional($dateBounds['max'])->format('Y-m-d') }}">
            </div>
            <div class="col-auto">
                <label class="form-label small mb-1">To</label>
                <input type="date" name="to" class="form-control form-control-sm"
                       value="{{ $to }}"
                       min="{{ optional($dateBounds['min'])->format('Y-m-d') }}"
                       max="{{ optional($dateBounds['max'])->format('Y-m-d') }}">
            </div>
            <div class="col-auto">
                <button type="submit" class="btn btn-sm btn-primary">Apply</button>
                <a href="{{ route('insights') }}" class="btn btn-sm btn-outline-secondary">Reset</a>
            </div>
        </form>
    </div>

    @if (! $latest)
        <div class="card"><div class="card-body">
            <p class="text-muted mb-0 py-4 text-center">No insights data yet</p>
        </div></div>
    @else
        @php
            $bMin = optional($dateBounds['min'])->format('Y-m-d');
            $bMax = optional($dateBounds['max'])->format('Y-m-d');
        @endphp

        {{-- Plain stat cards --}}
        <div class="row gy-4 mb-4">
            <div class="col-md">
                <div class="card h-100"><div class="card-body">
                    <span class="text-muted">Last 30 Days</span>
                    <h3 class="fw-bold mb-0">{{ $latest->earnings_30d !== null ? '$' . number_format($latest->earnings_30d, 2) : '—' }}</h3>
                </div></div>
            </div>
            @if ($latest->unearned_bids !== null)
                <div class="col-md">
                    <div class="card h-100"><div class="card-body">
                        <span class="text-muted">Unearned Bids</span>
                        <h3 class="fw-bold mb-0">{{ $latest->unearned_bids }}</h3>
                    </div></div>
                </div>
            @endif
            <div class="col-md">
                @include('_partials.insight-trend-box', [
                    'title' => 'Bids Remaining', 'metric' => 'bids_remaining',
                    'value' => $latest->bids_remaining ?? '—',
                    'chartType' => 'line', 'reversed' => false, 'min' => $bMin, 'max' => $bMax,
                ])
            </div>
            <div class="col-md">
                @include('_partials.insight-trend-box', [
                    'title' => 'Overall Ranking', 'metric' => 'overall_ranking',
                    'value' => $latest->overall_ranking ? 'Top ' . $latest->overall_ranking : '—',
                    'chartType' => 'line', 'reversed' => true, 'min' => $bMin, 'max' => $bMax,
                ])
            </div>
        </div>

        {{-- Total Earnings — full-width trend box --}}
        <div class="row gy-4 mb-4">
            <div class="col-12">
                @include('_partials.insight-trend-box', [
                    'title' => 'Total Earnings', 'metric' => 'earnings_total',
                    'value' => $latest->earnings_total !== null ? '$' . number_format($latest->earnings_total, 2) : '—',
                    'chartType' => 'line', 'reversed' => false, 'min' => $bMin, 'max' => $bMax,
                ])
            </div>
        </div>

        {{-- Proficiency + bids per milestone --}}
        <div class="row gy-4 mb-4">
            <div class="col-md-8">
                <div class="card h-100"><div class="card-body">
                    <h5 class="mb-3">Job Proficiency</h5>
                    @forelse ($latest->job_proficiency ?? [] as $item)
                        @php
                            $bar = $item['bars'][0] ?? [];
                            $rightLabel = $bar['rightLabel'] ?? $item['value'] ?? '';
                            $fill = $bar['fillPercentage']
                                ?? (float) preg_replace('/[^0-9.]/', '', (string) ($item['value'] ?? ''));
                        @endphp
                        <div class="mb-3">
                            <div class="d-flex justify-content-between">
                                <span>{{ $item['label'] ?? '' }}</span>
                                <span class="fw-bold">{{ $rightLabel }}</span>
                            </div>
                            <div class="progress" style="height: 8px;">
                                <div class="progress-bar" role="progressbar" style="width: {{ $fill }}%"></div>
                            </div>
                        </div>
                    @empty
                        <p class="text-muted mb-0">No data</p>
                    @endforelse
                </div></div>
            </div>
            <div class="col-md-4">
                @php
                    $bpm = $latest->bids_per_milestone ?? [];
                    $bpmRaw = $bpm['marketplace'] ?? null;
                    $bpmVal = is_array($bpmRaw) ? ($bpmRaw[0]['value'] ?? null) : $bpmRaw;
                @endphp
                @include('_partials.insight-trend-box', [
                    'title' => 'Bids per Milestone', 'metric' => 'bids_per_milestone',
                    'value' => $bpmVal ?? '—',
                    'chartType' => 'line', 'reversed' => false, 'min' => $bMin, 'max' => $bMax,
                ])
            </div>
        </div>

        {{-- Charts --}}
        <div class="row gy-4 mb-4">
            @if ($latest->earnings_over_time)
                <div class="col-md-6">
                    <div class="card"><div class="card-body">
                        <h5 class="mb-3">Earnings Over Time</h5>
                        <div id="chart-earnings"></div>
                    </div></div>
                </div>
            @endif
            @if ($latest->bid_conversion)
                <div class="col-md-6">
                    <div class="card"><div class="card-body">
                        <h5 class="mb-3">Bid Conversion</h5>
                        <div id="chart-conversion"></div>
                    </div></div>
                </div>
            @endif
            <div class="col-md-6">
                <div class="card"><div class="card-body">
                    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
                        <h5 class="mb-0">Profile Views — week of <span id="pv-week-label">{{ optional($refreshedAt)->format('Y-m-d') }}</span></h5>
                        <input type="date" id="pv-week-date" class="form-control form-control-sm" style="width:auto"
                               value="{{ optional($refreshedAt)->format('Y-m-d') }}"
                               min="{{ optional($dateBounds['min'])->format('Y-m-d') }}"
                               max="{{ optional($dateBounds['max'])->format('Y-m-d') }}">
                    </div>
                    <div id="chart-views-week"></div>
                </div></div>
            </div>
            <div class="col-12">
                <div class="card"><div class="card-body">
                    <h5 class="mb-3">Earnings History (Snapshots)</h5>
                    <div id="chart-history"></div>
                </div></div>
            </div>
        </div>

        {{-- Skill tables --}}
        <div class="row gy-4">
            @php
                $skillTables = [
                    ['title' => 'Earnings per Skill', 'section' => 'earnings_per_skill', 'rows' => $latest->earnings_per_skill ?? [], 'col' => 'Earnings', 'value' => fn ($r) => $r['value'] ?? '—'],
                    ['title' => 'Rating per Skill', 'section' => 'rating_per_skill', 'rows' => $latest->rating_per_skill ?? [], 'col' => 'Rating', 'value' => fn ($r) => isset($r['value']) ? number_format((float) $r['value'], 1) : '—'],
                    ['title' => 'Ranking per Skill', 'section' => 'ranking_per_skill', 'rows' => $latest->ranking_per_skill ?? [], 'col' => 'Rank', 'value' => fn ($r) => $r['displayValue'] ?? $r['value'] ?? '—'],
                    ['title' => 'High Demand Skills', 'section' => 'high_demand_skills', 'rows' => $latest->high_demand_skills ?? [], 'col' => 'Change', 'value' => fn ($r) => $r['displayValue'] ?? $r['value'] ?? '—'],
                ];
            @endphp
            @foreach ($skillTables as $table)
                <div class="col-md-6">
                    <div class="card"><div class="card-body">
                        <h5 class="mb-3">{{ $table['title'] }}</h5>
                        @if (count($table['rows']))
                            <div class="skill-scroll">
                            <table class="table table-sm mb-0">
                                <thead><tr><th>Skill</th><th class="text-end">{{ $table['col'] }}</th></tr></thead>
                                <tbody>
                                    @foreach ($table['rows'] as $row)
                                        @php
                                            $lbl = $row['label'] ?? $row['name'] ?? '';
                                            $d = $deltas[$table['section']][$lbl] ?? ['direction' => 'even', 'number' => null];
                                        @endphp
                                        <tr>
                                            <td>
                                                <span class="badge rounded-pill {{ $loop->first ? 'bg-warning' : 'bg-primary' }} me-2">{{ $loop->iteration }}</span>
                                                {{ $lbl }}
                                            </td>
                                            <td class="text-end">
                                                <span class="insight-indicator me-2">
                                                    @if ($d['direction'] === 'up')
                                                        <span class="trend-arrow trend-up" title="Up vs 30 days ago"></span>
                                                    @elseif ($d['direction'] === 'down')
                                                        <span class="trend-arrow trend-down" title="Down vs 30 days ago"></span>
                                                    @else
                                                        <span class="trend-arrow trend-even-orange" title="No 30-day change"></span>
                                                    @endif
                                                    <small class="text-muted">{{ $d['number'] ?? '—' }}</small>
                                                </span>
                                                {{ $table['value']($row) }}
                                                <button type="button" class="skill-graph-btn" data-section="{{ $table['section'] }}" data-label="{{ $lbl }}" title="Show history">
                                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 3v18h18"/><path d="M7 15l4-4 3 3 5-6"/></svg>
                                                </button>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                            </div>
                        @else
                            <p class="text-muted mb-0">No data</p>
                        @endif
                    </div></div>
                </div>
            @endforeach

            {{-- Trending skills: numbered list with direction arrows, like Freelancer's widget --}}
            <div class="col-md-6">
                <div class="card"><div class="card-body">
                    <h5 class="mb-3">Trending Skills</h5>
                    @php $trending = $latest->trending_skills ?? []; @endphp
                    @if (count($trending))
                        <div class="skill-scroll">
                        <ul class="list-group list-group-flush">
                            @foreach ($trending as $i => $row)
                                @php
                                    $lbl = $row['label'] ?? $row['name'] ?? '';
                                    $d = $deltas['trending_skills'][$lbl] ?? ['direction' => 'even', 'number' => null];
                                @endphp
                                <li class="list-group-item d-flex align-items-center px-0">
                                    <span class="badge rounded-pill {{ $i === 0 ? 'bg-warning' : 'bg-primary' }} me-3">{{ $i + 1 }}</span>
                                    <span class="flex-grow-1">{{ $lbl }}</span>
                                    <span class="insight-indicator me-2">
                                        @if ($d['direction'] === 'up')
                                            <span class="trend-arrow trend-up" title="Moved up vs 30 days ago"></span>
                                        @elseif ($d['direction'] === 'down')
                                            <span class="trend-arrow trend-down" title="Moved down vs 30 days ago"></span>
                                        @else
                                            <span class="trend-arrow trend-even-orange" title="No 30-day change"></span>
                                        @endif
                                        <small class="text-muted">{{ $d['number'] ?? '—' }}</small>
                                    </span>
                                    <button type="button" class="skill-graph-btn" data-section="trending_skills" data-label="{{ $lbl }}" title="Show history">
                                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 3v18h18"/><path d="M7 15l4-4 3 3 5-6"/></svg>
                                    </button>
                                </li>
                            @endforeach
                        </ul>
                        </div>
                    @else
                        <p class="text-muted mb-0">No data</p>
                    @endif
                </div></div>
            </div>
        </div>
    @endif

    <div class="modal fade" id="skillGraphModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="skillGraphTitle">Skill history</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div id="skill-graph-chart" style="min-height: 320px;"></div>
                    <p id="skill-graph-empty" class="text-muted text-center py-5 d-none mb-0">No history for this skill.</p>
                </div>
            </div>
        </div>
    </div>
@endsection

@section('page-script')
    <script>
        (function () {
            // Supports both chart shapes: {labels, datasets: [{label, data}]} (old
            // fixture captures) and {labels, values} (live crawler payloads).
            function series(section, fallbackName) {
                if (section && Array.isArray(section.datasets)) {
                    return section.datasets.map(function (d) {
                        return { name: d.label || '', data: (d.data || []).map(Number) };
                    });
                }
                if (section && Array.isArray(section.values)) {
                    return [{ name: fallbackName || '', data: section.values.map(Number) }];
                }
                return [];
            }

            function render(elId, type, section, colors, fallbackName) {
                const el = document.querySelector('#' + elId);
                if (! el || ! section || ! section.labels) { return; }
                const s = series(section, fallbackName);
                if (! s.length) { return; }
                new ApexCharts(el, {
                    chart: { type: type, height: 300, toolbar: { show: false }, stacked: type === 'bar' && s.length > 1 },
                    stroke: { curve: 'smooth', width: type === 'line' ? 3 : 0 },
                    colors: colors,
                    dataLabels: { enabled: false },
                    series: s,
                    xaxis: { categories: section.labels },
                }).render();
            }

            const latest = {
                earnings: @json($latest?->earnings_over_time),
                conversion: @json($latest?->bid_conversion),
            };

            render('chart-earnings', 'line', latest.earnings, ['#28c76f'], 'Amount Earned');
            render('chart-conversion', 'bar', latest.conversion, ['#ffab00', '#00cfe8', '#696cff'], 'Bids');

            // Profile Views week picker
            const pvWeekRoute = @json(route('insights.profile-views-week'));
            let pvWeekChart = null;

            function loadWeek(date) {
                const params = new URLSearchParams();
                if (date) { params.set('date', date); }
                fetch(pvWeekRoute + '?' + params.toString(), { headers: { Accept: 'application/json' } })
                    .then(r => r.ok ? r.json() : null)
                    .then(data => {
                        if (!data) { return; }
                        if (data.date) { document.querySelector('#pv-week-label').textContent = data.date; }
                        const el = document.querySelector('#chart-views-week');
                        if (pvWeekChart) { pvWeekChart.destroy(); pvWeekChart = null; }
                        pvWeekChart = new ApexCharts(el, {
                            chart: { type: 'bar', height: 300, toolbar: { show: false }, animations: { enabled: false } },
                            colors: ['#696cff'],
                            dataLabels: { enabled: false },
                            series: [{ name: 'Views', data: (data.values || []).map(Number) }],
                            xaxis: { categories: data.labels || [] },
                        });
                        pvWeekChart.render();
                    });
            }

            const pvDateEl = document.querySelector('#pv-week-date');
            if (pvDateEl) {
                pvDateEl.addEventListener('change', () => loadWeek(pvDateEl.value));
                loadWeek(pvDateEl.value);
            }

            const history = @json($history);
            const el = document.querySelector('#chart-history');
            if (el && history.length) {
                new ApexCharts(el, {
                    chart: { type: 'line', height: 300, toolbar: { show: false } },
                    stroke: { curve: 'smooth', width: 3 },
                    colors: ['#28c76f'],
                    dataLabels: { enabled: false },
                    series: [{ name: 'Total Earnings', data: history.map(h => h.earnings_total === null ? null : Number(h.earnings_total)) }],
                    xaxis: { categories: history.map(h => h.date) },
                }).render();
            }

            // Per-skill history modal
            const skillRoute = @json(route('insights.skill-history'));
            const rangeFrom = @json($from);
            const rangeTo = @json($to);
            const modalEl = document.querySelector('#skillGraphModal');
            // The themed content wrapper uses a CSS transform, which becomes the
            // containing block for the modal's position:fixed backdrop and leaves a
            // stray overlay that blocks clicks. Reparent the modal to <body> so the
            // backdrop covers/stacks correctly and is removed cleanly on close.
            if (modalEl && modalEl.parentNode !== document.body) {
                document.body.appendChild(modalEl);
            }
            let skillChart = null;

            document.querySelectorAll('.skill-graph-btn').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    const section = btn.getAttribute('data-section');
                    const label = btn.getAttribute('data-label');
                    document.querySelector('#skillGraphTitle').textContent = label + ' — history';

                    const params = new URLSearchParams({ section: section, label: label });
                    if (rangeFrom) { params.set('from', rangeFrom); }
                    if (rangeTo) { params.set('to', rangeTo); }

                    const empty = document.querySelector('#skill-graph-empty');
                    const chartEl = document.querySelector('#skill-graph-chart');

                    // Tear down the previous skill's chart immediately so it never
                    // lingers while the new data loads.
                    if (skillChart) { skillChart.destroy(); skillChart = null; }
                    chartEl.innerHTML = '';
                    chartEl.classList.remove('d-none');
                    empty.textContent = 'Loading…';
                    empty.classList.remove('d-none');

                    // Guard against out-of-order responses: only the latest click renders.
                    window.__skillGraphReq = (window.__skillGraphReq || 0) + 1;
                    const requestId = window.__skillGraphReq;

                    const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
                    modal.show();

                    fetch(skillRoute + '?' + params.toString(), { headers: { 'Accept': 'application/json' } })
                        .then(function (r) { return r.json(); })
                        .then(function (data) {
                            if (requestId !== window.__skillGraphReq) { return; }
                            if (skillChart) { skillChart.destroy(); skillChart = null; }

                            const hasData = (data.values || []).some(function (v) { return v !== null; });
                            if (! hasData) {
                                empty.textContent = 'No history for this skill.';
                                empty.classList.remove('d-none');
                                chartEl.classList.add('d-none');
                                return;
                            }
                            empty.classList.add('d-none'); chartEl.classList.remove('d-none');

                            // Assign the instance (NOT the .render() promise) so later
                            // destroy() calls work and don't abort the next open.
                            skillChart = new ApexCharts(chartEl, {
                                chart: { type: 'line', height: 320, toolbar: { show: false }, animations: { enabled: false } },
                                stroke: { curve: 'smooth', width: 3 },
                                colors: ['#696cff'],
                                dataLabels: { enabled: false },
                                series: [{ name: label, data: data.values.map(function (v) { return v === null ? null : Number(v); }) }],
                                xaxis: { categories: data.labels },
                                yaxis: { reversed: data.higherIsBetter === false },
                            });
                            skillChart.render();
                        });
                });
            });

            // On close, tear down the chart and defensively clear any stray
            // Bootstrap backdrop / body lock that would otherwise block clicks.
            modalEl.addEventListener('hidden.bs.modal', function () {
                if (skillChart) { skillChart.destroy(); skillChart = null; }
                document.querySelectorAll('.modal-backdrop').forEach(function (b) { b.remove(); });
                document.body.classList.remove('modal-open');
                document.body.style.removeProperty('overflow');
                document.body.style.removeProperty('padding-right');
            });

            // Per-box metric trend charts
            const metricRoute = @json(route('insights.metric-history'));
            const metricCharts = {};

            function formatMetric(metric, v) {
                if (v === null || v === undefined) { return '—'; }
                if (metric === 'earnings_total') { return '$' + Number(v).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
                if (metric === 'overall_ranking') { return 'Top ' + v + '%'; }
                return String(v);
            }

            function loadTrend(box) {
                const metric = box.getAttribute('data-metric');
                const type = box.getAttribute('data-type') || 'line';
                const reversed = box.getAttribute('data-reversed') === '1';
                const from = box.querySelector('.trend-from').value;
                const to = box.querySelector('.trend-to').value;
                const params = new URLSearchParams({ metric: metric });
                if (from) { params.set('from', from); }
                if (to) { params.set('to', to); }

                fetch(metricRoute + '?' + params.toString(), { headers: { Accept: 'application/json' } })
                    .then(r => r.ok ? r.json() : null)
                    .then(data => {
                        if (!data) { return; }
                        const el = document.querySelector('[data-metric-chart="' + metric + '"]');
                        if (metricCharts[metric]) { metricCharts[metric].destroy(); metricCharts[metric] = null; }

                        const vals = (data.values || []).map(v => v === null ? null : Number(v));
                        const last = [...vals].reverse().find(v => v !== null);
                        const valEl = document.querySelector('[data-metric-value="' + metric + '"]');
                        if (valEl && last !== undefined) { valEl.textContent = formatMetric(metric, last); }
                        if (!vals.some(v => v !== null)) { el.innerHTML = ''; return; }

                        metricCharts[metric] = new ApexCharts(el, {
                            chart: { type: type, height: 130, sparkline: { enabled: false }, toolbar: { show: false }, animations: { enabled: false } },
                            stroke: { curve: 'smooth', width: type === 'line' ? 2 : 0 },
                            colors: ['#696cff'],
                            dataLabels: { enabled: false },
                            series: [{ name: metric, data: vals }],
                            xaxis: { categories: data.labels, labels: { rotate: -45, style: { fontSize: '9px' } } },
                            yaxis: { reversed: reversed },
                        });
                        metricCharts[metric].render();
                    });
            }

            document.querySelectorAll('.insight-trend-filter').forEach(box => {
                box.querySelectorAll('.trend-from, .trend-to').forEach(inp =>
                    inp.addEventListener('change', () => loadTrend(box)));
                loadTrend(box);
            });
        })();
    </script>
@endsection
