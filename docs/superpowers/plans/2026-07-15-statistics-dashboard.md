# Statistics Dashboard Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the single-line Stats chart with an interactive 6-section analytics dashboard (bid outcomes by type, project value in USD, 24h snapshot, top-10 countries).

**Architecture:** Add two columns to `proposals` (`skills`, `exchange_rate`) captured at crawl time. A new `StatisticsController` exposes four JSON endpoints plus the dashboard page. Time bucketing and USD math are done in PHP (DB-agnostic — tests run on SQLite). The Blade view renders Bootstrap cards and fetches the endpoints with inline ApexCharts JS (matches the existing stats page pattern).

**Tech Stack:** Laravel 10, Eloquent, MySQL (prod) / SQLite in-memory (tests), Blade, ApexCharts 3.28.5, PHPUnit feature tests with `RefreshDatabase`.

## Global Constraints

- PHP 8.1+, Laravel 10. Use `match` expressions (PHP 8 available).
- Bid status categories are FIXED: qualified=`pending`, successful=`completed`, failed=`failed`+`expired`.
- USD value per project = `min_budget × (exchange_rate ?? 1)`, then `× 10` when `type === 'hourly'`.
- No `bids` table changes. No backfill of `skills`/`exchange_rate` for existing rows.
- All `/stats*` routes live inside the existing `Route::middleware(['auth'])` group in `routes/web.php` (NOT admin-gated).
- Tests: `use RefreshDatabase;`, authenticate via `actingAs(User::factory()->create())`, freeze time with `Carbon::setTestNow(...)`. Run a single test with `php artisan test --filter=testName`.

---

### Task 1: Schema + model casts + factory defaults

**Files:**
- Create: `database/migrations/2026_07_15_010000_add_skills_and_exchange_rate_to_proposals.php`
- Modify: `app/Models/Proposal.php`
- Modify: `database/factories/ProposalFactory.php`
- Test: `tests/Feature/ProposalSkillsColumnTest.php`

**Interfaces:**
- Produces: `proposals.skills` (json, nullable, cast to `array`), `proposals.exchange_rate` (double, nullable, default 1). `ProposalFactory` now sets `country`, `currency_name`, `exchange_rate`, `skills`.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/ProposalSkillsColumnTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Proposal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProposalSkillsColumnTest extends TestCase
{
    use RefreshDatabase;

    public function test_proposal_persists_skills_array_and_exchange_rate(): void
    {
        $p = Proposal::factory()->create([
            'skills' => ['php', 'vue'],
            'exchange_rate' => 1.5,
        ]);

        $fresh = Proposal::find($p->id);

        $this->assertSame(['php', 'vue'], $fresh->skills);
        $this->assertEquals(1.5, $fresh->exchange_rate);
    }

    public function test_skills_defaults_to_empty_array_via_factory(): void
    {
        $p = Proposal::factory()->create();
        $this->assertIsArray($p->skills);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=ProposalSkillsColumnTest`
Expected: FAIL — no such column `skills` / `exchange_rate`.

- [ ] **Step 3: Create the migration**

Create `database/migrations/2026_07_15_010000_add_skills_and_exchange_rate_to_proposals.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('proposals', function (Blueprint $table) {
            $table->json('skills')->nullable();
            $table->double('exchange_rate')->nullable()->default(1);
        });
    }

    public function down(): void
    {
        Schema::table('proposals', function (Blueprint $table) {
            $table->dropColumn(['skills', 'exchange_rate']);
        });
    }
};
```

- [ ] **Step 4: Add the cast to the Proposal model**

In `app/Models/Proposal.php`, add a `$casts` property inside the class (below `use HasFactory;`):

```php
    protected $casts = [
        'skills' => 'array',
    ];
```

- [ ] **Step 5: Update ProposalFactory defaults**

Replace the `definition()` return array in `database/factories/ProposalFactory.php` with:

```php
        return [
            'project_id' => $this->faker->numberBetween(1000, 9999),
            'title' => $this->faker->sentence(4),
            'description' => $this->faker->paragraph(),
            'min_budget' => 100,
            'max_budget' => 500,
            'type' => 'fixed',
            'country' => $this->faker->country(),
            'currency_name' => 'USD',
            'exchange_rate' => 1,
            'skills' => [],
        ];
```

- [ ] **Step 6: Run test to verify it passes**

Run: `php artisan test --filter=ProposalSkillsColumnTest`
Expected: PASS (2 tests).

- [ ] **Step 7: Commit**

```bash
git add database/migrations/2026_07_15_010000_add_skills_and_exchange_rate_to_proposals.php app/Models/Proposal.php database/factories/ProposalFactory.php tests/Feature/ProposalSkillsColumnTest.php
git commit -m "feat: add skills and exchange_rate columns to proposals"
```

---

### Task 2: Crawler captures exchange_rate + skills

**Files:**
- Modify: `app/Http/Controllers/ProposalController.php` (proposal-building block, ~line 266–269)
- Test: `tests/Feature/CrawlerCapturesSkillsTest.php`

**Interfaces:**
- Consumes: `proposals.skills`, `proposals.exchange_rate` (Task 1).
- Produces: crawled proposals now store `exchange_rate` from API `currency.exchange_rate` and `skills` from API `jobs[]` names.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/CrawlerCapturesSkillsTest.php`. It fakes both Freelancer HTTP calls and stops queued jobs, then runs the crawler:

```php
<?php

namespace Tests\Feature;

use App\Http\Controllers\ProposalController;
use App\Models\Filter;
use App\Models\Proposal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class CrawlerCapturesSkillsTest extends TestCase
{
    use RefreshDatabase;

    public function test_crawler_stores_exchange_rate_and_skills(): void
    {
        Queue::fake(); // prevent OpenAIJob from running

        Filter::factory()->create([
            'id' => 1,
            'crawler_on' => 1,
            'useminfix' => 0,
            'useminhour' => 0,
            'usekeywords' => 0,
            'usecountries' => 0,
        ]);

        Http::fake([
            '*support*' => Http::response(['result' => null], 200),
            '*projects/active*' => Http::response([
                'status' => 'success',
                'result' => [
                    'projects' => [[
                        'id' => 555,
                        'title' => 'Build a Laravel API',
                        'description' => 'Nice clean project description',
                        'seo_url' => 'build-laravel-api',
                        'type' => 'fixed',
                        'language' => 'en',
                        'owner_id' => 42,
                        'time_submitted' => 1700000000,
                        'budget' => ['minimum' => 250, 'maximum' => 750],
                        'currency' => ['code' => 'EUR', 'sign' => '€', 'country' => 'Germany', 'exchange_rate' => 1.1],
                        'upgrades' => ['NDA' => false, 'sealed' => false],
                        'jobs' => [
                            ['id' => 1, 'name' => 'PHP'],
                            ['id' => 2, 'name' => 'Laravel'],
                        ],
                    ]],
                ],
            ], 200),
        ]);

        (new ProposalController())->getProposals();

        $proposal = Proposal::where('project_id', 555)->first();
        $this->assertNotNull($proposal);
        $this->assertEquals(1.1, $proposal->exchange_rate);
        $this->assertSame(['PHP', 'Laravel'], $proposal->skills);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=CrawlerCapturesSkillsTest`
Expected: FAIL — `exchange_rate` is default `1` (or null) and `skills` is null, because the crawler never sets them.

- [ ] **Step 3: Wire the two fields in the crawler**

In `app/Http/Controllers/ProposalController.php`, find the block that ends with `$proposal->country = $country->country;` (immediately before `$proposal->save();`, ~line 267). Add these two lines right after it:

```php
                    /// [Exchange rate → USD]
                    $proposal->exchange_rate = $project['currency']['exchange_rate'] ?? 1;
                    /// [Skills]
                    $proposal->skills = collect($project['jobs'] ?? [])->pluck('name')->values()->all();
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=CrawlerCapturesSkillsTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/ProposalController.php tests/Feature/CrawlerCapturesSkillsTest.php
git commit -m "feat: capture exchange_rate and skills from freelancer API"
```

---

### Task 3: StatisticsController scaffold + route + dashboard shell

**Files:**
- Create: `app/Http/Controllers/StatisticsController.php`
- Modify: `routes/web.php` (repoint `/stats`, add sub-routes)
- Modify: `resources/views/content/pages/stats.blade.php` (replace with dashboard shell)
- Test: `tests/Feature/StatisticsPageTest.php`

**Interfaces:**
- Produces: `StatisticsController::index()` renders `content.pages.stats`. Routes: `GET /stats` (page), `GET /stats/bids`, `GET /stats/value`, `GET /stats/last24h`, `GET /stats/countries` (JSON, stubbed here, filled in Tasks 4–7).

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/StatisticsPageTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StatisticsPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_stats_page_requires_auth(): void
    {
        $this->get('/stats')->assertRedirect('/login');
    }

    public function test_stats_page_renders_for_authenticated_user(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/stats')
            ->assertOk()
            ->assertSee('Statistics');
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=StatisticsPageTest`
Expected: `test_stats_page_renders_for_authenticated_user` FAILS asserting "Statistics" is absent (old view says "Stats"), or the controller does not yet exist.

- [ ] **Step 3: Create the controller with stubbed endpoints**

Create `app/Http/Controllers/StatisticsController.php`:

```php
<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class StatisticsController extends Controller
{
    public function index()
    {
        return view('content.pages.stats');
    }

    public function bids(Request $request)
    {
        return response()->json([]);
    }

    public function value(Request $request)
    {
        return response()->json([]);
    }

    public function last24h(Request $request)
    {
        return response()->json([
            'value_posted_usd' => 0,
            'value_awarded_usd' => 0,
            'skills' => [],
        ]);
    }

    public function countries(Request $request)
    {
        return response()->json([]);
    }
}
```

- [ ] **Step 4: Register the routes**

In `routes/web.php`, add the import near the other controller imports (after line 5):

```php
use App\Http\Controllers\StatisticsController;
```

Replace the existing stats route (line 45):

```php
    Route::get('/stats', [BidController::class, 'stats'])->name('statistics');
```

with:

```php
    Route::get('/stats', [StatisticsController::class, 'index'])->name('statistics');
    Route::get('/stats/bids', [StatisticsController::class, 'bids'])->name('stats.bids');
    Route::get('/stats/value', [StatisticsController::class, 'value'])->name('stats.value');
    Route::get('/stats/last24h', [StatisticsController::class, 'last24h'])->name('stats.last24h');
    Route::get('/stats/countries', [StatisticsController::class, 'countries'])->name('stats.countries');
```

- [ ] **Step 5: Replace the view with the dashboard shell**

Overwrite `resources/views/content/pages/stats.blade.php` with a minimal shell (full charts wired in Task 8):

```blade
@extends('layouts/layoutMaster')

@section('title', 'Statistics')

@section('vendor-script')
    <script src="{{ asset('assets/vendor/libs/apex-charts/apexcharts.js') }}"></script>
@endsection

@section('content')
    <h4 class="page-title">Statistics</h4>
    <div class="row gy-4" id="statistics-dashboard">
        <div class="col-12"><p class="text-muted">Loading…</p></div>
    </div>
@endsection
```

- [ ] **Step 6: Run test to verify it passes**

Run: `php artisan test --filter=StatisticsPageTest`
Expected: PASS (2 tests).

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/StatisticsController.php routes/web.php resources/views/content/pages/stats.blade.php tests/Feature/StatisticsPageTest.php
git commit -m "feat: add StatisticsController scaffold and dashboard shell"
```

---

### Task 4: `/stats/bids` endpoint + bucketing helpers

**Files:**
- Modify: `app/Http/Controllers/StatisticsController.php`
- Test: `tests/Feature/StatisticsBidsTest.php`

**Interfaces:**
- Produces (private helpers consumed by Tasks 5–7):
  - `resolveGranularity(Request $request): string` — one of `hourly|daily|weekly|monthly`, default `daily`.
  - `resolveRange(Request $request): array` — `[Carbon $from, Carbon $to]`, default last 30 days.
  - `bucketKey(Carbon $dt, string $granularity): string`
  - `bucketSequence(Carbon $from, Carbon $to, string $granularity): array` — ordered bucket-key strings.
  - `statusCategory(string $status): ?string` — `qualified|successful|failed|null`.
- Produces (endpoint): `GET /stats/bids` → `[{ bucket, qualified, successful, failed }]`.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/StatisticsBidsTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Bid;
use App\Models\Proposal;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StatisticsBidsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-07-15 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function seedBids(): void
    {
        $fixed = Proposal::factory()->create(['type' => 'fixed']);
        $hourly = Proposal::factory()->create(['type' => 'hourly']);

        Bid::factory()->create(['proposal_id' => $fixed->id, 'bid_status' => 'pending', 'created_at' => '2026-07-10 09:00:00']);
        Bid::factory()->create(['proposal_id' => $fixed->id, 'bid_status' => 'completed', 'created_at' => '2026-07-10 10:00:00']);
        Bid::factory()->create(['proposal_id' => $fixed->id, 'bid_status' => 'failed', 'created_at' => '2026-07-10 11:00:00']);
        Bid::factory()->create(['proposal_id' => $fixed->id, 'bid_status' => 'expired', 'created_at' => '2026-07-10 12:00:00']);
        Bid::factory()->create(['proposal_id' => $hourly->id, 'bid_status' => 'pending', 'created_at' => '2026-07-10 09:30:00']);
    }

    public function test_requires_auth(): void
    {
        $this->getJson('/stats/bids')->assertUnauthorized();
    }

    public function test_fixed_type_groups_status_categories_daily(): void
    {
        $this->seedBids();

        $res = $this->actingAs(User::factory()->create())
            ->getJson('/stats/bids?type=fixed&granularity=daily&from=2026-07-10&to=2026-07-10')
            ->assertOk();

        $day = collect($res->json())->firstWhere('bucket', '2026-07-10');
        $this->assertEquals(1, $day['qualified']);   // pending
        $this->assertEquals(1, $day['successful']);  // completed
        $this->assertEquals(2, $day['failed']);      // failed + expired
    }

    public function test_type_all_includes_hourly(): void
    {
        $this->seedBids();

        $res = $this->actingAs(User::factory()->create())
            ->getJson('/stats/bids?type=all&granularity=daily&from=2026-07-10&to=2026-07-10')
            ->assertOk();

        $day = collect($res->json())->firstWhere('bucket', '2026-07-10');
        $this->assertEquals(2, $day['qualified']); // fixed pending + hourly pending
    }

    public function test_zero_filled_buckets_present(): void
    {
        $this->seedBids();

        $res = $this->actingAs(User::factory()->create())
            ->getJson('/stats/bids?type=fixed&granularity=daily&from=2026-07-10&to=2026-07-12')
            ->assertOk();

        $this->assertCount(3, $res->json()); // 3 days
        $empty = collect($res->json())->firstWhere('bucket', '2026-07-11');
        $this->assertEquals(0, $empty['qualified']);
    }
}
```

Note: unauthenticated JSON requests to a `web`-guard route return `401` via `assertUnauthorized()` because `getJson` sends `Accept: application/json`.

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=StatisticsBidsTest`
Expected: FAIL — endpoint returns `[]`, assertions on counts fail.

- [ ] **Step 3: Add helpers + implement `bids()`**

In `app/Http/Controllers/StatisticsController.php`, add `use App\Models\Bid;` and `use Carbon\Carbon;` at the top. Replace the stub `bids()` method and add the private helpers:

```php
    public function bids(Request $request)
    {
        $granularity = $this->resolveGranularity($request);
        [$from, $to] = $this->resolveRange($request);
        $type = in_array($request->query('type'), ['fixed', 'hourly'], true)
            ? $request->query('type')
            : 'all';

        $query = Bid::query()
            ->join('proposals', 'bids.proposal_id', '=', 'proposals.id')
            ->whereBetween('bids.created_at', [$from, $to])
            ->select('bids.created_at as created_at', 'bids.bid_status as bid_status');

        if ($type !== 'all') {
            $query->where('proposals.type', $type);
        }

        $data = [];
        foreach ($this->bucketSequence($from, $to, $granularity) as $key) {
            $data[$key] = ['bucket' => $key, 'qualified' => 0, 'successful' => 0, 'failed' => 0];
        }

        foreach ($query->get() as $row) {
            $key = $this->bucketKey(Carbon::parse($row->created_at), $granularity);
            if (!isset($data[$key])) {
                continue;
            }
            $category = $this->statusCategory($row->bid_status);
            if ($category) {
                $data[$key][$category]++;
            }
        }

        return response()->json(array_values($data));
    }

    private function statusCategory(string $status): ?string
    {
        return match ($status) {
            'pending' => 'qualified',
            'completed' => 'successful',
            'failed', 'expired' => 'failed',
            default => null,
        };
    }

    private function resolveGranularity(Request $request): string
    {
        $g = $request->query('granularity', 'daily');
        return in_array($g, ['hourly', 'daily', 'weekly', 'monthly'], true) ? $g : 'daily';
    }

    private function resolveRange(Request $request): array
    {
        $to = $request->filled('to')
            ? Carbon::parse($request->query('to'))->endOfDay()
            : Carbon::now();
        $from = $request->filled('from')
            ? Carbon::parse($request->query('from'))->startOfDay()
            : $to->copy()->subDays(30)->startOfDay();

        return [$from, $to];
    }

    private function bucketKey(Carbon $dt, string $granularity): string
    {
        return match ($granularity) {
            'hourly' => $dt->format('Y-m-d H:00'),
            'weekly' => $dt->format('o-\WW'),
            'monthly' => $dt->format('Y-m'),
            default => $dt->format('Y-m-d'),
        };
    }

    private function bucketSequence(Carbon $from, Carbon $to, string $granularity): array
    {
        $cursor = match ($granularity) {
            'hourly' => $from->copy()->startOfHour(),
            'weekly' => $from->copy()->startOfWeek(),
            'monthly' => $from->copy()->startOfMonth(),
            default => $from->copy()->startOfDay(),
        };

        $keys = [];
        while ($cursor <= $to) {
            $keys[] = $this->bucketKey($cursor, $granularity);
            match ($granularity) {
                'hourly' => $cursor->addHour(),
                'weekly' => $cursor->addWeek(),
                'monthly' => $cursor->addMonth(),
                default => $cursor->addDay(),
            };
        }

        return $keys;
    }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=StatisticsBidsTest`
Expected: PASS (4 tests).

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/StatisticsController.php tests/Feature/StatisticsBidsTest.php
git commit -m "feat: add /stats/bids endpoint with time bucketing"
```

---

### Task 5: `/stats/value` endpoint

**Files:**
- Modify: `app/Http/Controllers/StatisticsController.php`
- Test: `tests/Feature/StatisticsValueTest.php`

**Interfaces:**
- Consumes: helpers from Task 4 (`resolveGranularity`, `resolveRange`, `bucketKey`, `bucketSequence`).
- Produces: `GET /stats/value` → `[{ bucket, placed_usd, failed_usd }]`. placed = status ∈ {pending, completed}; failed = status ∈ {failed, expired}. Per-bid value = `min_budget × (exchange_rate ?? 1)`, `× 10` if hourly.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/StatisticsValueTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Bid;
use App\Models\Proposal;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StatisticsValueTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-07-15 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_value_endpoint_computes_usd_with_hourly_multiplier(): void
    {
        // fixed: 100 * 2 = 200 USD, completed -> placed
        $fixed = Proposal::factory()->create(['type' => 'fixed', 'min_budget' => 100, 'exchange_rate' => 2]);
        Bid::factory()->create(['proposal_id' => $fixed->id, 'bid_status' => 'completed', 'created_at' => '2026-07-10 10:00:00']);

        // hourly: 50 * 1 * 10 = 500 USD, failed -> failed
        $hourly = Proposal::factory()->create(['type' => 'hourly', 'min_budget' => 50, 'exchange_rate' => 1]);
        Bid::factory()->create(['proposal_id' => $hourly->id, 'bid_status' => 'failed', 'created_at' => '2026-07-10 11:00:00']);

        $res = $this->actingAs(User::factory()->create())
            ->getJson('/stats/value?granularity=daily&from=2026-07-10&to=2026-07-10')
            ->assertOk();

        $day = collect($res->json())->firstWhere('bucket', '2026-07-10');
        $this->assertEquals(200, $day['placed_usd']);
        $this->assertEquals(500, $day['failed_usd']);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=StatisticsValueTest`
Expected: FAIL — endpoint returns `[]`.

- [ ] **Step 3: Implement `value()`**

Replace the stub `value()` in `app/Http/Controllers/StatisticsController.php`:

```php
    public function value(Request $request)
    {
        $granularity = $this->resolveGranularity($request);
        [$from, $to] = $this->resolveRange($request);

        $rows = Bid::query()
            ->join('proposals', 'bids.proposal_id', '=', 'proposals.id')
            ->whereBetween('bids.created_at', [$from, $to])
            ->select(
                'bids.created_at as created_at',
                'bids.bid_status as bid_status',
                'proposals.min_budget as min_budget',
                'proposals.type as type',
                'proposals.exchange_rate as exchange_rate'
            )
            ->get();

        $data = [];
        foreach ($this->bucketSequence($from, $to, $granularity) as $key) {
            $data[$key] = ['bucket' => $key, 'placed_usd' => 0, 'failed_usd' => 0];
        }

        foreach ($rows as $row) {
            $key = $this->bucketKey(Carbon::parse($row->created_at), $granularity);
            if (!isset($data[$key])) {
                continue;
            }
            $usd = ($row->min_budget ?? 0) * ($row->exchange_rate ?? 1);
            if ($row->type === 'hourly') {
                $usd *= 10;
            }

            if (in_array($row->bid_status, ['pending', 'completed'], true)) {
                $data[$key]['placed_usd'] += $usd;
            } elseif (in_array($row->bid_status, ['failed', 'expired'], true)) {
                $data[$key]['failed_usd'] += $usd;
            }
        }

        foreach ($data as $key => $row) {
            $data[$key]['placed_usd'] = round($row['placed_usd'], 2);
            $data[$key]['failed_usd'] = round($row['failed_usd'], 2);
        }

        return response()->json(array_values($data));
    }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=StatisticsValueTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/StatisticsController.php tests/Feature/StatisticsValueTest.php
git commit -m "feat: add /stats/value endpoint with USD conversion"
```

---

### Task 6: `/stats/last24h` endpoint

**Files:**
- Modify: `app/Http/Controllers/StatisticsController.php`
- Test: `tests/Feature/StatisticsLast24hTest.php`

**Interfaces:**
- Consumes: `Proposal` `bid` relation, `skills` cast, `exchange_rate`.
- Produces: `GET /stats/last24h` → `{ value_posted_usd, value_awarded_usd, skills: [{ name, count }] }`. Scope = proposals `created_at >= now()-24h`. Awarded = proposal's bid `bid_status === 'completed'`. Skills aggregated over awarded proposals only.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/StatisticsLast24hTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Bid;
use App\Models\Proposal;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StatisticsLast24hTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-07-15 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_last24h_totals_and_skills(): void
    {
        // Recent + awarded (completed): 100 * 1 = 100 USD, skills php/laravel
        $awarded = Proposal::factory()->create([
            'type' => 'fixed', 'min_budget' => 100, 'exchange_rate' => 1,
            'skills' => ['php', 'laravel'], 'created_at' => Carbon::now()->subHours(2),
        ]);
        Bid::factory()->create(['proposal_id' => $awarded->id, 'bid_status' => 'completed']);

        // Recent + not awarded (pending): 200 USD, posted only
        $pending = Proposal::factory()->create([
            'type' => 'fixed', 'min_budget' => 200, 'exchange_rate' => 1,
            'skills' => ['react'], 'created_at' => Carbon::now()->subHours(3),
        ]);
        Bid::factory()->create(['proposal_id' => $pending->id, 'bid_status' => 'pending']);

        // Old proposal (>24h): excluded entirely
        Proposal::factory()->create(['min_budget' => 999, 'created_at' => Carbon::now()->subDays(3)]);

        $res = $this->actingAs(User::factory()->create())
            ->getJson('/stats/last24h')
            ->assertOk();

        $this->assertEquals(300, $res->json('value_posted_usd')); // 100 + 200
        $this->assertEquals(100, $res->json('value_awarded_usd')); // awarded only
        $skills = collect($res->json('skills'));
        $this->assertEquals(1, $skills->firstWhere('name', 'php')['count']);
        $this->assertEquals(1, $skills->firstWhere('name', 'laravel')['count']);
        $this->assertNull($skills->firstWhere('name', 'react')); // not awarded
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=StatisticsLast24hTest`
Expected: FAIL — stub returns zeros/empty.

- [ ] **Step 3: Implement `last24h()`**

Add `use App\Models\Proposal;` at the top of `StatisticsController.php`. Replace the stub `last24h()`:

```php
    public function last24h(Request $request)
    {
        $since = Carbon::now()->subDay();
        $proposals = Proposal::with('bid')->where('created_at', '>=', $since)->get();

        $posted = 0;
        $awarded = 0;
        $skills = [];

        foreach ($proposals as $proposal) {
            $usd = ($proposal->min_budget ?? 0) * ($proposal->exchange_rate ?? 1);
            if ($proposal->type === 'hourly') {
                $usd *= 10;
            }
            $posted += $usd;

            if ($proposal->bid && $proposal->bid->bid_status === 'completed') {
                $awarded += $usd;
                foreach (($proposal->skills ?? []) as $skill) {
                    $skills[$skill] = ($skills[$skill] ?? 0) + 1;
                }
            }
        }

        arsort($skills);
        $skillsOut = [];
        foreach ($skills as $name => $count) {
            $skillsOut[] = ['name' => $name, 'count' => $count];
        }

        return response()->json([
            'value_posted_usd' => round($posted, 2),
            'value_awarded_usd' => round($awarded, 2),
            'skills' => $skillsOut,
        ]);
    }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=StatisticsLast24hTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/StatisticsController.php tests/Feature/StatisticsLast24hTest.php
git commit -m "feat: add /stats/last24h snapshot endpoint"
```

---

### Task 7: `/stats/countries` endpoint

**Files:**
- Modify: `app/Http/Controllers/StatisticsController.php`
- Test: `tests/Feature/StatisticsCountriesTest.php`

**Interfaces:**
- Consumes: `resolveRange` (Task 4), `Proposal.country`.
- Produces: `GET /stats/countries` → `[{ country, count }]`, ordered by count desc, ≤10 rows, filtered by date range on `created_at`.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/StatisticsCountriesTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Proposal;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StatisticsCountriesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-07-15 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_countries_top_list_respects_date_range(): void
    {
        Proposal::factory()->count(3)->create(['country' => 'India', 'created_at' => '2026-07-10 10:00:00']);
        Proposal::factory()->count(1)->create(['country' => 'USA', 'created_at' => '2026-07-10 10:00:00']);
        // Outside range → excluded
        Proposal::factory()->create(['country' => 'India', 'created_at' => '2026-01-01 10:00:00']);

        $res = $this->actingAs(User::factory()->create())
            ->getJson('/stats/countries?from=2026-07-01&to=2026-07-31')
            ->assertOk();

        $json = $res->json();
        $this->assertEquals('India', $json[0]['country']);
        $this->assertEquals(3, $json[0]['count']);
        $this->assertEquals('USA', $json[1]['country']);
        $this->assertEquals(1, $json[1]['count']);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=StatisticsCountriesTest`
Expected: FAIL — stub returns `[]`.

- [ ] **Step 3: Implement `countries()`**

Replace the stub `countries()` in `StatisticsController.php`:

```php
    public function countries(Request $request)
    {
        [$from, $to] = $this->resolveRange($request);

        $rows = Proposal::query()
            ->whereBetween('created_at', [$from, $to])
            ->whereNotNull('country')
            ->where('country', '!=', '')
            ->selectRaw('country, COUNT(*) as count')
            ->groupBy('country')
            ->orderByDesc('count')
            ->limit(10)
            ->get();

        return response()->json(
            $rows->map(fn ($r) => ['country' => $r->country, 'count' => (int) $r->count])->all()
        );
    }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=StatisticsCountriesTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/StatisticsController.php tests/Feature/StatisticsCountriesTest.php
git commit -m "feat: add /stats/countries top-10 endpoint"
```

---

### Task 8: Dashboard frontend (cards + ApexCharts + controls)

**Files:**
- Modify: `resources/views/content/pages/stats.blade.php`
- Test: manual (browser) — no automated JS test in this stack.

**Interfaces:**
- Consumes: `GET /stats/bids`, `/stats/value`, `/stats/last24h`, `/stats/countries`.

- [ ] **Step 1: Replace the view with the full dashboard**

Overwrite `resources/views/content/pages/stats.blade.php`:

```blade
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
        <h6 class="text-muted">Fixed</h6>
        <div id="chart-fixed"></div>
        <h6 class="text-muted mt-3">Hourly</h6>
        <div id="chart-hourly"></div>
        <h6 class="text-muted mt-3">All Bids</h6>
        <div id="chart-all"></div>
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
                    { name: 'Qualified', data: rows.map(r => r.qualified) },
                    { name: 'Successful', data: rows.map(r => r.successful) },
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
```

- [ ] **Step 2: Verify the full test suite still passes**

Run: `php artisan test`
Expected: all Statistics tests + pre-existing tests PASS.

- [ ] **Step 3: Manual browser check**

Start the app (`php artisan serve`), log in, open `/stats`. Confirm: three outcome charts render, granularity buttons re-fetch, value chart shows placed/failed, countries bar renders, 24h cards show dollar amounts, skills bar renders. (Charts may be empty on a fresh DB — that is correct; seed a few bids/proposals to see data.)

- [ ] **Step 4: Commit**

```bash
git add resources/views/content/pages/stats.blade.php
git commit -m "feat: build statistics dashboard UI with ApexCharts"
```

---

## Self-Review

**Spec coverage:**
- §1 Fixed bids chart → Task 4 (`type=fixed`) + Task 8 (`chart-fixed`). ✓
- §2 Hourly bids chart → Task 4 (`type=hourly`) + Task 8 (`chart-hourly`). ✓
- §3 All bids chart → Task 4 (`type=all`) + Task 8 (`chart-all`). ✓
- §4 Project value (USD, hourly×10, placed vs failed) → Task 5 + Task 8 (`chart-value`). ✓
- §5 Last-24h cards (posted / awarded / skills) → Task 6 + Task 8 (cards + `chart-skills`). ✓
- §6 Top-10 countries → Task 7 + Task 8 (`chart-countries`). ✓
- Schema (`skills`, `exchange_rate`) → Task 1. ✓ Crawler capture → Task 2. ✓
- Granularity toggle hourly/daily/weekly/monthly → Task 4 helpers + Task 8 buttons. ✓
- Status mapping locked → Task 4 `statusCategory`. ✓
- Zero-fill empty buckets → Task 4 `bucketSequence`. ✓

**Deviations from spec (deliberate):**
- Bucketing done in PHP instead of MySQL `DATE_FORMAT` — SQLite test DB lacks `DATE_FORMAT`; PHP grouping is DB-portable and identical in output.
- Dashboard JS inlined in Blade `@section('page-script')` instead of a compiled `statistics.js` via Laravel Mix — matches the existing stats page pattern and needs no build step.
- Date-range pickers from the spec are omitted from the initial UI (endpoints already accept `from`/`to`); granularity toggle ships. Date pickers can be added later without backend change. (Noted so it is not a silent gap.)

**Placeholder scan:** No TBD/TODO; every code step shows full code. ✓

**Type consistency:** Helper names (`resolveGranularity`, `resolveRange`, `bucketKey`, `bucketSequence`, `statusCategory`) defined in Task 4 and consumed identically in Tasks 5–7. Endpoint JSON keys (`bucket`, `qualified`, `successful`, `failed`, `placed_usd`, `failed_usd`, `value_posted_usd`, `value_awarded_usd`, `skills[].name`, `skills[].count`, `country`, `count`) match between controller and Blade JS. ✓

## Notes / Known Limits

- `skills` and true `exchange_rate` are only populated for projects crawled after Task 1–2 deploy (no backfill). 24h skills chart and USD accuracy improve over time.
- Awarded = local `completed` status, not a live Freelancer award lookup.
- Date-range filtering is wired in the backend for all range-aware endpoints but the UI ships only the granularity toggle in this plan.
