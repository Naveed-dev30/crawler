@extends('layouts/layoutMaster')

@section('title', 'Statistics')

@section('vendor-script')
    <script src="{{ asset('assets/vendor/libs/apex-charts/apexcharts.js') }}"></script>
@endsection

@section('content')
    <h4 class="page-title mb-4">Statistics</h4>

    {{-- One shared filter for the whole page: every card, chart and table
         below reads the range picked here. --}}
    <div class="card mb-4">
        <div class="card-body py-3 d-flex flex-wrap align-items-center justify-content-between gap-3">
            <span class="text-muted small text-uppercase fw-semibold d-inline-flex align-items-center">
                <i class="bx bx-calendar me-2"></i>Date Range
            </span>
            <div class="d-flex flex-wrap align-items-end gap-2" id="date-range">
                <div>
                    <label class="form-label small text-muted mb-1" for="range-from">From</label>
                    <input type="date" class="form-control form-control-sm" id="range-from">
                </div>
                <div>
                    <label class="form-label small text-muted mb-1" for="range-to">To</label>
                    <input type="date" class="form-control form-control-sm" id="range-to">
                </div>
                <div class="btn-group btn-group-sm" role="group" aria-label="Range presets">
                    <button type="button" class="btn btn-outline-primary" data-preset="0">Today</button>
                    <button type="button" class="btn btn-outline-primary" data-preset="7">7d</button>
                    <button type="button" class="btn btn-outline-primary" data-preset="30">30d</button>
                    <button type="button" class="btn btn-outline-primary" data-preset="90">90d</button>
                    <button type="button" class="btn btn-outline-primary" data-preset="all">All</button>
                </div>
                <button type="button" class="btn btn-sm btn-label-secondary" id="range-reset">Reset</button>
            </div>
        </div>
    </div>

    {{-- Category overview for the selected range --}}
    <div class="card mb-4"><div class="card-body">
        <div class="d-flex align-items-center justify-content-between mb-3">
            <span class="text-muted small text-uppercase fw-semibold">Overview</span>
            <span class="badge bg-label-primary" id="ov-range-label">—</span>
        </div>
        <div class="row text-center g-0 align-items-start">
            <div class="col-3 border-end">
                <div class="px-1">
                    <h3 class="mb-0 fw-bold" style="color:#696cff" id="ov-placed">—</h3>
                    <small class="text-muted d-block mb-1">Bids Placed</small>
                    <div class="d-flex justify-content-center gap-1 flex-wrap" style="min-height: 1.4rem;">
                        <span class="badge rounded-pill bg-label-success" title="Marked Correct"><i class="bx bx-check"></i> <span id="ov-placed-correct">—</span></span>
                        <span class="badge rounded-pill bg-label-danger" title="Marked Incorrect"><i class="bx bx-x"></i> <span id="ov-placed-incorrect">—</span></span>
                    </div>
                </div>
            </div>
            <div class="col-3 border-end">
                <div class="px-1">
                    <h3 class="mb-0 fw-bold text-danger" id="ov-failed">—</h3>
                    <small class="text-muted d-block mb-1">Failed</small>
                    <div class="d-flex justify-content-center gap-1 flex-wrap" style="min-height: 1.4rem;">
                    </div>
                </div>
            </div>
            <div class="col-3 border-end">
                <div class="px-1">
                    <h3 class="mb-0 fw-bold text-warning" id="ov-skills">—</h3>
                    <small class="text-muted d-block mb-1">Skills Not Matched</small>
                    <div class="d-flex justify-content-center gap-1 flex-wrap" style="min-height: 1.4rem;">
                        <span class="badge rounded-pill bg-label-success" title="Interested"><i class="bx bx-check"></i> <span id="ov-skills-int">—</span></span>
                        <span class="badge rounded-pill bg-label-danger" title="Not Interested"><i class="bx bx-x"></i> <span id="ov-skills-notint">—</span></span>
                    </div>
                </div>
            </div>
            <div class="col-3">
                <div class="px-1">
                    <h3 class="mb-0 fw-bold text-info" id="ov-nq">—</h3>
                    <small class="text-muted d-block mb-1">Not Qualified</small>
                    <div class="d-flex justify-content-center gap-1 flex-wrap" style="min-height: 1.4rem;">
                    </div>
                </div>
            </div>
        </div>
    </div></div>

    <!-- Value posted vs awarded over the selected range -->
    <div class="row gy-4 mb-4">
        <div class="col-md-6">
            <div class="card"><div class="card-body">
                <span class="text-muted">Value Posted (USD)</span>
                <h3 id="stat-posted">—</h3>
            </div></div>
        </div>
        <div class="col-md-6">
            <div class="card"><div class="card-body">
                <span class="text-muted">Value Awarded (USD)</span>
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
        <h5>Project Value (USD) by Category</h5>
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
                <h5>Skills Awarded</h5>
                <div id="chart-skills"></div>
            </div></div>
        </div>
    </div>

    <div class="card mt-4 mb-4">
        <h5 class="card-header">Mobile Agent Activity</h5>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-sm mb-0">
                    <thead>
                        <tr>
                            <th>Agent</th>
                            <th class="text-end">Assigned</th>
                            <th class="text-end">Responded</th>
                            <th class="text-end">Blocked</th>
                            <th class="text-end">Reassigned</th>
                            <th class="text-end">Avg response</th>
                        </tr>
                    </thead>
                    <tbody id="agent-rows">
                        <tr><td colspan="6" class="text-center text-muted py-3">Loading…</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="modal fade" id="agentActivityModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="agentActivityTitle">Agent activity</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <ul class="list-group list-group-flush" id="agent-activity-list"></ul>
                    <p id="agent-activity-empty" class="text-muted text-center py-4 d-none mb-0">No activity in this range.</p>
                </div>
            </div>
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

            // The All preset has no fixed From/To — it asks the backend for
            // everything on record instead.
            let allTime = false;

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

            // Every endpoint on this page takes the same window, so build all
            // their URLs through here — nothing on the dashboard opts out.
            function statsUrl(path, extra) {
                const p = new URLSearchParams();
                if (allTime) {
                    p.set('all', '1');
                } else {
                    if (fromEl.value) { p.set('from', fromEl.value); }
                    if (toEl.value) { p.set('to', toEl.value); }
                }
                Object.entries(extra || {}).forEach(([k, v]) => p.set(k, v));
                const q = p.toString();
                return q ? path + '?' + q : path;
            }

            function rangeLabel(from, to) {
                if (allTime) { return 'All time'; }
                const fmt = (d) => new Date(d + 'T00:00:00').toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' });
                if (!from || !to) { return '—'; }
                return from === to ? fmt(from) : fmt(from) + ' – ' + fmt(to);
            }

            async function loadOverview() {
                try {
                    const res = await fetch(statsUrl('/stats/overview'), { headers: { Accept: 'application/json' } });
                    if (!res.ok) { return; }
                    const o = await res.json();
                    const c = o.counts || {};
                    const set = (id, v) => { const n = document.getElementById(id); if (n) { n.textContent = v; } };
                    set('ov-range-label', rangeLabel(o.from, o.to));
                    set('ov-placed', c.placed);
                    set('ov-placed-correct', c.placedCorrect);
                    set('ov-placed-incorrect', c.placedIncorrect);
                    set('ov-failed', c.failed);
                    set('ov-skills', c.skillNotMatched);
                    set('ov-skills-int', c.skillsInterested);
                    set('ov-skills-notint', c.skillsNotInterested);
                    set('ov-nq', c.notQualified);
                } catch (e) { /* keep last render */ }
            }

            function renderBar(elId, categories, series, horizontal, colors, extraOpts) {
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
                Object.assign(opts, extraOpts || {});
                charts[elId] = new ApexCharts(el, opts);
                charts[elId].render();
            }

            // Awarded, Placed, Failed, Skills Not Matched, Not Qualified —
            // the last three share the Bids by Status palette below.
            const OUTCOME_COLORS = ['#399cff', '#28c76f', '#ea5455', '#ffab00', '#00cfe8'];

            const STATUS_COLORS = {
                'Bids Placed': '#28c76f',
                'Failed': '#ea5455',
                'Skills Not Matched': '#ffab00',
                'Not Qualified': '#00cfe8',
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
                const res = await fetch(statsUrl('/stats/winrate', { granularity }), { headers: { Accept: 'application/json' } });
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
                    { name: 'Skills Not Matched', data: rows.map(r => r.skills) },
                    { name: 'Not Qualified', data: rows.map(r => r.nq) },
                ];
            }

            async function loadOutcome(type, elId, granularity) {
                const res = await fetch(statsUrl('/stats/bids', { type, granularity }), { headers: { Accept: 'application/json' } });
                const rows = await res.json();
                renderBar(elId, rows.map(r => r.bucket), outcomeSeries(rows), false, OUTCOME_COLORS);
            }

            async function loadValue(granularity) {
                const res = await fetch(statsUrl('/stats/value', { granularity }), { headers: { Accept: 'application/json' } });
                const rows = await res.json();
                // Same four categories, same colours as the Bids by Status donut.
                renderBar('chart-value', rows.map(r => r.bucket), [
                    { name: 'Bids Placed', data: rows.map(r => r.placed_usd) },
                    { name: 'Failed', data: rows.map(r => r.failed_usd) },
                    { name: 'Skills Not Matched', data: rows.map(r => r.skills_usd) },
                    { name: 'Not Qualified', data: rows.map(r => r.nq_usd) },
                ], false, [
                    STATUS_COLORS['Bids Placed'],
                    STATUS_COLORS['Failed'],
                    STATUS_COLORS['Skills Not Matched'],
                    STATUS_COLORS['Not Qualified'],
                ], {
                    tooltip: { y: { formatter: (v) => '$' + Math.round(v || 0).toLocaleString() } },
                });
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
                const res = await fetch(statsUrl('/stats/status'), { headers: { Accept: 'application/json' } });
                const rows = await res.json();
                renderDonut('chart-status', rows);
                renderStatusList(rows);
            }

            async function loadCountries() {
                const res = await fetch(statsUrl('/stats/countries'), { headers: { Accept: 'application/json' } });
                const rows = await res.json();
                const amounts = rows.map(r => Number(r.amount_usd) || 0);
                renderBar('chart-countries', rows.map(r => r.country), [
                    { name: 'Projects', data: rows.map(r => r.count) },
                ], true, null, {
                    // Count alone hides that one country's ten projects can be
                    // worth more than another's forty.
                    tooltip: {
                        y: {
                            formatter: function (val, opts) {
                                return val.toLocaleString() + ' projects · $'
                                    + Math.round(amounts[opts.dataPointIndex] || 0).toLocaleString();
                            },
                        },
                    },
                });
            }

            async function loadValueTotals() {
                const res = await fetch(statsUrl('/stats/snapshot'), { headers: { Accept: 'application/json' } });
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

            // The whole page hangs off the shared date range — one entry point,
            // so nothing can quietly keep showing a different window.
            function reloadAll() {
                loadOverview();
                loadAllOutcomes(currentGranularity);
                loadCountries();
                loadStatus();
                loadValueTotals();
                loadMobileAgents();
            }

            function markGranularity(g) {
                currentGranularity = g;
                document.querySelectorAll('#granularity-group button').forEach(b => {
                    const on = b.dataset.granularity === g;
                    b.classList.toggle('btn-primary', on);
                    b.classList.toggle('btn-outline-primary', !on);
                });
            }

            document.querySelectorAll('#granularity-group button').forEach(btn => {
                btn.addEventListener('click', function () {
                    markGranularity(this.dataset.granularity);
                    loadAllOutcomes(currentGranularity);
                });
            });

            function markPreset(preset) {
                document.querySelectorAll('#date-range [data-preset]').forEach(b => {
                    const on = b.dataset.preset === String(preset);
                    b.classList.toggle('btn-primary', on);
                    b.classList.toggle('btn-outline-primary', !on);
                });
            }

            // A day of data is unreadable bucketed daily, and years of it is
            // unreadable bucketed hourly — pick a granularity that fits.
            function applyPreset(preset) {
                if (preset === 'all') {
                    allTime = true;
                    fromEl.value = '';
                    toEl.value = '';
                    markGranularity('monthly');
                } else {
                    allTime = false;
                    setRange(Number(preset));
                    if (Number(preset) === 0) { markGranularity('hourly'); }
                }
                markPreset(preset);
            }

            document.querySelectorAll('#date-range [data-preset]').forEach(btn => {
                btn.addEventListener('click', function () {
                    applyPreset(this.dataset.preset);
                    reloadAll();
                });
            });

            [fromEl, toEl].forEach(el => el.addEventListener('change', function () {
                allTime = false;
                markPreset(null);
                clampBounds();
                reloadAll();
            }));

            document.querySelector('#range-reset').addEventListener('click', function () {
                applyPreset('30');
                markGranularity('daily');
                reloadAll();
            });

            // Mobile agent activity table + modal
            const agentRoute = @json(route('stats.mobile-agents'));
            const agentActivityBase = '/stats/mobile-agents/';

            function fmtDuration(sec) {
                if (sec === null || sec === undefined) { return '—'; }
                const h = Math.floor(sec / 3600), m = Math.floor((sec % 3600) / 60);
                return h ? (h + 'h ' + m + 'm') : (m + 'm');
            }
            function esc(s) { const d = document.createElement('div'); d.textContent = s == null ? '' : s; return d.innerHTML; }

            async function loadMobileAgents() {
                const tbody = document.querySelector('#agent-rows');
                try {
                    const res = await fetch(statsUrl(agentRoute), { headers: { Accept: 'application/json' } });
                    if (!res.ok) { return; }
                    const data = await res.json();
                    const rows = data.rows || [];
                    if (!rows.length) {
                        tbody.innerHTML = '<tr><td colspan="6" class="text-center text-muted py-3">No mobile agents.</td></tr>';
                        return;
                    }
                    tbody.innerHTML = rows.map(r => {
                        const uid = parseInt(r.user_id, 10);
                        return '<tr style="cursor:pointer" data-user-id="' + uid + '" data-name="' + esc(r.name) + '">' +
                        '<td>' + esc(r.name) + '</td>' +
                        '<td class="text-end">' + r.assigned + '</td>' +
                        '<td class="text-end">' + r.responded + '</td>' +
                        '<td class="text-end">' + r.blocked + '</td>' +
                        '<td class="text-end">' + r.reassigned + '</td>' +
                        '<td class="text-end">' + fmtDuration(r.avg_response_seconds) + '</td>' +
                        '</tr>'
                    }).join('');
                } catch (e) { /* keep last render */ }
            }

            document.querySelector('#agent-rows').addEventListener('click', async function (ev) {
                const tr = ev.target.closest('tr[data-user-id]');
                if (!tr) { return; }
                const uid = parseInt(tr.dataset.userId, 10);
                if (!Number.isInteger(uid)) { return; }
                document.querySelector('#agentActivityTitle').textContent = tr.dataset.name + ' — activity';
                const list = document.querySelector('#agent-activity-list');
                const empty = document.querySelector('#agent-activity-empty');
                list.innerHTML = '';
                empty.classList.add('d-none');
                bootstrap.Modal.getOrCreateInstance(document.querySelector('#agentActivityModal')).show();

                const res = await fetch(statsUrl(agentActivityBase + uid + '/activity'), { headers: { Accept: 'application/json' } });
                if (!res.ok) { return; }
                const items = (await res.json()).items || [];
                if (!items.length) { empty.classList.remove('d-none'); return; }
                list.innerHTML = items.map(it =>
                    '<li class="list-group-item px-0">' +
                    '<div class="d-flex justify-content-between"><span class="badge bg-label-primary">' + esc(it.type) + '</span>' +
                    '<small class="text-muted">' + esc(new Date(it.time).toLocaleString()) + '</small></div>' +
                    '<div class="small mt-1">' + (it.project_id ? '<span class="text-muted">#' + esc(it.project_id) + '</span> ' : '') + esc(it.detail) + '</div>' +
                    '</li>'
                ).join('');
            });

            // Initial load — default last 30 days (matches backend default).
            applyPreset('30');
            reloadAll();
        })();
    </script>
@endsection
