@extends('layouts/layoutMaster')

@section('title', 'Statistics')

@section('vendor-script')
    <script src="{{ asset('assets/vendor/libs/apex-charts/apexcharts.js') }}"></script>
@endsection

@section('content')
    <h4 class="page-title mb-4">Statistics</h4>

    {{-- Lifetime + today overview — not affected by the date range below --}}
    <div class="row gy-4 mb-4">
        <div class="col-md-6">
            <div class="card h-100"><div class="card-body">
                <div class="d-flex align-items-center justify-content-between mb-3">
                    <span class="text-muted small text-uppercase fw-semibold">Lifetime</span>
                    <span class="badge bg-label-primary rounded p-2 lh-1"><i class="bx bx-infinite"></i></span>
                </div>
                <div class="row text-center g-2">
                    <div class="col-3">
                        <h4 class="mb-0 fw-bold" style="color:#696cff" id="ov-life-placed">—</h4>
                        <small class="text-muted">Bids Placed</small>
                    </div>
                    <div class="col-3">
                        <h4 class="mb-0 fw-bold text-danger" id="ov-life-failed">—</h4>
                        <small class="text-muted">Failed</small>
                    </div>
                    <div class="col-3">
                        <h4 class="mb-0 fw-bold text-warning" id="ov-life-skills">—</h4>
                        <small class="text-muted">Skills Not Matched</small>
                    </div>
                    <div class="col-3">
                        <h4 class="mb-0 fw-bold text-info" id="ov-life-nq">—</h4>
                        <small class="text-muted">Not Qualified</small>
                    </div>
                </div>
            </div></div>
        </div>
        <div class="col-md-6">
            <div class="card h-100"><div class="card-body">
                <div class="d-flex align-items-center justify-content-between mb-3">
                    <span class="text-muted small text-uppercase fw-semibold">Today <span class="fw-normal text-lowercase">(12:01 am – 11:59 pm)</span></span>
                    <span class="badge bg-label-success rounded p-2 lh-1"><i class="bx bx-calendar-check"></i></span>
                </div>
                <div class="row text-center g-2">
                    <div class="col-3">
                        <h4 class="mb-0 fw-bold" style="color:#696cff" id="ov-day-placed">—</h4>
                        <small class="text-muted">Bids Placed</small>
                    </div>
                    <div class="col-3">
                        <h4 class="mb-0 fw-bold text-danger" id="ov-day-failed">—</h4>
                        <small class="text-muted">Failed</small>
                    </div>
                    <div class="col-3">
                        <h4 class="mb-0 fw-bold text-warning" id="ov-day-skills">—</h4>
                        <small class="text-muted">Skills Not Matched</small>
                    </div>
                    <div class="col-3">
                        <h4 class="mb-0 fw-bold text-info" id="ov-day-nq">—</h4>
                        <small class="text-muted">Not Qualified</small>
                    </div>
                </div>
            </div></div>
        </div>
    </div>

    {{-- Date range filter (applies to the sections below only) --}}
    <div class="d-flex flex-wrap justify-content-end align-items-end gap-2 mb-4" id="date-range">
        <div>
            <label class="form-label small text-muted mb-1" for="range-from">From</label>
            <input type="date" class="form-control form-control-sm" id="range-from">
        </div>
        <div>
            <label class="form-label small text-muted mb-1" for="range-to">To</label>
            <input type="date" class="form-control form-control-sm" id="range-to">
        </div>
        <div class="btn-group btn-group-sm" role="group" aria-label="Range presets">
            <button type="button" class="btn btn-outline-primary" data-preset="7">7d</button>
            <button type="button" class="btn btn-outline-primary" data-preset="30">30d</button>
            <button type="button" class="btn btn-outline-primary" data-preset="90">90d</button>
        </div>
        <button type="button" class="btn btn-sm btn-label-secondary" id="range-reset">Reset</button>
    </div>

    <!-- 24h snapshot cards -->
    <div class="row gy-4 mb-4">
        <div class="col-md-6">
            <div class="card"><div class="card-body">
                <span class="text-muted">Value Posted (24h, USD)</span>
                <h3 id="stat-posted">—</h3>
            </div></div>
        </div>
        <div class="col-md-6">
            <div class="card"><div class="card-body">
                <span class="text-muted">Value Awarded (24h, USD)</span>
                <h3 id="stat-awarded">—</h3>
            </div></div>
        </div>
    </div>

    <!-- Win-rate KPI cards (range-driven) -->
    <div class="row gy-4 mb-4">
        <div class="col-md-3 col-sm-6">
            <div class="card h-100"><div class="card-body">
                <span class="text-muted">Win Rate</span>
                <h3 class="fw-bold mb-0" id="kpi-winrate" style="color:#28c76f">—</h3>
            </div></div>
        </div>
        <div class="col-md-3 col-sm-6">
            <div class="card h-100"><div class="card-body">
                <span class="text-muted">Won (Awarded)</span>
                <h3 class="fw-bold mb-0" id="kpi-awarded">—</h3>
            </div></div>
        </div>
        <div class="col-md-3 col-sm-6">
            <div class="card h-100"><div class="card-body">
                <span class="text-muted">Completed Bids</span>
                <h3 class="fw-bold mb-0" id="kpi-completed">—</h3>
            </div></div>
        </div>
        <div class="col-md-3 col-sm-6">
            <div class="card h-100"><div class="card-body">
                <span class="text-muted">Earnings (USD)</span>
                <h3 class="fw-bold mb-0" id="kpi-earnings" style="color:#696cff">—</h3>
            </div></div>
        </div>
    </div>

    <!-- Win rate over time -->
    <div class="card mb-4"><div class="card-body">
        <h5>Win Rate Over Time</h5>
        <div id="chart-winrate"></div>
    </div></div>

    <!-- Bid outcome charts with shared granularity -->
    <div class="card mb-4"><div class="card-body">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h5 class="mb-0">Bid Outcomes</h5>
            <div class="btn-group btn-group-sm" role="group" id="granularity-group">
                <button type="button" class="btn btn-outline-primary" data-granularity="hourly">Hourly</button>
                <button type="button" class="btn btn-primary" data-granularity="daily">Daily</button>
                <button type="button" class="btn btn-outline-primary" data-granularity="weekly">Weekly</button>
                <button type="button" class="btn btn-outline-primary" data-granularity="monthly">Monthly</button>
            </div>
        </div>
        <h6 class="text-muted">All Bids</h6>
        <div id="chart-all"></div>
        <hr class="my-4">
        <div class="row mt-3">
            <div class="col-md-6">
                <h6 class="text-muted">Fixed</h6>
                <div id="chart-fixed"></div>
            </div>
            <div class="col-md-6">
                <h6 class="text-muted">Hourly</h6>
                <div id="chart-hourly"></div>
            </div>
        </div>
    </div></div>

    <!-- Project value chart -->
    <div class="card mb-4"><div class="card-body">
        <h5>Project Value (USD) — Placed vs Failed</h5>
        <div id="chart-value"></div>
    </div></div>

    <!-- Status breakdown donut -->
    <div class="card mb-4"><div class="card-body">
        <h5 class="mb-4">Bids by Status</h5>
        <div class="row align-items-center gy-4">
            <div class="col-md-6">
                <div id="chart-status"></div>
            </div>
            <div class="col-md-6">
                <div id="status-list" class="d-flex flex-column gap-3"></div>
            </div>
        </div>
    </div></div>

    <!-- Top countries + skills -->
    <div class="row gy-4">
        <div class="col-md-6">
            <div class="card"><div class="card-body">
                <h5>Top 10 Countries</h5>
                <div id="chart-countries"></div>
            </div></div>
        </div>
        <div class="col-md-6">
            <div class="card"><div class="card-body">
                <h5>Skills Awarded (24h)</h5>
                <div id="chart-skills"></div>
            </div></div>
        </div>
    </div>
@endsection

@section('page-script')
    <script>
        (function () {
            const charts = {};
            let currentGranularity = 'daily';

            const fromEl = document.querySelector('#range-from');
            const toEl = document.querySelector('#range-to');

            // Lifetime/today overview — loaded once, independent of the date range
            fetch('/stats/overview', { headers: { 'Accept': 'application/json' } })
                .then(res => res.ok ? res.json() : null)
                .then(o => {
                    if (!o) return;
                    const set = (id, v) => { const n = document.getElementById(id); if (n) n.textContent = v; };
                    set('ov-life-placed', o.lifetime.placed);
                    set('ov-life-failed', o.lifetime.failed);
                    set('ov-life-skills', o.lifetime.skillNotMatched);
                    set('ov-life-nq', o.lifetime.notQualified);
                    set('ov-day-placed', o.daily.placed);
                    set('ov-day-failed', o.daily.failed);
                    set('ov-day-skills', o.daily.skillNotMatched);
                    set('ov-day-nq', o.daily.notQualified);
                })
                .catch(() => {});

            function ymd(d) {
                return d.getFullYear() + '-'
                    + String(d.getMonth() + 1).padStart(2, '0') + '-'
                    + String(d.getDate()).padStart(2, '0');
            }

            function setRange(days) {
                const to = new Date();
                const from = new Date();
                from.setDate(from.getDate() - days);
                fromEl.value = ymd(from);
                toEl.value = ymd(to);
                clampBounds();
            }

            function clampBounds() {
                const today = ymd(new Date());
                fromEl.max = toEl.value || today;
                toEl.max = today;
                toEl.min = fromEl.value || '';
            }

            function rangeParams() {
                const p = new URLSearchParams();
                if (fromEl.value) { p.set('from', fromEl.value); }
                if (toEl.value) { p.set('to', toEl.value); }
                const s = p.toString();
                return s ? '&' + s : '';
            }

            function renderBar(elId, categories, series, horizontal, colors) {
                if (charts[elId]) { charts[elId].destroy(); }
                const el = document.querySelector('#' + elId);
                if (!el) { return; }
                const opts = {
                    chart: { type: 'bar', height: 300, stacked: false, toolbar: { show: false } },
                    plotOptions: { bar: { horizontal: !!horizontal, columnWidth: '60%' } },
                    dataLabels: { enabled: false },
                    series: series,
                    xaxis: { categories: categories },
                };
                if (colors) { opts.colors = colors; }
                charts[elId] = new ApexCharts(el, opts);
                charts[elId].render();
            }

            // Awarded, Placed, Failed
            const OUTCOME_COLORS = ['#399cff', '#28c76f', '#ea5455'];

            const STATUS_COLORS = {
                Completed: '#28c76f',
                Pending: '#ff9f43',
                Failed: '#ea5455',
                Expired: '#82868b',
            };

            function renderDonut(elId, rows) {
                if (charts[elId]) { charts[elId].destroy(); }
                const el = document.querySelector('#' + elId);
                if (!el) { return; }
                const labels = rows.map(r => r.status);
                const counts = rows.map(r => r.count);
                const amounts = rows.map(r => r.amount_usd);
                const total = counts.reduce((a, b) => a + b, 0);
                charts[elId] = new ApexCharts(el, {
                    chart: { type: 'donut', height: 320 },
                    labels: labels,
                    series: counts,
                    colors: labels.map(l => STATUS_COLORS[l] || '#696cff'),
                    legend: { position: 'bottom' },
                    dataLabels: { enabled: true, formatter: (v) => Math.round(v) + '%' },
                    plotOptions: {
                        pie: { donut: { labels: {
                            show: true,
                            total: { show: true, label: 'Total Bids', formatter: () => total.toLocaleString() },
                        } } },
                    },
                    tooltip: {
                        y: {
                            formatter: function (val, opts) {
                                const amt = amounts[opts.seriesIndex] || 0;
                                return val.toLocaleString() + ' bids · $' + Number(amt).toLocaleString();
                            },
                        },
                    },
                });
                charts[elId].render();
            }

            function renderWinRate(elId, rows) {
                if (charts[elId]) { charts[elId].destroy(); }
                const el = document.querySelector('#' + elId);
                if (!el) { return; }
                charts[elId] = new ApexCharts(el, {
                    chart: { type: 'line', height: 300, toolbar: { show: false } },
                    stroke: { curve: 'smooth', width: 3 },
                    colors: ['#28c76f'],
                    markers: { size: 4 },
                    dataLabels: { enabled: false },
                    series: [{ name: 'Win Rate', data: rows.map(r => r.win_rate) }],
                    xaxis: { categories: rows.map(r => r.bucket) },
                    yaxis: {
                        min: 0, max: 100,
                        labels: { formatter: (v) => Math.round(v) + '%' },
                    },
                    tooltip: {
                        y: {
                            formatter: function (val, opts) {
                                const r = rows[opts.dataPointIndex] || {};
                                return val + '% (' + (r.awarded || 0) + '/' + (r.completed || 0) + ')';
                            },
                        },
                    },
                });
                charts[elId].render();
            }

            async function loadWinRate(granularity) {
                const res = await fetch(`/stats/winrate?granularity=${granularity}${rangeParams()}`, { headers: { Accept: 'application/json' } });
                const data = await res.json();
                const s = data.summary || {};
                document.querySelector('#kpi-winrate').textContent = (s.win_rate ?? 0) + '%';
                document.querySelector('#kpi-awarded').textContent = Number(s.awarded ?? 0).toLocaleString();
                document.querySelector('#kpi-completed').textContent = Number(s.completed ?? 0).toLocaleString();
                document.querySelector('#kpi-earnings').textContent = '$' + Number(s.earnings_usd ?? 0).toLocaleString();
                renderWinRate('chart-winrate', data.series || []);
            }

            function outcomeSeries(rows) {
                return [
                    { name: 'Awarded', data: rows.map(r => r.awarded) },
                    { name: 'Placed', data: rows.map(r => r.placed) },
                    { name: 'Failed', data: rows.map(r => r.failed) },
                ];
            }

            async function loadOutcome(type, elId, granularity) {
                const res = await fetch(`/stats/bids?type=${type}&granularity=${granularity}${rangeParams()}`, { headers: { Accept: 'application/json' } });
                const rows = await res.json();
                renderBar(elId, rows.map(r => r.bucket), outcomeSeries(rows), false, OUTCOME_COLORS);
            }

            async function loadValue(granularity) {
                const res = await fetch(`/stats/value?granularity=${granularity}${rangeParams()}`, { headers: { Accept: 'application/json' } });
                const rows = await res.json();
                renderBar('chart-value', rows.map(r => r.bucket), [
                    { name: 'Placed (USD)', data: rows.map(r => r.placed_usd) },
                    { name: 'Failed (USD)', data: rows.map(r => r.failed_usd) },
                ], false, ['#28c76f', '#ea5455']);
            }

            function renderStatusList(rows) {
                const list = document.querySelector('#status-list');
                if (!list) { return; }
                const totalCount = rows.reduce((a, r) => a + r.count, 0) || 1;
                const totalAmt = rows.reduce((a, r) => a + Number(r.amount_usd), 0);
                list.innerHTML = rows.map(r => {
                    const color = STATUS_COLORS[r.status] || '#696cff';
                    const pct = Math.round((r.count / totalCount) * 100);
                    return `
                        <div class="d-flex align-items-center justify-content-between">
                            <div class="d-flex align-items-center gap-2">
                                <span style="width:12px;height:12px;border-radius:3px;background:${color};display:inline-block"></span>
                                <span class="fw-semibold">${r.status}</span>
                            </div>
                            <div class="text-end">
                                <div class="fw-semibold">${r.count.toLocaleString()} <span class="text-muted small">(${pct}%)</span></div>
                                <div class="text-muted small">$${Number(r.amount_usd).toLocaleString()}</div>
                            </div>
                        </div>`;
                }).join('') + `
                    <hr class="my-1">
                    <div class="d-flex align-items-center justify-content-between">
                        <span class="fw-bold">Total</span>
                        <div class="text-end">
                            <div class="fw-bold">${totalCount.toLocaleString()}</div>
                            <div class="text-muted small">$${Number(totalAmt).toLocaleString()}</div>
                        </div>
                    </div>`;
            }

            async function loadStatus() {
                const res = await fetch(`/stats/status?${rangeParams().replace(/^&/, '')}`, { headers: { Accept: 'application/json' } });
                const rows = await res.json();
                renderDonut('chart-status', rows);
                renderStatusList(rows);
            }

            async function loadCountries() {
                const res = await fetch(`/stats/countries?${rangeParams().replace(/^&/, '')}`, { headers: { Accept: 'application/json' } });
                const rows = await res.json();
                renderBar('chart-countries', rows.map(r => r.country), [
                    { name: 'Projects', data: rows.map(r => r.count) },
                ], true);
            }

            async function loadSnapshot() {
                const res = await fetch('/stats/last24h', { headers: { Accept: 'application/json' } });
                const data = await res.json();
                document.querySelector('#stat-posted').textContent = '$' + Number(data.value_posted_usd).toLocaleString();
                document.querySelector('#stat-awarded').textContent = '$' + Number(data.value_awarded_usd).toLocaleString();
                renderBar('chart-skills', data.skills.map(s => s.name), [
                    { name: 'Awarded', data: data.skills.map(s => s.count) },
                ], true);
            }

            function loadAllOutcomes(granularity) {
                loadOutcome('fixed', 'chart-fixed', granularity);
                loadOutcome('hourly', 'chart-hourly', granularity);
                loadOutcome('all', 'chart-all', granularity);
                loadValue(granularity);
                loadWinRate(granularity);
            }

            // Everything driven by the shared date range (snapshot excluded — fixed 24h).
            function reloadRangeCharts() {
                loadAllOutcomes(currentGranularity);
                loadCountries();
                loadStatus();
            }

            document.querySelectorAll('#granularity-group button').forEach(btn => {
                btn.addEventListener('click', function () {
                    document.querySelectorAll('#granularity-group button').forEach(b => {
                        b.classList.remove('btn-primary');
                        b.classList.add('btn-outline-primary');
                    });
                    this.classList.remove('btn-outline-primary');
                    this.classList.add('btn-primary');
                    currentGranularity = this.dataset.granularity;
                    loadAllOutcomes(currentGranularity);
                });
            });

            function markPreset(days) {
                document.querySelectorAll('#date-range [data-preset]').forEach(b => {
                    b.classList.toggle('btn-primary', Number(b.dataset.preset) === days);
                    b.classList.toggle('btn-outline-primary', Number(b.dataset.preset) !== days);
                });
            }

            document.querySelectorAll('#date-range [data-preset]').forEach(btn => {
                btn.addEventListener('click', function () {
                    const days = Number(this.dataset.preset);
                    setRange(days);
                    markPreset(days);
                    reloadRangeCharts();
                });
            });

            [fromEl, toEl].forEach(el => el.addEventListener('change', function () {
                markPreset(null);
                clampBounds();
                reloadRangeCharts();
            }));

            document.querySelector('#range-reset').addEventListener('click', function () {
                setRange(30);
                markPreset(30);
                reloadRangeCharts();
            });

            // Initial load — default last 30 days (matches backend default).
            setRange(30);
            markPreset(30);
            loadAllOutcomes(currentGranularity);
            loadCountries();
            loadStatus();
            loadSnapshot();
        })();
    </script>
@endsection
