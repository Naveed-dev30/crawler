@extends('layouts/layoutMaster')

@section('title', 'Statistics')

@section('vendor-script')
    <script src="{{ asset('assets/vendor/libs/apex-charts/apexcharts.js') }}"></script>
@endsection

@section('content')
    <h4 class="page-title">Statistics</h4>

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

            function renderBar(elId, categories, series, horizontal) {
                if (charts[elId]) { charts[elId].destroy(); }
                const el = document.querySelector('#' + elId);
                if (!el) { return; }
                charts[elId] = new ApexCharts(el, {
                    chart: { type: 'bar', height: 300, stacked: false, toolbar: { show: false } },
                    plotOptions: { bar: { horizontal: !!horizontal, columnWidth: '60%' } },
                    dataLabels: { enabled: false },
                    series: series,
                    xaxis: { categories: categories },
                });
                charts[elId].render();
            }

            function outcomeSeries(rows) {
                return [
                    { name: 'Awarded', data: rows.map(r => r.awarded) },
                    { name: 'Placed', data: rows.map(r => r.placed) },
                    { name: 'Failed', data: rows.map(r => r.failed) },
                ];
            }

            async function loadOutcome(type, elId, granularity) {
                const res = await fetch(`/stats/bids?type=${type}&granularity=${granularity}`, { headers: { Accept: 'application/json' } });
                const rows = await res.json();
                renderBar(elId, rows.map(r => r.bucket), outcomeSeries(rows), false);
            }

            async function loadValue(granularity) {
                const res = await fetch(`/stats/value?granularity=${granularity}`, { headers: { Accept: 'application/json' } });
                const rows = await res.json();
                renderBar('chart-value', rows.map(r => r.bucket), [
                    { name: 'Placed (USD)', data: rows.map(r => r.placed_usd) },
                    { name: 'Failed (USD)', data: rows.map(r => r.failed_usd) },
                ], false);
            }

            async function loadCountries() {
                const res = await fetch('/stats/countries', { headers: { Accept: 'application/json' } });
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
            }

            document.querySelectorAll('#granularity-group button').forEach(btn => {
                btn.addEventListener('click', function () {
                    document.querySelectorAll('#granularity-group button').forEach(b => {
                        b.classList.remove('btn-primary');
                        b.classList.add('btn-outline-primary');
                    });
                    this.classList.remove('btn-outline-primary');
                    this.classList.add('btn-primary');
                    loadAllOutcomes(this.dataset.granularity);
                });
            });

            // Initial load
            loadAllOutcomes('daily');
            loadCountries();
            loadSnapshot();
        })();
    </script>
@endsection
