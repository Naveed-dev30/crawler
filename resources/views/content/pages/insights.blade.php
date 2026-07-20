@extends('layouts/layoutMaster')

@section('title', 'Insights')

@section('vendor-script')
    <script src="{{ asset('assets/vendor/libs/apex-charts/apexcharts.js') }}"></script>
@endsection

@section('content')
    <h4 class="page-title">Insights</h4>

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
            <div class="col-md">
                <div class="card h-100"><div class="card-body">
                    <span class="text-muted">Unearned Bids</span>
                    <h3 class="fw-bold mb-0">{{ $latest->unearned_bids ?? '—' }}</h3>
                </div></div>
            </div>
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
                        @php $bar = $item['bars'][0] ?? []; @endphp
                        <div class="mb-3">
                            <div class="d-flex justify-content-between">
                                <span>{{ $item['label'] ?? '' }}</span>
                                <span class="fw-bold">{{ $bar['rightLabel'] ?? '' }}</span>
                            </div>
                            <div class="progress" style="height: 8px;">
                                <div class="progress-bar" role="progressbar" style="width: {{ $bar['fillPercentage'] ?? 0 }}%"></div>
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
                        $bpmMarket = $bpm['marketplace'][0] ?? null;
                    @endphp
                    <p class="mb-2"><span class="text-muted">You:</span>
                        <span class="fw-bold">{{ $bpm['user'] ?? '—' }}</span></p>
                    @if ($bpmMarket)
                        <h3 class="fw-bold mb-1">{{ $bpmMarket['value'] ?? '—' }}</h3>
                        <small class="text-muted">{{ $bpmMarket['label'] ?? '' }}</small>
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
            <div class="col-md-6">
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
                    ['title' => 'Rating per Skill', 'rows' => $latest->rating_per_skill ?? [], 'col' => 'Rating', 'value' => fn ($r) => isset($r['value']) ? number_format((float) $r['value'], 1) : '—'],
                    ['title' => 'Ranking per Skill', 'rows' => $latest->ranking_per_skill ?? [], 'col' => 'Rank', 'value' => fn ($r) => $r['displayValue'] ?? '—'],
                    ['title' => 'High Demand Skills', 'rows' => $latest->high_demand_skills ?? [], 'col' => 'Change', 'value' => fn ($r) => $r['displayValue'] ?? '—'],
                    ['title' => 'Trending Skills', 'rows' => $latest->trending_skills ?? [], 'col' => 'Trend', 'value' => fn ($r) => $r['direction'] ?? '—'],
                ];
            @endphp
            @foreach ($skillTables as $table)
                <div class="col-md-6">
                    <div class="card"><div class="card-body">
                        <h5 class="mb-3">{{ $table['title'] }}</h5>
                        @if (count($table['rows']))
                            <table class="table table-sm">
                                <thead><tr><th>Skill</th><th class="text-end">{{ $table['col'] }}</th></tr></thead>
                                <tbody>
                                    @foreach (array_slice($table['rows'], 0, 20) as $row)
                                        <tr>
                                            <td>{{ $row['label'] ?? '' }}</td>
                                            <td class="text-end">{{ $table['value']($row) }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                            @if (count($table['rows']) > 20)
                                <small class="text-muted">Showing 20 of {{ count($table['rows']) }}</small>
                            @endif
                        @else
                            <p class="text-muted mb-0">No data</p>
                        @endif
                    </div></div>
                </div>
            @endforeach
        </div>
    @endif
@endsection

@section('page-script')
    <script>
        (function () {
            function series(section) {
                return (section && section.datasets ? section.datasets : []).map(function (d) {
                    return { name: d.label || '', data: (d.data || []).map(Number) };
                });
            }

            function render(elId, type, section, colors) {
                const el = document.querySelector('#' + elId);
                if (! el || ! section || ! section.labels) { return; }
                new ApexCharts(el, {
                    chart: { type: type, height: 300, toolbar: { show: false }, stacked: type === 'bar' && section.datasets.length > 1 },
                    stroke: { curve: 'smooth', width: type === 'line' ? 3 : 0 },
                    colors: colors,
                    dataLabels: { enabled: false },
                    series: series(section),
                    xaxis: { categories: section.labels },
                }).render();
            }

            const latest = {
                earnings: @json($latest?->earnings_over_time),
                conversion: @json($latest?->bid_conversion),
                viewsWeek: @json($latest?->profile_views_week),
                viewsYear: @json($latest?->profile_views_year),
            };

            render('chart-earnings', 'line', latest.earnings, ['#28c76f']);
            render('chart-conversion', 'bar', latest.conversion, ['#ffab00', '#00cfe8', '#696cff']);
            render('chart-views-week', 'bar', latest.viewsWeek, ['#696cff']);
            render('chart-views-year', 'line', latest.viewsYear, ['#696cff']);

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
        })();
    </script>
@endsection
