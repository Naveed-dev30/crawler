@extends('layouts/layoutMaster')

@section('title', 'Leaderboard')

@section('vendor-script')
    <script src="{{ asset('assets/vendor/libs/apex-charts/apexcharts.js') }}"></script>
@endsection

@section('content')
    <div class="d-flex flex-wrap justify-content-between align-items-end mb-3 gap-2">
        <div>
            <h4 class="page-title mb-1">Leaderboard</h4>
            @if ($refreshedAt)
                <small class="text-muted">Refreshed: {{ $refreshedAt->format('Y-m-d H:i') }} · {{ $snapshotCount }} snapshots</small>
            @endif
        </div>
        <form method="GET" action="{{ route('leaderboard') }}" class="row g-2 align-items-end">
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
                <a href="{{ route('leaderboard') }}" class="btn btn-sm btn-outline-secondary">Reset</a>
            </div>
        </form>
    </div>

    @if (! $latest)
        <div class="card"><div class="card-body">
            <p class="text-muted mb-0 py-4 text-center">No leaderboard data yet</p>
        </div></div>
    @else
        <div class="row gy-4 mb-4">
            <div class="col-md-4">
                <div class="card h-100"><div class="card-body">
                    <span class="text-muted">Rank</span>
                    <h3 class="fw-bold mb-0">#{{ $latest->self_rank ?? '—' }}</h3>
                </div></div>
            </div>
            <div class="col-md-4">
                <div class="card h-100"><div class="card-body">
                    <span class="text-muted">Score</span>
                    <h3 class="fw-bold mb-0">{{ $latest->self_score !== null ? number_format($latest->self_score) : '—' }}</h3>
                </div></div>
            </div>
            <div class="col-md-4">
                <div class="card h-100"><div class="card-body">
                    <span class="text-muted">Level</span>
                    <h3 class="fw-bold mb-0">{{ $latest->self_level ?? '—' }}</h3>
                </div></div>
            </div>
        </div>

        <div class="card mb-4"><div class="card-body">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h5 class="mb-0">Top 5 — Score by Day</h5>
                <span class="text-muted small d-none d-sm-inline">{{ count($top5Dates) }} days</span>
            </div>
            <div id="chart-top5"></div>
        </div></div>

        <div class="row gy-4">
            <div class="col-md-6">
                <div class="card"><div class="card-body">
                    <h5 class="mb-3">Rank Over Time</h5>
                    <div id="chart-rank"></div>
                </div></div>
            </div>
            <div class="col-md-6">
                <div class="card"><div class="card-body">
                    <h5 class="mb-3">Score Over Time</h5>
                    <div id="chart-score"></div>
                </div></div>
            </div>
        </div>
    @endif
@endsection

@section('page-script')
    <script>
        (function () {
            const history = @json($history);
            if (! history.length) { return; }
            const dates = history.map(h => h.date);

            function render(elId, name, data, reversed, color) {
                const el = document.querySelector('#' + elId);
                if (! el) { return; }
                new ApexCharts(el, {
                    chart: { type: 'line', height: 300, toolbar: { show: false } },
                    stroke: { curve: 'smooth', width: 3 },
                    colors: [color],
                    markers: { size: 4 },
                    dataLabels: { enabled: false },
                    series: [{ name: name, data: data }],
                    xaxis: { categories: dates },
                    yaxis: { reversed: !!reversed },
                }).render();
            }

            // Rank: lower is better → reversed axis.
            render('chart-rank', 'Rank', history.map(h => h.rank), true, '#696cff');
            render('chart-score', 'Score', history.map(h => h.score), false, '#28c76f');

            // Top 5 daily score — one coloured line per current top-5 player.
            const top5Series = @json($top5Series);
            const top5Dates = @json($top5Dates);
            const top5El = document.querySelector('#chart-top5');
            if (top5El && top5Series.length) {
                new ApexCharts(top5El, {
                    chart: { type: 'line', height: 360, toolbar: { show: false }, zoom: { enabled: false } },
                    series: top5Series,
                    colors: ['#696cff', '#28c76f', '#ff9f43', '#ea5455', '#00cfe8',
                             '#9c6ade', '#f6416c', '#00b8d9', '#ffb400', '#5a8dee',
                             '#16b1a3', '#e0729e'],
                    stroke: { curve: 'smooth', width: 3 },
                    markers: { size: 3, hover: { size: 5 } },
                    dataLabels: { enabled: false },
                    legend: { position: 'top', horizontalAlign: 'left', markers: { radius: 12 } },
                    grid: { borderColor: '#eceef1', strokeDashArray: 4 },
                    xaxis: {
                        categories: top5Dates,
                        tickAmount: Math.min(top5Dates.length, 8),
                        tooltip: { enabled: false },
                    },
                    yaxis: {
                        labels: {
                            formatter: v => v == null ? '' : (v >= 1000 ? (v / 1000).toFixed(0) + 'k' : v),
                        },
                    },
                    tooltip: { y: { formatter: v => v == null ? '—' : v.toLocaleString() } },
                }).render();
            }
        })();
    </script>
@endsection
