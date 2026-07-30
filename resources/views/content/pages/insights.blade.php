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
        {{-- Stat cards --}}
        <div class="row gy-4 mb-4">
            <div class="col-md">
                <div class="card h-100"><div class="card-body">
                    <span class="text-muted">Total Earnings</span>
                    <h3 class="fw-bold mb-0">{{ $latest->earnings_total !== null ? '$' . number_format($latest->earnings_total, 2) : '—' }}</h3>
                </div></div>
            </div>
            <div class="col-md">
                <div class="card h-100"><div class="card-body">
                    <span class="text-muted">Last 30 Days</span>
                    <h3 class="fw-bold mb-0">{{ $latest->earnings_30d !== null ? '$' . number_format($latest->earnings_30d, 2) : '—' }}</h3>
                </div></div>
            </div>
            <div class="col-md">
                <div class="card h-100"><div class="card-body">
                    <span class="text-muted">Bids Remaining</span>
                    <h3 class="fw-bold mb-0">{{ $latest->bids_remaining ?? '—' }}</h3>
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
                <div class="card h-100"><div class="card-body">
                    <span class="text-muted">Overall Ranking</span>
                    <h3 class="fw-bold mb-0">{{ $latest->overall_ranking ? 'Top ' . $latest->overall_ranking : '—' }}</h3>
                </div></div>
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
                <div class="card h-100"><div class="card-body">
                    <h5 class="mb-3">Bids per Milestone</h5>
                    @php
                        $bpm = $latest->bids_per_milestone ?? [];
                        $bpmMarketRaw = $bpm['marketplace'] ?? null;
                        $bpmMarket = is_array($bpmMarketRaw)
                            ? ($bpmMarketRaw[0] ?? null)
                            : ($bpmMarketRaw !== null ? ['value' => $bpmMarketRaw] : null);
                    @endphp
                    @if ($bpmMarket)
                        <div class="text-center py-3">
                            <h1 class="fw-bold text-primary display-5 mb-2">{{ $bpmMarket['value'] ?? '—' }}</h1>
                            <p class="text-muted text-uppercase small mb-0">
                                {{ $bpmMarket['label'] ?? 'How many bids our best freelancers need to make before receiving a milestone' }}
                            </p>
                        </div>
                        @if (($bpm['user'] ?? null) !== null)
                            <p class="text-center text-muted mb-0">You: <span class="fw-bold">{{ $bpm['user'] }}</span></p>
                        @endif
                    @else
                        <p class="text-muted mb-0">No marketplace benchmark</p>
                    @endif
                </div></div>
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
            @if ($latest->profile_views_week)
                <div class="col-md-6">
                    <div class="card"><div class="card-body">
                        <h5 class="mb-3">Profile Views (Past Week)</h5>
                        <div id="chart-views-week"></div>
                    </div></div>
                </div>
            @endif
            @if ($latest->profile_views_year)
                <div class="col-md-6">
                    <div class="card"><div class="card-body">
                        <h5 class="mb-3">Profile Views (Past Year)</h5>
                        <div id="chart-views-year"></div>
                    </div></div>
                </div>
            @endif
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
                                    @foreach (array_slice($table['rows'], 0, 20) as $row)
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
                            @if (count($table['rows']) > 20)
                                <small class="text-muted">Showing 20 of {{ count($table['rows']) }}</small>
                            @endif
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
                            @foreach (array_slice($trending, 0, 20) as $i => $row)
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
                        @if (count($trending) > 20)
                            <small class="text-muted">Showing 20 of {{ count($trending) }}</small>
                        @endif
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
                viewsWeek: @json($latest?->profile_views_week),
                viewsYear: @json($latest?->profile_views_year),
            };

            render('chart-earnings', 'line', latest.earnings, ['#28c76f'], 'Amount Earned');
            render('chart-conversion', 'bar', latest.conversion, ['#ffab00', '#00cfe8', '#696cff'], 'Bids');
            render('chart-views-week', 'bar', latest.viewsWeek, ['#696cff'], 'Views');
            render('chart-views-year', 'line', latest.viewsYear, ['#696cff'], 'Views');

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
        })();
    </script>
@endsection
