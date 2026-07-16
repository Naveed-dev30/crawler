# Award Tracking Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Track which placed bids the client awarded (via a Freelancer API poll on a 30-min cron), store the awarded price, and drive the blue "Awarded" chart series and the 24h awarded-value card from real awards.

**Architecture:** Add `awarded` + `awarded_price` columns to `bids`. A `BidAwardChecker` service (run by an artisan command and a Kernel schedule) polls the Freelancer bids API for `award_status` on all `completed`+`awarded=false` bids, batched by project. The statistics `bids()` endpoint returns disjoint Awarded/Placed/Failed counts, `last24h()` computes awarded value from `awarded_price × exchange_rate`, and the stats page JS renders the renamed series.

**Tech Stack:** Laravel 10, Eloquent, Guzzle via `Http`, artisan console command, `Kernel` scheduler, PHPUnit feature tests (SQLite in-memory, `Http::fake`).

## Global Constraints

- `bids.awarded`: boolean, not null, default `false`. `bids.awarded_price`: double, nullable, stored in **native currency**.
- Poll set: `bid_status = 'completed'` AND `awarded = false`, all ages, cron **every 30 min**, batched **100 project_ids per API call**.
- Award detection: a returned bid counts as awarded when `award_status === 'awarded'`. `awarded_price = returned amount ?? bid.price`.
- Chart series are **disjoint**: `awarded == true` → Awarded; `bid_status == 'completed'` AND not awarded → Placed; `bid_status` in {`failed`, `expired`} → Failed; `pending` → skipped.
- Awarded USD = `(awarded_price ?? bid.price) × (exchange_rate ?? 1)` — **no hourly ×10**.
- Freelancer API field names (`award_status`, `amount`, `project_id`) are isolated in `BidAwardChecker` only.
- Run one test: `docker exec crawler-laravel.test-1 php artisan test --filter=Name`.

---

### Task 1: Schema + Bid model cast + factory

**Files:**
- Create: `database/migrations/2026_07_15_020000_add_awarded_to_bids.php`
- Modify: `app/Models/Bid.php`
- Modify: `database/factories/BidFactory.php`
- Test: `tests/Feature/BidAwardedColumnTest.php`

**Interfaces:**
- Produces: `bids.awarded` (bool, cast to boolean on the model, default false) and `bids.awarded_price` (double, nullable). `BidFactory` sets `awarded => false`.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/BidAwardedColumnTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Bid;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BidAwardedColumnTest extends TestCase
{
    use RefreshDatabase;

    public function test_bid_persists_awarded_and_price(): void
    {
        $bid = Bid::factory()->create(['awarded' => true, 'awarded_price' => 123.45]);

        $fresh = Bid::find($bid->id);

        $this->assertTrue($fresh->awarded);
        $this->assertEquals(123.45, $fresh->awarded_price);
    }

    public function test_awarded_defaults_to_false(): void
    {
        $bid = Bid::factory()->create();
        $this->assertFalse($bid->awarded);
        $this->assertNull($bid->awarded_price);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker exec crawler-laravel.test-1 php artisan test --filter=BidAwardedColumnTest`
Expected: FAIL — no such column `awarded`.

- [ ] **Step 3: Create the migration**

Create `database/migrations/2026_07_15_020000_add_awarded_to_bids.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bids', function (Blueprint $table) {
            $table->boolean('awarded')->default(false);
            $table->double('awarded_price')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('bids', function (Blueprint $table) {
            $table->dropColumn(['awarded', 'awarded_price']);
        });
    }
};
```

- [ ] **Step 4: Add the cast to the Bid model**

In `app/Models/Bid.php`, add a `$casts` property inside the class (after `use HasFactory, Notifiable;`):

```php
    protected $casts = [
        'awarded' => 'boolean',
    ];
```

- [ ] **Step 5: Update BidFactory**

In `database/factories/BidFactory.php`, add `'awarded' => false,` to the `definition()` return array (after the `admin_feedback` line):

```php
            'awarded' => false,
```

- [ ] **Step 6: Run test to verify it passes**

Run: `docker exec crawler-laravel.test-1 php artisan test --filter=BidAwardedColumnTest`
Expected: PASS (2 tests).

- [ ] **Step 7: Commit**

```bash
git add database/migrations/2026_07_15_020000_add_awarded_to_bids.php app/Models/Bid.php database/factories/BidFactory.php tests/Feature/BidAwardedColumnTest.php
git commit -m "feat: add awarded and awarded_price columns to bids"
```

---

### Task 2: BidAwardChecker service + command + schedule

**Files:**
- Create: `app/Services/BidAwardChecker.php`
- Create: `app/Console/Commands/CheckBidAwards.php`
- Modify: `app/Console/Kernel.php`
- Test: `tests/Feature/BidAwardCheckerTest.php`

**Interfaces:**
- Consumes: `bids.awarded`, `bids.awarded_price` (Task 1); `config('variables.flUserId')`, `config('variables.flKey')`; `Proposal.project_id`.
- Produces: `App\Services\BidAwardChecker::run(): void`; artisan command `bids:check-awards`.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/BidAwardCheckerTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Bid;
use App\Models\Proposal;
use App\Services\BidAwardChecker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BidAwardCheckerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['variables.flUserId' => '999', 'variables.flKey' => 'test-key']);
    }

    private function fakeBidsResponse(array $bids): void
    {
        Http::fake([
            '*bids*' => Http::response(['status' => 'success', 'result' => ['bids' => $bids]], 200),
        ]);
    }

    public function test_marks_awarded_and_stores_amount(): void
    {
        $this->fakeBidsResponse([
            ['project_id' => 555, 'award_status' => 'awarded', 'amount' => 320],
        ]);
        $p = Proposal::factory()->create(['project_id' => 555]);
        $bid = Bid::factory()->create(['proposal_id' => $p->id, 'bid_status' => 'completed', 'awarded' => false, 'price' => 100]);

        (new BidAwardChecker())->run();

        $bid->refresh();
        $this->assertTrue($bid->awarded);
        $this->assertEquals(320, $bid->awarded_price);
    }

    public function test_falls_back_to_bid_price_when_amount_missing(): void
    {
        $this->fakeBidsResponse([
            ['project_id' => 555, 'award_status' => 'awarded'], // no amount
        ]);
        $p = Proposal::factory()->create(['project_id' => 555]);
        $bid = Bid::factory()->create(['proposal_id' => $p->id, 'bid_status' => 'completed', 'awarded' => false, 'price' => 100]);

        (new BidAwardChecker())->run();

        $bid->refresh();
        $this->assertTrue($bid->awarded);
        $this->assertEquals(100, $bid->awarded_price);
    }

    public function test_pending_award_leaves_bid_unawarded(): void
    {
        $this->fakeBidsResponse([
            ['project_id' => 555, 'award_status' => 'pending', 'amount' => 320],
        ]);
        $p = Proposal::factory()->create(['project_id' => 555]);
        $bid = Bid::factory()->create(['proposal_id' => $p->id, 'bid_status' => 'completed', 'awarded' => false]);

        (new BidAwardChecker())->run();

        $bid->refresh();
        $this->assertFalse($bid->awarded);
        $this->assertNull($bid->awarded_price);
    }

    public function test_only_completed_unawarded_bids_are_polled(): void
    {
        // API says project 777 is awarded, but our bid there is 'failed' -> must not change
        $this->fakeBidsResponse([
            ['project_id' => 777, 'award_status' => 'awarded', 'amount' => 500],
        ]);
        $p = Proposal::factory()->create(['project_id' => 777]);
        $bid = Bid::factory()->create(['proposal_id' => $p->id, 'bid_status' => 'failed', 'awarded' => false]);

        (new BidAwardChecker())->run();

        $bid->refresh();
        $this->assertFalse($bid->awarded);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker exec crawler-laravel.test-1 php artisan test --filter=BidAwardCheckerTest`
Expected: FAIL — class `App\Services\BidAwardChecker` not found.

- [ ] **Step 3: Create the service**

Create `app/Services/BidAwardChecker.php`:

```php
<?php

namespace App\Services;

use App\Models\Bid;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class BidAwardChecker
{
    public function run(): void
    {
        $bids = Bid::where('bid_status', 'completed')
            ->where('awarded', false)
            ->with('proposal')
            ->get();

        // Index our bids by project_id (one bid per project per bidder).
        $byProject = [];
        foreach ($bids as $bid) {
            $pid = $bid->proposal->project_id ?? null;
            if ($pid) {
                $byProject[$pid] = $bid;
            }
        }

        if (empty($byProject)) {
            return;
        }

        $flUserId = config('variables.flUserId');
        $flKey = config('variables.flKey');

        foreach (array_chunk(array_keys($byProject), 100) as $chunk) {
            $query = 'compact=true&bidders[]=' . $flUserId;
            foreach ($chunk as $pid) {
                $query .= '&projects[]=' . $pid;
            }
            $url = 'https://www.freelancer.com/api/projects/0.1/bids/?' . $query;

            try {
                $response = Http::timeout(60)
                    ->withHeaders(['Freelancer-OAuth-V1' => $flKey])
                    ->get($url);

                if (!$response->successful()) {
                    Log::warning('Award check: HTTP ' . $response->status());
                    continue;
                }

                $returnedBids = $response->json('result.bids') ?? [];
                foreach ($returnedBids as $rb) {
                    $pid = $rb['project_id'] ?? null;
                    if (!$pid || !isset($byProject[$pid])) {
                        continue;
                    }
                    if (($rb['award_status'] ?? null) !== 'awarded') {
                        continue;
                    }

                    $bid = $byProject[$pid];
                    $bid->awarded = true;
                    $bid->awarded_price = $rb['amount'] ?? $bid->price;
                    $bid->save();
                }
            } catch (\Throwable $e) {
                Log::warning('Award check exception: ' . $e->getMessage());
                continue;
            }
        }
    }
}
```

- [ ] **Step 4: Create the artisan command**

Create `app/Console/Commands/CheckBidAwards.php`:

```php
<?php

namespace App\Console\Commands;

use App\Services\BidAwardChecker;
use Illuminate\Console\Command;

class CheckBidAwards extends Command
{
    protected $signature = 'bids:check-awards';

    protected $description = 'Poll Freelancer for award status of placed bids';

    public function handle(): int
    {
        (new BidAwardChecker())->run();
        $this->info('Award check complete.');

        return self::SUCCESS;
    }
}
```

- [ ] **Step 5: Schedule it every 30 minutes**

In `app/Console/Kernel.php`, add a second scheduled task inside `schedule()`, after the existing `getProposals` block:

```php
    $schedule
      ->call(function () {
        (new \App\Services\BidAwardChecker())->run();
      })
      ->everyThirtyMinutes();
```

- [ ] **Step 6: Run test to verify it passes**

Run: `docker exec crawler-laravel.test-1 php artisan test --filter=BidAwardCheckerTest`
Expected: PASS (4 tests).

- [ ] **Step 7: Commit**

```bash
git add app/Services/BidAwardChecker.php app/Console/Commands/CheckBidAwards.php app/Console/Kernel.php tests/Feature/BidAwardCheckerTest.php
git commit -m "feat: add BidAwardChecker cron and command"
```

---

### Task 3: `bids()` endpoint → Awarded/Placed/Failed + chart series

**Files:**
- Modify: `app/Http/Controllers/StatisticsController.php` (`bids()`, remove `statusCategory()`)
- Modify: `resources/views/content/pages/stats.blade.php` (`outcomeSeries`)
- Test: `tests/Feature/StatisticsBidsTest.php` (rewrite)

**Interfaces:**
- Consumes: `bids.awarded` (Task 1).
- Produces: `GET /stats/bids` → `[{ bucket, awarded, placed, failed }]`.

- [ ] **Step 1: Rewrite the test**

Replace the entire contents of `tests/Feature/StatisticsBidsTest.php`:

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

        // awarded (completed + awarded)
        Bid::factory()->create(['proposal_id' => $fixed->id, 'bid_status' => 'completed', 'awarded' => true, 'created_at' => '2026-07-10 09:00:00']);
        // placed (completed, not awarded)
        Bid::factory()->create(['proposal_id' => $fixed->id, 'bid_status' => 'completed', 'awarded' => false, 'created_at' => '2026-07-10 10:00:00']);
        // failed + expired
        Bid::factory()->create(['proposal_id' => $fixed->id, 'bid_status' => 'failed', 'created_at' => '2026-07-10 11:00:00']);
        Bid::factory()->create(['proposal_id' => $fixed->id, 'bid_status' => 'expired', 'created_at' => '2026-07-10 12:00:00']);
        // pending -> not shown
        Bid::factory()->create(['proposal_id' => $fixed->id, 'bid_status' => 'pending', 'created_at' => '2026-07-10 08:00:00']);
        // hourly awarded
        Bid::factory()->create(['proposal_id' => $hourly->id, 'bid_status' => 'completed', 'awarded' => true, 'created_at' => '2026-07-10 09:30:00']);
    }

    public function test_requires_auth(): void
    {
        $this->getJson('/stats/bids')->assertUnauthorized();
    }

    public function test_fixed_type_awarded_placed_failed_daily(): void
    {
        $this->seedBids();

        $res = $this->actingAs(User::factory()->create())
            ->getJson('/stats/bids?type=fixed&granularity=daily&from=2026-07-10&to=2026-07-10')
            ->assertOk();

        $day = collect($res->json())->firstWhere('bucket', '2026-07-10');
        $this->assertEquals(1, $day['awarded']); // completed+awarded
        $this->assertEquals(1, $day['placed']);  // completed, not awarded
        $this->assertEquals(2, $day['failed']);  // failed + expired
    }

    public function test_type_all_includes_hourly_awarded(): void
    {
        $this->seedBids();

        $res = $this->actingAs(User::factory()->create())
            ->getJson('/stats/bids?type=all&granularity=daily&from=2026-07-10&to=2026-07-10')
            ->assertOk();

        $day = collect($res->json())->firstWhere('bucket', '2026-07-10');
        $this->assertEquals(2, $day['awarded']); // fixed awarded + hourly awarded
    }

    public function test_zero_filled_buckets_present(): void
    {
        $this->seedBids();

        $res = $this->actingAs(User::factory()->create())
            ->getJson('/stats/bids?type=fixed&granularity=daily&from=2026-07-10&to=2026-07-12')
            ->assertOk();

        $this->assertCount(3, $res->json());
        $empty = collect($res->json())->firstWhere('bucket', '2026-07-11');
        $this->assertEquals(0, $empty['awarded']);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker exec crawler-laravel.test-1 php artisan test --filter=StatisticsBidsTest`
Expected: FAIL — response still has `qualified`/`successful` keys; `awarded`/`placed` are undefined.

- [ ] **Step 3: Rewrite `bids()` and remove `statusCategory()`**

In `app/Http/Controllers/StatisticsController.php`, replace the `bids()` method body's select + data-init + loop so it categorizes by awarded/placed/failed. The full method becomes:

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
            ->select('bids.created_at as created_at', 'bids.bid_status as bid_status', 'bids.awarded as awarded');

        if ($type !== 'all') {
            $query->where('proposals.type', $type);
        }

        $data = [];
        foreach ($this->bucketSequence($from, $to, $granularity) as $key) {
            $data[$key] = ['bucket' => $key, 'awarded' => 0, 'placed' => 0, 'failed' => 0];
        }

        foreach ($query->get() as $row) {
            $key = $this->bucketKey(Carbon::parse($row->created_at), $granularity);
            if (!isset($data[$key])) {
                continue;
            }
            if ($row->awarded) {
                $data[$key]['awarded']++;
            } elseif ($row->bid_status === 'completed') {
                $data[$key]['placed']++;
            } elseif (in_array($row->bid_status, ['failed', 'expired'], true)) {
                $data[$key]['failed']++;
            }
        }

        return response()->json(array_values($data));
    }
```

Then delete the now-unused `statusCategory()` method (the whole `private function statusCategory(string $status): ?string { ... }` block).

- [ ] **Step 4: Update the chart series in the view**

In `resources/views/content/pages/stats.blade.php`, replace the `outcomeSeries` function:

```js
            function outcomeSeries(rows) {
                return [
                    { name: 'Awarded', data: rows.map(r => r.awarded) },
                    { name: 'Placed', data: rows.map(r => r.placed) },
                    { name: 'Failed', data: rows.map(r => r.failed) },
                ];
            }
```

(ApexCharts' default palette keeps Awarded blue, Placed green, Failed orange — no color change needed.)

- [ ] **Step 5: Run test to verify it passes**

Run: `docker exec crawler-laravel.test-1 php artisan test --filter=StatisticsBidsTest`
Expected: PASS (4 tests).

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/StatisticsController.php resources/views/content/pages/stats.blade.php tests/Feature/StatisticsBidsTest.php
git commit -m "feat: bid outcomes chart shows awarded/placed/failed"
```

---

### Task 4: `last24h()` awarded value from awarded flag + price

**Files:**
- Modify: `app/Http/Controllers/StatisticsController.php` (`last24h()`)
- Test: `tests/Feature/StatisticsLast24hTest.php` (rewrite)

**Interfaces:**
- Consumes: `bids.awarded`, `bids.awarded_price` (Task 1); `Proposal.bid` relation, `exchange_rate`, `skills`.
- Produces: `GET /stats/last24h` → `{ value_posted_usd, value_awarded_usd, skills:[{name,count}] }` where awarded value = `(awarded_price ?? bid.price) × exchange_rate`.

- [ ] **Step 1: Rewrite the test**

Replace the entire contents of `tests/Feature/StatisticsLast24hTest.php`:

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

    public function test_awarded_value_uses_awarded_price_and_exchange_rate(): void
    {
        // Awarded: posted = 100*1 = 100; awarded = awarded_price(250)*1 = 250
        $awarded = Proposal::factory()->create([
            'type' => 'fixed', 'min_budget' => 100, 'exchange_rate' => 1,
            'skills' => ['php', 'laravel'], 'created_at' => Carbon::now()->subHours(2),
        ]);
        Bid::factory()->create(['proposal_id' => $awarded->id, 'bid_status' => 'completed', 'awarded' => true, 'awarded_price' => 250, 'price' => 90]);

        // Placed but not awarded: posted 200, not awarded
        $placed = Proposal::factory()->create([
            'type' => 'fixed', 'min_budget' => 200, 'exchange_rate' => 1,
            'skills' => ['react'], 'created_at' => Carbon::now()->subHours(3),
        ]);
        Bid::factory()->create(['proposal_id' => $placed->id, 'bid_status' => 'completed', 'awarded' => false]);

        // Old proposal (>24h): excluded
        Proposal::factory()->create(['min_budget' => 999, 'created_at' => Carbon::now()->subDays(3)]);

        $res = $this->actingAs(User::factory()->create())->getJson('/stats/last24h')->assertOk();

        $this->assertEquals(300, $res->json('value_posted_usd'));  // 100 + 200
        $this->assertEquals(250, $res->json('value_awarded_usd')); // awarded_price * exchange_rate
        $skills = collect($res->json('skills'));
        $this->assertEquals(1, $skills->firstWhere('name', 'php')['count']);
        $this->assertNull($skills->firstWhere('name', 'react')); // not awarded
    }

    public function test_awarded_value_falls_back_to_bid_price(): void
    {
        // awarded_price null -> use bid price 50; exchange_rate 2 -> 100
        $p = Proposal::factory()->create([
            'type' => 'fixed', 'min_budget' => 10, 'exchange_rate' => 2,
            'skills' => [], 'created_at' => Carbon::now()->subHours(1),
        ]);
        Bid::factory()->create(['proposal_id' => $p->id, 'bid_status' => 'completed', 'awarded' => true, 'awarded_price' => null, 'price' => 50]);

        $res = $this->actingAs(User::factory()->create())->getJson('/stats/last24h')->assertOk();

        $this->assertEquals(100, $res->json('value_awarded_usd')); // 50 * 2
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker exec crawler-laravel.test-1 php artisan test --filter=StatisticsLast24hTest`
Expected: FAIL — current `last24h()` computes awarded from `bid_status === 'completed'` and posted-value formula, so `value_awarded_usd` is 300 (both completed) not 250.

- [ ] **Step 3: Update `last24h()`**

In `app/Http/Controllers/StatisticsController.php`, replace the `foreach` body inside `last24h()` (the block that adds to `$awarded` and `$skills`) so awarded uses the `awarded` flag and `awarded_price`:

```php
        foreach ($proposals as $proposal) {
            $usd = ($proposal->min_budget ?? 0) * ($proposal->exchange_rate ?? 1);
            if ($proposal->type === 'hourly') {
                $usd *= 10;
            }
            $posted += $usd;

            if ($proposal->bid && $proposal->bid->awarded) {
                $native = $proposal->bid->awarded_price ?? $proposal->bid->price;
                $awarded += $native * ($proposal->exchange_rate ?? 1);
                foreach (($proposal->skills ?? []) as $skill) {
                    $skills[$skill] = ($skills[$skill] ?? 0) + 1;
                }
            }
        }
```

(The rest of the method — `$since`, the query, `arsort`, `$skillsOut`, and the JSON response — stays unchanged.)

- [ ] **Step 4: Run test to verify it passes**

Run: `docker exec crawler-laravel.test-1 php artisan test --filter=StatisticsLast24hTest`
Expected: PASS (2 tests).

- [ ] **Step 5: Run the full suite (regression)**

Run: `docker exec crawler-laravel.test-1 php artisan test`
Expected: all Award/Statistics/Bids/Crawler tests PASS (pre-existing `ExampleTest` GET `/`→302 remains the only failure).

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/StatisticsController.php tests/Feature/StatisticsLast24hTest.php
git commit -m "feat: 24h awarded value from awarded flag and awarded_price"
```

---

## Self-Review

**Spec coverage:**
- Schema `awarded` + `awarded_price` → Task 1. ✓
- Cron poll (completed+awarded=false, 30 min, batch 100, award_status/amount, fallback to bid price) → Task 2. ✓
- One-way (only polls awarded=false) → Task 2 query. ✓
- Chart series Awarded/Placed/Failed disjoint + JS rename → Task 3. ✓
- `last24h` awarded USD = `(awarded_price ?? price) × exchange_rate`, no ×10; skills from awarded → Task 4. ✓
- API field names isolated in `BidAwardChecker` → Task 2. ✓
- `value()` unchanged → not touched. ✓

**Placeholder scan:** No TBD/TODO; full code in every step. ✓

**Type consistency:** `bids()` JSON keys `awarded/placed/failed` match the view's `outcomeSeries` (`r.awarded/r.placed/r.failed`). `BidAwardChecker::run()` signature matches the command and Kernel calls. `awarded` cast to boolean (Task 1) used as truthy in Task 3/4. `awarded_price` read in Task 4. ✓

## Notes / Known Limits

- The Awarded series/card stay ~0 until the cron runs against the live Freelancer API (not running in the dev env). Optional demo: mark a few seeded `completed` bids `awarded` with an `awarded_price`.
- One bid per project per bidder is assumed when indexing API results by `project_id` (matches the crawler, which creates one proposal/bid per project).
- Revocation is not tracked (once `awarded = true`, the bid is no longer polled).
