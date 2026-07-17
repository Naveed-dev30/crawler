@extends('layouts/layoutMaster')

@section('title', 'Leaderboard')

@section('vendor-script')
    <script src="{{ asset('assets/vendor/libs/apex-charts/apexcharts.js') }}"></script>
@endsection

@section('content')
    <h4 class="page-title">Leaderboard</h4>

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
            <h5 class="mb-3">Top 5</h5>
            <table class="table">
                <thead><tr><th>Rank</th><th>Name</th><th>Score</th></tr></thead>
                <tbody>
                    @foreach ($latest->top5 ?? [] as $row)
                        <tr class="{{ !empty($row['is_current_user']) ? 'table-primary fw-bold' : '' }}">
                            <td>#{{ $row['rank'] }}</td>
                            <td>{{ $row['public_name'] }}</td>
                            <td>{{ isset($row['score']) ? number_format($row['score']) : '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
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
        })();
    </script>
@endsection
