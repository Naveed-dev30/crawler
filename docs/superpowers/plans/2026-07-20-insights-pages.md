# Insights Dashboard Pages Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Two authenticated sidebar pages — `/insights` (full metrics dashboard) and `/insights/bids` (bid table + audit-log modal) — mirroring the existing Leaderboard page pattern.

**Architecture:** Web routes in the auth group call new `page` methods on the existing `InsightsController` and `BidInsightsController`, which query the existing models and return Blade views. Views follow `leaderboard.blade.php` structure (layoutMaster, ApexCharts vendor script, card grid). Audit modal fetches the existing JSON API — no new backend endpoints.

**Tech Stack:** Laravel 10 Blade, Bootstrap (Sneat template classes already in use), ApexCharts (already vendored), PHPUnit feature tests (sqlite in-memory).

**Spec:** `docs/superpowers/specs/2026-07-20-insights-pages-design.md`

## Global Constraints

- Mirror `leaderboard` page pattern exactly: route in auth group of `routes/web.php`, controller returns `view('content.pages.<name>', [...])`, Blade extends `layouts/layoutMaster`, empty-state card when no data.
- Pages never 500 on null/partial snapshot sections — every JSON access defensive (`?? []`, `?? '—'`); charts/tables skipped when their column is null.
- Menu file `resources/menu/verticalMenu.json`: new entries appended after Leaderboard, before Filters. No `access` key.
- Test pattern of `tests/Feature/LeaderboardPageTest.php`: `RefreshDatabase`, `$this->get(...)->assertRedirect()` for guests, `$this->actingAs(User::factory()->create())` for authed.
- Run tests with `php artisan test --filter=<TestClass>`. Known pre-existing failure: ExampleTest (unrelated).
- Local commits only; NEVER push.

---

### Task 1: `/insights` dashboard page

**Files:**
- Modify: `app/Http/Controllers/InsightsController.php` (add `page` method)
- Modify: `routes/web.php` (auth group, after leaderboard route at line 70)
- Modify: `resources/menu/verticalMenu.json` (add Insights entry)
- Create: `resources/views/content/pages/insights.blade.php`
- Test: `tests/Feature/InsightsPageTest.php`

**Interfaces:**
- Consumes: `App\Models\InsightSnapshot` (columns: earnings_total, earnings_30d, bids_remaining, unearned_bids, overall_ranking; array-cast JSON columns: job_proficiency, rating_per_skill, ranking_per_skill, high_demand_skills, trending_skills, bids_per_milestone, profile_views_week, profile_views_year, earnings_over_time, bid_conversion).
- Produces: `GET /insights` (route name `insights`) rendering `content.pages.insights` with `$latest` (InsightSnapshot|null) and `$history` (array of `['date','earnings_total','bids_remaining']`).

- [ ] **Step 1: Write the failing test**

`tests/Feature/InsightsPageTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\InsightSnapshot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InsightsPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_requires_auth(): void
    {
        $this->get('/insights')->assertRedirect();
    }

    public function test_empty_state_when_no_snapshots(): void
    {
        $this->actingAs(User::factory()->create())->get('/insights')
            ->assertOk()
            ->assertSee('No insights data yet');
    }

    public function test_renders_latest_snapshot(): void
    {
        InsightSnapshot::create([
            'scraped_at' => '2026-07-20 10:00:00',
            'earnings_total' => 363600.05,
            'earnings_30d' => 0,
            'bids_remaining' => 203,
            'unearned_bids' => 1297,
            'overall_ranking' => '25%',
            'job_proficiency' => [
                ['label' => 'Completed Jobs', 'bars' => [['rightLabel' => '99%', 'fillPercentage' => 99]]],
            ],
            'rating_per_skill' => [['label' => 'Flutter', 'value' => 5]],
            'ranking_per_skill' => [['label' => '.NET', 'value' => 44, 'displayValue' => 'Top 57%']],
            'high_demand_skills' => [['label' => 'Data Entry', 'value' => 630.1, 'displayValue' => '+29%']],
            'trending_skills' => [['label' => 'Graphic Design', 'value' => 5667.4, 'direction' => 'even']],
            'bids_per_milestone' => ['user' => null, 'marketplace' => [['value' => '21.70', 'label' => 'How many bids our best freelancers need']]],
            'profile_views_week' => ['labels' => ['14/7'], 'datasets' => [['label' => 'Views', 'data' => [35]]]],
            'profile_views_year' => ['labels' => ['Jul 26'], 'datasets' => [['label' => 'Views', 'data' => [169]]]],
            'earnings_over_time' => ['labels' => ["Feb '26"], 'datasets' => [['label' => 'Amount Earned (USD)', 'data' => ['0.00']]]],
            'bid_conversion' => ['labels' => ["Jul '26"], 'datasets' => [['label' => 'Bids Placed', 'data' => [1642]]]],
            'raw' => '{}',
        ]);

        $res = $this->actingAs(User::factory()->create())->get('/insights')->assertOk();
        $res->assertSee('363,600.05');
        $res->assertSee('Bids Remaining');
        $res->assertSee('203');
        $res->assertSee('Top 25%');
        $res->assertSee('Completed Jobs');
        $res->assertSee('Flutter');
        $res->assertSee('Data Entry');
        $res->assertSee('Graphic Design');
        $res->assertSee('21.70');
    }

    public function test_partial_snapshot_does_not_error(): void
    {
        InsightSnapshot::create([
            'scraped_at' => '2026-07-20 10:00:00',
            'bids_remaining' => 210,
            'raw' => '{}',
        ]);

        $this->actingAs(User::factory()->create())->get('/insights')
            ->assertOk()
            ->assertSee('210');
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=InsightsPageTest`
Expected: FAIL — `test_requires_auth` may pass (unknown routes can redirect) but the authed tests get 404.

- [ ] **Step 3: Add controller method**

In `app/Http/Controllers/InsightsController.php` add:

```php
    public function page()
    {
        $latest = InsightSnapshot::orderByDesc('scraped_at')->first();

        $history = InsightSnapshot::orderByDesc('scraped_at')
            ->limit(90)
            ->get(['scraped_at', 'earnings_total', 'bids_remaining'])
            ->reverse()
            ->values()
            ->map(fn ($s) => [
                'date' => $s->scraped_at->format('Y-m-d'),
                'earnings_total' => $s->earnings_total,
                'bids_remaining' => $s->bids_remaining,
            ])
            ->all();

        return view('content.pages.insights', [
            'latest' => $latest,
            'history' => $history,
        ]);
    }
```

- [ ] **Step 4: Add route**

In `routes/web.php`, directly after the leaderboard route (line 70), inside the same auth group:

```php
    Route::get('/insights', [\App\Http\Controllers\InsightsController::class, 'page'])->name('insights');
```

- [ ] **Step 5: Create the view**

`resources/views/content/pages/insights.blade.php`:

```blade
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
```

- [ ] **Step 6: Add menu entry**

In `resources/menu/verticalMenu.json`, insert after the Leaderboard entry (before Filters):

```json
    {
      "url": "/insights",
      "name": "Insights",
      "icon": "menu-icon tf-icons bx bx-line-chart",
      "slug": "insights"
    },
```

- [ ] **Step 7: Run test to verify it passes**

Run: `php artisan test --filter=InsightsPageTest`
Expected: PASS (4 tests)

- [ ] **Step 8: Commit**

```bash
git add app/Http/Controllers/InsightsController.php routes/web.php resources/views/content/pages/insights.blade.php resources/menu/verticalMenu.json tests/Feature/InsightsPageTest.php
git commit -m "feat: add insights dashboard page"
```

---

### Task 2: `/insights/bids` page with audit modal

**Files:**
- Modify: `app/Http/Controllers/BidInsightsController.php` (add `page` method)
- Modify: `routes/web.php` (after the `/insights` route from Task 1)
- Modify: `resources/menu/verticalMenu.json` (add Bid Insights entry after Insights)
- Create: `resources/views/content/pages/insights-bids.blade.php`
- Test: `tests/Feature/InsightsBidsPageTest.php`

**Interfaces:**
- Consumes: `App\Models\BidInsight` (columns per Task 4 of the ingest plan; array casts on actions_taken/client_engagement; `changes()` relation — method form only). Existing JSON API `GET /api/insights/bids/{id}/changes` (used by the modal's fetch).
- Produces: `GET /insights/bids` (route name `insights.bids`) rendering `content.pages.insights-bids` with `$bids` (LengthAwarePaginator, 50/page).

- [ ] **Step 1: Write the failing test**

`tests/Feature/InsightsBidsPageTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\BidInsight;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InsightsBidsPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_requires_auth(): void
    {
        $this->get('/insights/bids')->assertRedirect();
    }

    public function test_empty_state(): void
    {
        $this->actingAs(User::factory()->create())->get('/insights/bids')
            ->assertOk()
            ->assertSee('No bid insights yet');
    }

    public function test_renders_bid_rows(): void
    {
        BidInsight::create([
            'project_id' => 39812345,
            'project_url' => 'https://www.freelancer.com/projects/php/some-project',
            'time_to_bid_seconds' => 94,
            'bid_amount' => 250,
            'bid_currency' => 'USD',
            'client_country' => 'US',
            'client_rating' => 4.8,
            'client_reviews' => 132,
            'bid_rank' => 3,
            'winning_bid_amount' => 220,
            'winning_bid_sealed' => false,
            'actions_taken' => ['viewed_by_client'],
            'last_scraped_at' => '2026-07-20 10:00:00',
        ]);

        $res = $this->actingAs(User::factory()->create())->get('/insights/bids')->assertOk();
        $res->assertSee('39812345');
        $res->assertSee('1m 34s');
        $res->assertSee('250.00 USD');
        $res->assertSee('US');
        $res->assertSee('#3');
    }

    public function test_sealed_winning_bid_shows_sealed(): void
    {
        BidInsight::create([
            'project_id' => 7,
            'winning_bid_sealed' => true,
            'last_scraped_at' => '2026-07-20 10:00:00',
        ]);

        $this->actingAs(User::factory()->create())->get('/insights/bids')
            ->assertOk()
            ->assertSee('Sealed');
    }

    public function test_paginates_at_50(): void
    {
        for ($i = 1; $i <= 55; $i++) {
            BidInsight::create(['project_id' => $i, 'last_scraped_at' => now()]);
        }

        $this->actingAs(User::factory()->create())->get('/insights/bids')
            ->assertOk()
            ->assertSee('page=2');
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=InsightsBidsPageTest`
Expected: FAIL — authed tests 404 (route not defined).

- [ ] **Step 3: Add controller method**

In `app/Http/Controllers/BidInsightsController.php` add:

```php
    public function page()
    {
        $bids = BidInsight::orderByDesc('last_scraped_at')->paginate(50);

        return view('content.pages.insights-bids', ['bids' => $bids]);
    }
```

- [ ] **Step 4: Add route**

In `routes/web.php`, directly after the `/insights` route added in Task 1:

```php
    Route::get('/insights/bids', [\App\Http\Controllers\BidInsightsController::class, 'page'])->name('insights.bids');
```

- [ ] **Step 5: Create the view**

`resources/views/content/pages/insights-bids.blade.php`:

```blade
@extends('layouts/layoutMaster')

@section('title', 'Bid Insights')

@section('content')
    <h4 class="page-title">Bid Insights</h4>

    @if ($bids->isEmpty())
        <div class="card"><div class="card-body">
            <p class="text-muted mb-0 py-4 text-center">No bid insights yet</p>
        </div></div>
    @else
        <div class="card">
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Project</th>
                            <th>Time to Bid</th>
                            <th>Bid Amount</th>
                            <th>Client</th>
                            <th>Bid Rank</th>
                            <th>Winning Bid</th>
                            <th>Actions</th>
                            <th>Last Update</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($bids as $bid)
                            <tr>
                                <td>
                                    @if ($bid->project_url)
                                        <a href="{{ $bid->project_url }}" target="_blank" rel="noopener">{{ $bid->project_id }}</a>
                                    @else
                                        {{ $bid->project_id }}
                                    @endif
                                </td>
                                <td>
                                    @if ($bid->time_to_bid_seconds !== null)
                                        {{ $bid->time_to_bid_seconds < 60
                                            ? $bid->time_to_bid_seconds . 's'
                                            : intdiv($bid->time_to_bid_seconds, 60) . 'm ' . ($bid->time_to_bid_seconds % 60) . 's' }}
                                    @else
                                        —
                                    @endif
                                </td>
                                <td>{{ $bid->bid_amount !== null ? number_format($bid->bid_amount, 2) . ' ' . ($bid->bid_currency ?? '') : '—' }}</td>
                                <td>
                                    {{ $bid->client_country ?? '—' }}
                                    @if ($bid->client_rating !== null)
                                        · {{ number_format($bid->client_rating, 1) }}★
                                    @endif
                                    @if ($bid->client_reviews !== null)
                                        · {{ $bid->client_reviews }} reviews
                                    @endif
                                </td>
                                <td>{{ $bid->bid_rank !== null ? '#' . $bid->bid_rank : '—' }}</td>
                                <td>
                                    @if ($bid->winning_bid_sealed)
                                        Sealed
                                    @elseif ($bid->winning_bid_amount !== null)
                                        {{ number_format($bid->winning_bid_amount, 2) }}
                                    @else
                                        —
                                    @endif
                                </td>
                                <td>{{ count($bid->actions_taken ?? []) }}</td>
                                <td>{{ $bid->last_scraped_at?->format('Y-m-d H:i') }}</td>
                                <td>
                                    <button type="button" class="btn btn-sm btn-outline-primary js-changes"
                                            data-bid-id="{{ $bid->id }}" data-project-id="{{ $bid->project_id }}"
                                            data-bs-toggle="modal" data-bs-target="#changesModal">
                                        Changes
                                    </button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="card-body">
                {{ $bids->links() }}
            </div>
        </div>

        {{-- Audit log modal --}}
        <div class="modal fade" id="changesModal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-lg">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">Change History — Project <span id="changesProjectId"></span></h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body" id="changesBody">
                        <p class="text-muted mb-0">Loading…</p>
                    </div>
                </div>
            </div>
        </div>
    @endif
@endsection

@section('page-script')
    <script>
        (function () {
            const body = document.getElementById('changesBody');
            const projectSpan = document.getElementById('changesProjectId');
            if (! body) { return; }

            document.querySelectorAll('.js-changes').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    projectSpan.textContent = btn.dataset.projectId;
                    body.innerHTML = '<p class="text-muted mb-0">Loading…</p>';

                    fetch('/api/insights/bids/' + btn.dataset.bidId + '/changes')
                        .then(function (res) {
                            if (! res.ok) { throw new Error('HTTP ' + res.status); }
                            return res.json();
                        })
                        .then(function (json) {
                            const rows = json.data || [];
                            if (! rows.length) {
                                body.innerHTML = '<p class="text-muted mb-0">No changes recorded</p>';
                                return;
                            }
                            let html = '<table class="table table-sm"><thead><tr>' +
                                '<th>Field</th><th>Old</th><th>New</th><th>Observed At</th></tr></thead><tbody>';
                            rows.forEach(function (c) {
                                html += '<tr><td>' + esc(c.field) + '</td><td>' + esc(c.old_value) +
                                    '</td><td>' + esc(c.new_value) + '</td><td>' + esc(c.observed_at) + '</td></tr>';
                            });
                            body.innerHTML = html + '</tbody></table>';
                        })
                        .catch(function () {
                            body.innerHTML = '<p class="text-danger mb-0">Failed to load changes</p>';
                        });
                });
            });

            function esc(v) {
                if (v === null || v === undefined) { return '—'; }
                const d = document.createElement('div');
                d.textContent = String(v);
                return d.innerHTML;
            }
        })();
    </script>
@endsection
```

- [ ] **Step 6: Add menu entry**

In `resources/menu/verticalMenu.json`, insert after the Insights entry (before Filters):

```json
    {
      "url": "/insights/bids",
      "name": "Bid Insights",
      "icon": "menu-icon tf-icons bx bx-target-lock",
      "slug": "insights-bids"
    },
```

- [ ] **Step 7: Run test to verify it passes**

Run: `php artisan test --filter=InsightsBidsPageTest`
Expected: PASS (5 tests)

- [ ] **Step 8: Run full suite**

Run: `php artisan test`
Expected: all green except the known pre-existing ExampleTest failure.

- [ ] **Step 9: Commit**

```bash
git add app/Http/Controllers/BidInsightsController.php routes/web.php resources/views/content/pages/insights-bids.blade.php resources/menu/verticalMenu.json tests/Feature/InsightsBidsPageTest.php
git commit -m "feat: add bid insights page with audit-log modal"
```
