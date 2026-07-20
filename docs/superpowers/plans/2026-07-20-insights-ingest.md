# Insights Ingest APIs Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ingest + read APIs for Freelancer insights (user stats snapshots and per-project bid insights with field-level audit log), mirroring the existing gamification ingest pattern.

**Architecture:** External crawler POSTs raw JSON to two token-protected ingest endpoints. `InsightsController` parses the serverData blob into a snapshot row (canonical columns + JSON sections + raw). `BidInsightsController` upserts per-project rows and writes field-level change rows for recurring fields. Read endpoints return JSON.

**Tech Stack:** Laravel 10, PHP 8.1, Eloquent, MySQL (sqlite in-memory for tests), PHPUnit feature tests.

**Spec:** `docs/superpowers/specs/2026-07-20-insights-ingest-design.md`

## Global Constraints

- Follow gamification pattern: `updateOrCreate` keyed on `scraped_at`, `raw` payload column, `{"success": true, ...}` responses.
- Auth: existing `gamification.token` middleware alias (`EnsureGamificationToken`), config `variables.gamificationIngestToken`. No new middleware, no new secrets.
- Ingest never 500s on malformed sections — null the column, keep `raw`.
- Tests use fixture `tests/Fixtures/user-stats-extracted.json` (already on disk, must be committed). Known fixture values: earnings total `$363,600.05`, past-30d `$0.00`, Bids Remaining `203`, Unearned Bids `1297`, overall ranking `"25%"`, `trendingSkills` has 2289 entries, `ratingPerSkill` has 33 entries, `bidsPerMilestone` is `null`.
- Run tests with `php artisan test --filter=<TestClass>`.
- `docs/` is gitignored; plan/spec commits need `git add -f` (fixture path `tests/Fixtures/` is NOT ignored, plain `git add` works).

---

### Task 1: `insight_snapshots` migration + `InsightSnapshot` model

**Files:**
- Create: `database/migrations/2026_07_20_000000_create_insight_snapshots_table.php`
- Create: `app/Models/InsightSnapshot.php`
- Test: `tests/Feature/InsightSnapshotModelTest.php`

**Interfaces:**
- Produces: `App\Models\InsightSnapshot` with fillable columns listed below; casts: `scraped_at` datetime, all JSON section columns `array`. Later tasks call `InsightSnapshot::updateOrCreate(['scraped_at' => Carbon], [...columns])`.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use App\Models\InsightSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InsightSnapshotModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_creates_snapshot_with_casts(): void
    {
        $snap = InsightSnapshot::create([
            'scraped_at' => '2026-07-20 10:00:00',
            'earnings_total' => 363600.05,
            'earnings_30d' => 0,
            'bids_remaining' => 203,
            'unearned_bids' => 1297,
            'overall_ranking' => '25%',
            'job_proficiency' => [['label' => 'Completed Jobs']],
            'trending_skills' => [['label' => 'PHP']],
            'raw' => '{}',
        ]);

        $snap->refresh();
        $this->assertSame(203, $snap->bids_remaining);
        $this->assertSame('25%', $snap->overall_ranking);
        $this->assertIsArray($snap->job_proficiency);
        $this->assertSame('PHP', $snap->trending_skills[0]['label']);
        $this->assertInstanceOf(\Carbon\Carbon::class, $snap->scraped_at);
    }

    public function test_scraped_at_is_unique(): void
    {
        InsightSnapshot::create(['scraped_at' => '2026-07-20 10:00:00', 'raw' => '{}']);
        $this->expectException(\Illuminate\Database\QueryException::class);
        InsightSnapshot::create(['scraped_at' => '2026-07-20 10:00:00', 'raw' => '{}']);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=InsightSnapshotModelTest`
Expected: FAIL — `Class "App\Models\InsightSnapshot" not found`

- [ ] **Step 3: Write migration**

`database/migrations/2026_07_20_000000_create_insight_snapshots_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('insight_snapshots', function (Blueprint $table) {
            $table->id();
            $table->timestamp('scraped_at')->unique();
            $table->decimal('earnings_total', 14, 2)->nullable();
            $table->decimal('earnings_30d', 14, 2)->nullable();
            $table->unsignedInteger('bids_remaining')->nullable();
            $table->unsignedInteger('unearned_bids')->nullable();
            $table->string('overall_ranking')->nullable();
            $table->json('job_proficiency')->nullable();
            $table->json('rating_per_skill')->nullable();
            $table->json('ranking_per_skill')->nullable();
            $table->json('high_demand_skills')->nullable();
            $table->json('trending_skills')->nullable();
            $table->json('bids_per_milestone')->nullable();
            $table->json('profile_views_week')->nullable();
            $table->json('profile_views_year')->nullable();
            $table->json('earnings_over_time')->nullable();
            $table->json('bid_conversion')->nullable();
            $table->longText('raw')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('insight_snapshots');
    }
};
```

- [ ] **Step 4: Write model**

`app/Models/InsightSnapshot.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InsightSnapshot extends Model
{
    protected $fillable = [
        'scraped_at',
        'earnings_total',
        'earnings_30d',
        'bids_remaining',
        'unearned_bids',
        'overall_ranking',
        'job_proficiency',
        'rating_per_skill',
        'ranking_per_skill',
        'high_demand_skills',
        'trending_skills',
        'bids_per_milestone',
        'profile_views_week',
        'profile_views_year',
        'earnings_over_time',
        'bid_conversion',
        'raw',
    ];

    protected $casts = [
        'scraped_at' => 'datetime',
        'earnings_total' => 'decimal:2',
        'earnings_30d' => 'decimal:2',
        'job_proficiency' => 'array',
        'rating_per_skill' => 'array',
        'ranking_per_skill' => 'array',
        'high_demand_skills' => 'array',
        'trending_skills' => 'array',
        'bids_per_milestone' => 'array',
        'profile_views_week' => 'array',
        'profile_views_year' => 'array',
        'earnings_over_time' => 'array',
        'bid_conversion' => 'array',
    ];
}
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test --filter=InsightSnapshotModelTest`
Expected: PASS (2 tests)

- [ ] **Step 6: Commit**

```bash
git add database/migrations/2026_07_20_000000_create_insight_snapshots_table.php app/Models/InsightSnapshot.php tests/Feature/InsightSnapshotModelTest.php
git commit -m "feat: add insight_snapshots table and model"
```

---

### Task 2: `POST /api/insights/ingest`

**Files:**
- Create: `app/Http/Controllers/InsightsController.php`
- Modify: `routes/api.php` (after the gamification route, line 38)
- Commit: `tests/Fixtures/user-stats-extracted.json` (already on disk)
- Test: `tests/Feature/InsightsIngestTest.php`

**Interfaces:**
- Consumes: `InsightSnapshot` from Task 1; existing middleware alias `gamification.token`.
- Produces: `InsightsController::ingest(Request): JsonResponse` at `POST /api/insights/ingest`; route name-free, gamification-style. Task 3 adds `index` to this same controller.

- [ ] **Step 1: Write the failing test**

`tests/Feature/InsightsIngestTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\InsightSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InsightsIngestTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'test-secret-token';

    protected function setUp(): void
    {
        parent::setUp();
        config(['variables.gamificationIngestToken' => self::TOKEN]);
    }

    private function payload(): array
    {
        return json_decode(file_get_contents(base_path('tests/Fixtures/user-stats-extracted.json')), true);
    }

    private function post(array $payload)
    {
        return $this->withHeader('Authorization', 'Bearer ' . self::TOKEN)
            ->postJson('/api/insights/ingest', $payload);
    }

    public function test_rejects_missing_token(): void
    {
        $this->postJson('/api/insights/ingest', $this->payload())->assertStatus(401);
        $this->assertSame(0, InsightSnapshot::count());
    }

    public function test_full_blob_parses_canonical_columns(): void
    {
        $this->post($this->payload())->assertOk()->assertJson(['success' => true]);

        $snap = InsightSnapshot::firstOrFail();
        $this->assertSame('363600.05', (string) $snap->earnings_total);
        $this->assertSame('0.00', (string) $snap->earnings_30d);
        $this->assertSame(203, $snap->bids_remaining);
        $this->assertSame(1297, $snap->unearned_bids);
        $this->assertSame('25%', $snap->overall_ranking);
        $this->assertCount(4, $snap->job_proficiency);
        $this->assertCount(33, $snap->rating_per_skill);
        $this->assertCount(2289, $snap->trending_skills);
        $this->assertNull($snap->bids_per_milestone['user']);
        $this->assertNotNull($snap->bids_per_milestone['marketplace']);
        $this->assertArrayHasKey('labels', $snap->profile_views_week);
        // raw retained
        $this->assertArrayHasKey('userStats', json_decode($snap->raw, true));
    }

    public function test_partial_blob_user_stats_only(): void
    {
        $p = ['userStats' => $this->payload()['userStats']];
        $this->post($p)->assertOk();

        $snap = InsightSnapshot::firstOrFail();
        $this->assertSame(203, $snap->bids_remaining);
        $this->assertNull($snap->overall_ranking);
        $this->assertNull($snap->trending_skills);
    }

    public function test_missing_both_sections_is_422(): void
    {
        $this->post(['foo' => 'bar'])->assertStatus(422);
        $this->assertSame(0, InsightSnapshot::count());
    }

    public function test_same_scraped_at_is_idempotent(): void
    {
        $p = $this->payload();
        $p['scraped_at'] = '2026-07-20T10:00:00Z';
        $this->post($p)->assertOk();
        $this->post($p)->assertOk();
        $this->assertSame(1, InsightSnapshot::count());
    }

    public function test_invalid_scraped_at_does_not_500(): void
    {
        $p = $this->payload();
        $p['scraped_at'] = 'not-a-date';
        $this->post($p)->assertSuccessful();
        $this->assertSame(1, InsightSnapshot::count());
    }

    public function test_malformed_section_nulls_column_but_succeeds(): void
    {
        $p = $this->payload();
        $p['userStats']['totalEarnings'] = 'garbage';
        $this->post($p)->assertOk();
        $this->assertNull(InsightSnapshot::firstOrFail()->earnings_total);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=InsightsIngestTest`
Expected: FAIL — 404s (route not defined)

- [ ] **Step 3: Write controller**

`app/Http/Controllers/InsightsController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Models\InsightSnapshot;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class InsightsController extends Controller
{
    public function ingest(Request $request)
    {
        $payload = $request->all();

        $userStats = is_array($payload['userStats'] ?? null) ? $payload['userStats'] : null;
        $marketStats = is_array($payload['marketplaceStats'] ?? null) ? $payload['marketplaceStats'] : null;

        if ($userStats === null && $marketStats === null) {
            return response()->json(['message' => 'Invalid payload'], 422);
        }

        $userStats = $userStats ?? [];
        $marketStats = $marketStats ?? [];

        $rawTs = $payload['scraped_at'] ?? null;
        try {
            $scrapedAt = (is_string($rawTs) && $rawTs !== '') ? Carbon::parse($rawTs) : now();
        } catch (\Throwable $e) {
            $scrapedAt = now();
        }

        $snapshot = InsightSnapshot::updateOrCreate(
            ['scraped_at' => $scrapedAt],
            [
                'earnings_total' => $this->parseMoney($userStats['totalEarnings'][0]['value'] ?? null),
                'earnings_30d' => $this->parseMoney($userStats['totalEarnings'][1]['value'] ?? null),
                'bids_remaining' => $this->bidSummaryValue($userStats['bidSummary'] ?? null, 'Bids Remaining'),
                'unearned_bids' => $this->bidSummaryValue($userStats['bidSummary'] ?? null, 'Unearned Bids'),
                'overall_ranking' => $this->stringOrNull($marketStats['overallRanking'][0]['value'] ?? null),
                'job_proficiency' => $this->arrayOrNull($userStats['jobProficiency'] ?? null),
                'rating_per_skill' => $this->arrayOrNull($userStats['ratingPerSkill'] ?? null),
                'ranking_per_skill' => $this->arrayOrNull($marketStats['rankingPerSkill'] ?? null),
                'high_demand_skills' => $this->arrayOrNull($marketStats['highDemandSkills'] ?? null),
                'trending_skills' => $this->arrayOrNull($marketStats['trendingSkills'] ?? null),
                'bids_per_milestone' => [
                    'user' => $userStats['bidsPerMilestone'] ?? null,
                    'marketplace' => $marketStats['bidsPerMilestoneMarketplace'] ?? null,
                ],
                'profile_views_week' => $this->arrayOrNull($marketStats['profileViewCountPastWeek'] ?? null),
                'profile_views_year' => $this->arrayOrNull($marketStats['profileViewCountPastYear'] ?? null),
                'earnings_over_time' => $this->arrayOrNull($userStats['earningsOverTime'] ?? null),
                'bid_conversion' => $this->arrayOrNull($userStats['bidConversion'] ?? null),
                'raw' => json_encode($payload),
            ]
        );

        return response()->json(['success' => true, 'id' => $snapshot->id]);
    }

    private function parseMoney(mixed $value): ?float
    {
        if (! is_string($value) && ! is_numeric($value)) {
            if ($value !== null) {
                Log::warning('insights ingest: unparseable money value', ['value' => $value]);
            }

            return null;
        }
        $clean = preg_replace('/[^0-9.\-]/', '', (string) $value);

        return is_numeric($clean) ? (float) $clean : null;
    }

    private function bidSummaryValue(mixed $summary, string $label): ?int
    {
        if (! is_array($summary)) {
            return null;
        }
        foreach ($summary as $item) {
            if (is_array($item) && ($item['label'] ?? null) === $label && is_numeric($item['value'] ?? null)) {
                return (int) $item['value'];
            }
        }

        return null;
    }

    private function arrayOrNull(mixed $value): ?array
    {
        if ($value !== null && ! is_array($value)) {
            Log::warning('insights ingest: expected array section', ['value' => $value]);

            return null;
        }

        return is_array($value) ? $value : null;
    }

    private function stringOrNull(mixed $value): ?string
    {
        return (is_string($value) || is_numeric($value)) ? (string) $value : null;
    }
}
```

- [ ] **Step 4: Add route**

In `routes/api.php`, after the gamification route block (line 36-37), add:

```php
Route::post('insights/ingest', [InsightsController::class, 'ingest'])
    ->middleware('gamification.token');
```

And the import at the top with the other controller imports:

```php
use App\Http\Controllers\InsightsController;
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test --filter=InsightsIngestTest`
Expected: PASS (7 tests)

- [ ] **Step 6: Commit (include fixture)**

```bash
git add app/Http/Controllers/InsightsController.php routes/api.php tests/Feature/InsightsIngestTest.php tests/Fixtures/user-stats-extracted.json
git commit -m "feat: add insights ingest endpoint"
```

---

### Task 3: `GET /api/insights` (latest + history)

**Files:**
- Modify: `app/Http/Controllers/InsightsController.php` (add `index` method)
- Modify: `routes/api.php`
- Test: `tests/Feature/InsightsIndexTest.php`

**Interfaces:**
- Consumes: `InsightSnapshot`, `InsightsController` from Tasks 1-2.
- Produces: `GET /api/insights` → `{"latest": {...}|null, "history": [{"date","earnings_total","earnings_30d","bids_remaining","unearned_bids","overall_ranking"}]}`. `latest` excludes `raw`.

- [ ] **Step 1: Write the failing test**

`tests/Feature/InsightsIndexTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\InsightSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InsightsIndexTest extends TestCase
{
    use RefreshDatabase;

    public function test_empty_state(): void
    {
        $this->getJson('/api/insights')
            ->assertOk()
            ->assertJson(['latest' => null, 'history' => []]);
    }

    public function test_returns_latest_and_history_without_raw(): void
    {
        InsightSnapshot::create([
            'scraped_at' => '2026-07-19 10:00:00',
            'earnings_total' => 100,
            'bids_remaining' => 210,
            'raw' => '{"secret":1}',
        ]);
        InsightSnapshot::create([
            'scraped_at' => '2026-07-20 10:00:00',
            'earnings_total' => 363600.05,
            'bids_remaining' => 203,
            'overall_ranking' => '25%',
            'raw' => '{"secret":2}',
        ]);

        $res = $this->getJson('/api/insights')->assertOk()->json();

        $this->assertSame(203, $res['latest']['bids_remaining']);
        $this->assertArrayNotHasKey('raw', $res['latest']);
        $this->assertCount(2, $res['history']);
        // history is chronological
        $this->assertSame('2026-07-19', $res['history'][0]['date']);
        $this->assertSame('2026-07-20', $res['history'][1]['date']);
        $this->assertSame('25%', $res['history'][1]['overall_ranking']);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=InsightsIndexTest`
Expected: FAIL — 404 (route not defined)

- [ ] **Step 3: Add `index` method to `InsightsController`**

```php
    public function index()
    {
        $latest = InsightSnapshot::orderByDesc('scraped_at')->first();

        $history = InsightSnapshot::orderByDesc('scraped_at')
            ->limit(90)
            ->get(['scraped_at', 'earnings_total', 'earnings_30d', 'bids_remaining', 'unearned_bids', 'overall_ranking'])
            ->reverse()
            ->values()
            ->map(fn ($s) => [
                'date' => $s->scraped_at->format('Y-m-d'),
                'earnings_total' => $s->earnings_total,
                'earnings_30d' => $s->earnings_30d,
                'bids_remaining' => $s->bids_remaining,
                'unearned_bids' => $s->unearned_bids,
                'overall_ranking' => $s->overall_ranking,
            ])
            ->all();

        return response()->json([
            'latest' => $latest?->makeHidden('raw'),
            'history' => $history,
        ]);
    }
```

- [ ] **Step 4: Add route**

In `routes/api.php`, next to the insights ingest route:

```php
Route::get('insights', [InsightsController::class, 'index']);
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test --filter=InsightsIndexTest`
Expected: PASS (2 tests)

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/InsightsController.php routes/api.php tests/Feature/InsightsIndexTest.php
git commit -m "feat: add insights latest+history read endpoint"
```

---

### Task 4: `bid_insights` + `bid_insight_changes` migrations + models

**Files:**
- Create: `database/migrations/2026_07_20_000100_create_bid_insights_table.php`
- Create: `database/migrations/2026_07_20_000200_create_bid_insight_changes_table.php`
- Create: `app/Models/BidInsight.php`
- Create: `app/Models/BidInsightChange.php`
- Test: `tests/Feature/BidInsightModelTest.php`

**Interfaces:**
- Produces: `BidInsight` (unique `project_id`, one-time + recurring columns, `changes()` hasMany relation), `BidInsightChange` (belongsTo `bidInsight`). Task 5-6 rely on exact column names below and constants `BidInsight::ONE_TIME_FIELDS`, `BidInsight::RECURRING_FIELDS`.

- [ ] **Step 1: Write the failing test**

`tests/Feature/BidInsightModelTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\BidInsight;
use App\Models\BidInsightChange;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BidInsightModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_creates_bid_insight_with_casts(): void
    {
        $bid = BidInsight::create([
            'project_id' => 39812345,
            'bid_amount' => 250,
            'client_country' => 'US',
            'winning_bid_sealed' => false,
            'actions_taken' => ['viewed_by_client'],
            'client_engagement' => ['viewed' => true],
            'last_scraped_at' => '2026-07-20 10:00:00',
            'raw' => ['project_id' => 39812345],
        ]);

        $bid->refresh();
        $this->assertSame(39812345, $bid->project_id);
        $this->assertFalse($bid->winning_bid_sealed);
        $this->assertSame(['viewed_by_client'], $bid->actions_taken);
        $this->assertTrue($bid->client_engagement['viewed']);
        $this->assertIsArray($bid->raw);
    }

    public function test_project_id_unique(): void
    {
        BidInsight::create(['project_id' => 1, 'last_scraped_at' => now()]);
        $this->expectException(\Illuminate\Database\QueryException::class);
        BidInsight::create(['project_id' => 1, 'last_scraped_at' => now()]);
    }

    public function test_changes_relation(): void
    {
        $bid = BidInsight::create(['project_id' => 2, 'last_scraped_at' => now()]);
        $bid->changes()->create([
            'field' => 'bid_rank',
            'old_value' => '5',
            'new_value' => '3',
            'observed_at' => now(),
        ]);

        $this->assertSame(1, $bid->changes()->count());
        $this->assertSame('bid_rank', BidInsightChange::first()->field);
        $this->assertSame($bid->id, BidInsightChange::first()->bidInsight->id);
    }

    public function test_field_constants(): void
    {
        $this->assertContains('client_country', BidInsight::ONE_TIME_FIELDS);
        $this->assertContains('bid_rank', BidInsight::RECURRING_FIELDS);
        $this->assertEmpty(array_intersect(BidInsight::ONE_TIME_FIELDS, BidInsight::RECURRING_FIELDS));
    }
}
```

Note: Eloquent models have a base `changes` attribute internally (`getChanges()`), but a `changes()` hasMany relation works — access it via `$bid->changes()->...` (method form) as done here and in Tasks 5-7. Do not rely on the `$bid->changes` magic property.

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=BidInsightModelTest`
Expected: FAIL — `Class "App\Models\BidInsight" not found`

- [ ] **Step 3: Write migrations**

`database/migrations/2026_07_20_000100_create_bid_insights_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bid_insights', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('project_id')->unique();
            $table->string('project_url')->nullable();
            // one-time fields
            $table->unsignedInteger('time_to_bid_seconds')->nullable();
            $table->decimal('bid_amount', 12, 2)->nullable();
            $table->string('bid_currency', 8)->nullable();
            $table->string('client_country', 64)->nullable();
            $table->decimal('client_rating', 3, 2)->nullable();
            $table->unsignedInteger('client_reviews')->nullable();
            // recurring fields
            $table->unsignedInteger('bid_rank')->nullable();
            $table->decimal('winning_bid_amount', 12, 2)->nullable();
            $table->boolean('winning_bid_sealed')->nullable();
            $table->longText('winning_bid_text')->nullable();
            $table->json('actions_taken')->nullable();
            $table->json('client_engagement')->nullable();
            $table->timestamp('last_scraped_at');
            $table->json('raw')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bid_insights');
    }
};
```

`database/migrations/2026_07_20_000200_create_bid_insight_changes_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bid_insight_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bid_insight_id')->constrained('bid_insights')->cascadeOnDelete();
            $table->string('field', 64);
            $table->text('old_value')->nullable();
            $table->text('new_value')->nullable();
            $table->timestamp('observed_at');
            $table->timestamps();
            $table->index(['bid_insight_id', 'observed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bid_insight_changes');
    }
};
```

- [ ] **Step 4: Write models**

`app/Models/BidInsight.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BidInsight extends Model
{
    public const ONE_TIME_FIELDS = [
        'project_url',
        'time_to_bid_seconds',
        'bid_amount',
        'bid_currency',
        'client_country',
        'client_rating',
        'client_reviews',
    ];

    public const RECURRING_FIELDS = [
        'bid_rank',
        'winning_bid_amount',
        'winning_bid_sealed',
        'winning_bid_text',
        'actions_taken',
        'client_engagement',
    ];

    protected $fillable = [
        'project_id',
        'project_url',
        'time_to_bid_seconds',
        'bid_amount',
        'bid_currency',
        'client_country',
        'client_rating',
        'client_reviews',
        'bid_rank',
        'winning_bid_amount',
        'winning_bid_sealed',
        'winning_bid_text',
        'actions_taken',
        'client_engagement',
        'last_scraped_at',
        'raw',
    ];

    protected $casts = [
        'bid_amount' => 'decimal:2',
        'winning_bid_amount' => 'decimal:2',
        'client_rating' => 'decimal:2',
        'winning_bid_sealed' => 'boolean',
        'actions_taken' => 'array',
        'client_engagement' => 'array',
        'raw' => 'array',
        'last_scraped_at' => 'datetime',
    ];

    public function changes(): HasMany
    {
        return $this->hasMany(BidInsightChange::class);
    }
}
```

`app/Models/BidInsightChange.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BidInsightChange extends Model
{
    protected $fillable = [
        'bid_insight_id',
        'field',
        'old_value',
        'new_value',
        'observed_at',
    ];

    protected $casts = [
        'observed_at' => 'datetime',
    ];

    public function bidInsight(): BelongsTo
    {
        return $this->belongsTo(BidInsight::class);
    }
}
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test --filter=BidInsightModelTest`
Expected: PASS (4 tests)

- [ ] **Step 6: Commit**

```bash
git add database/migrations/2026_07_20_000100_create_bid_insights_table.php database/migrations/2026_07_20_000200_create_bid_insight_changes_table.php app/Models/BidInsight.php app/Models/BidInsightChange.php tests/Feature/BidInsightModelTest.php
git commit -m "feat: add bid_insights and bid_insight_changes tables and models"
```

---

### Task 5: `POST /api/insights/bids/ingest` — create path

**Files:**
- Create: `app/Http/Controllers/BidInsightsController.php`
- Modify: `routes/api.php`
- Test: `tests/Feature/BidInsightsIngestTest.php`

**Interfaces:**
- Consumes: `BidInsight`, `BidInsightChange`, constants from Task 4; `gamification.token` middleware.
- Produces: `BidInsightsController::ingest(Request): JsonResponse` at `POST /api/insights/bids/ingest`, response `{"success": true, "created": N, "updated": N, "changes": N, "skipped": N}`. Task 6 extends the same method's update path; Task 7 adds `index`/`changes` methods to this controller.

- [ ] **Step 1: Write the failing test**

`tests/Feature/BidInsightsIngestTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\BidInsight;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BidInsightsIngestTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'test-secret-token';

    protected function setUp(): void
    {
        parent::setUp();
        config(['variables.gamificationIngestToken' => self::TOKEN]);
    }

    private function post(array $payload)
    {
        return $this->withHeader('Authorization', 'Bearer ' . self::TOKEN)
            ->postJson('/api/insights/bids/ingest', $payload);
    }

    private function bidItem(array $overrides = []): array
    {
        return array_merge([
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
            'winning_bid_text' => 'I will build this.',
            'actions_taken' => ['viewed_by_client'],
            'client_engagement' => ['viewed' => true, 'replied' => false],
        ], $overrides);
    }

    public function test_rejects_missing_token(): void
    {
        $this->postJson('/api/insights/bids/ingest', ['bids' => [$this->bidItem()]])
            ->assertStatus(401);
        $this->assertSame(0, BidInsight::count());
    }

    public function test_missing_bids_array_is_422(): void
    {
        $this->post(['foo' => 'bar'])->assertStatus(422);
    }

    public function test_initial_ingest_creates_rows_without_changes(): void
    {
        $res = $this->post([
            'scraped_at' => '2026-07-20T10:00:00Z',
            'crawl_type' => 'initial',
            'bids' => [$this->bidItem(), $this->bidItem(['project_id' => 39812346])],
        ]);

        $res->assertOk()->assertJson([
            'success' => true,
            'created' => 2,
            'updated' => 0,
            'changes' => 0,
            'skipped' => 0,
        ]);

        $bid = BidInsight::where('project_id', 39812345)->firstOrFail();
        $this->assertSame(94, $bid->time_to_bid_seconds);
        $this->assertSame('US', $bid->client_country);
        $this->assertSame(3, $bid->bid_rank);
        $this->assertFalse($bid->winning_bid_sealed);
        $this->assertSame(['viewed_by_client'], $bid->actions_taken);
        $this->assertSame('2026-07-20 10:00:00', $bid->last_scraped_at->format('Y-m-d H:i:s'));
        $this->assertSame(0, $bid->changes()->count());
        $this->assertSame(39812345, $bid->raw['project_id']);
    }

    public function test_item_without_project_id_is_skipped(): void
    {
        $item = $this->bidItem();
        unset($item['project_id']);

        $this->post(['bids' => [$item, $this->bidItem(['project_id' => 7])]])
            ->assertOk()
            ->assertJson(['created' => 1, 'skipped' => 1]);
        $this->assertSame(1, BidInsight::count());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=BidInsightsIngestTest`
Expected: FAIL — 404 (route not defined)

- [ ] **Step 3: Write controller (create path only; update path arrives in Task 6)**

`app/Http/Controllers/BidInsightsController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Models\BidInsight;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BidInsightsController extends Controller
{
    public function ingest(Request $request)
    {
        $payload = $request->all();

        $bids = $payload['bids'] ?? null;
        if (! is_array($bids)) {
            return response()->json(['message' => 'Invalid payload'], 422);
        }

        $rawTs = $payload['scraped_at'] ?? null;
        try {
            $scrapedAt = (is_string($rawTs) && $rawTs !== '') ? Carbon::parse($rawTs) : now();
        } catch (\Throwable $e) {
            $scrapedAt = now();
        }

        $created = 0;
        $updated = 0;
        $changes = 0;
        $skipped = 0;

        DB::transaction(function () use ($bids, $scrapedAt, &$created, &$updated, &$changes, &$skipped) {
            foreach ($bids as $item) {
                if (! is_array($item) || ! is_numeric($item['project_id'] ?? null)) {
                    $skipped++;
                    continue;
                }

                $existing = BidInsight::where('project_id', (int) $item['project_id'])->first();

                if ($existing === null) {
                    $attributes = ['project_id' => (int) $item['project_id']];
                    foreach (array_merge(BidInsight::ONE_TIME_FIELDS, BidInsight::RECURRING_FIELDS) as $field) {
                        if (array_key_exists($field, $item)) {
                            $attributes[$field] = $item[$field];
                        }
                    }
                    $attributes['last_scraped_at'] = $scrapedAt;
                    $attributes['raw'] = $item;
                    BidInsight::create($attributes);
                    $created++;
                    continue;
                }

                $changes += $this->applyUpdate($existing, $item, $scrapedAt);
                $updated++;
            }
        });

        return response()->json([
            'success' => true,
            'created' => $created,
            'updated' => $updated,
            'changes' => $changes,
            'skipped' => $skipped,
        ]);
    }

    private function applyUpdate(BidInsight $existing, array $item, Carbon $scrapedAt): int
    {
        // Task 6 implements diffing; for now just touch scrape metadata.
        $existing->last_scraped_at = $scrapedAt;
        $existing->raw = $item;
        $existing->save();

        return 0;
    }
}
```

- [ ] **Step 4: Add routes**

In `routes/api.php`, after the insights routes:

```php
Route::post('insights/bids/ingest', [BidInsightsController::class, 'ingest'])
    ->middleware('gamification.token');
```

Import at top:

```php
use App\Http\Controllers\BidInsightsController;
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test --filter=BidInsightsIngestTest`
Expected: PASS (4 tests)

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/BidInsightsController.php routes/api.php tests/Feature/BidInsightsIngestTest.php
git commit -m "feat: add bid insights ingest endpoint (create path)"
```

---

### Task 6: Recurring diff + audit change rows

**Files:**
- Modify: `app/Http/Controllers/BidInsightsController.php` (replace `applyUpdate` stub)
- Test: `tests/Feature/BidInsightsAuditTest.php`

**Interfaces:**
- Consumes: `BidInsightsController::applyUpdate` stub from Task 5, `BidInsight::ONE_TIME_FIELDS` / `RECURRING_FIELDS`, `BidInsightChange`.
- Produces: `applyUpdate(BidInsight, array, Carbon): int` returning the number of change rows written. Recurring fields diffed + updated; one-time fields fill-in only when currently null.

- [ ] **Step 1: Write the failing test**

`tests/Feature/BidInsightsAuditTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\BidInsight;
use App\Models\BidInsightChange;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BidInsightsAuditTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'test-secret-token';

    protected function setUp(): void
    {
        parent::setUp();
        config(['variables.gamificationIngestToken' => self::TOKEN]);
    }

    private function post(array $payload)
    {
        return $this->withHeader('Authorization', 'Bearer ' . self::TOKEN)
            ->postJson('/api/insights/bids/ingest', $payload);
    }

    private function bidItem(array $overrides = []): array
    {
        return array_merge([
            'project_id' => 39812345,
            'bid_amount' => 250,
            'client_country' => 'US',
            'bid_rank' => 5,
            'winning_bid_amount' => 220,
            'winning_bid_sealed' => false,
            'actions_taken' => ['viewed_by_client'],
            'client_engagement' => ['viewed' => true, 'replied' => false],
        ], $overrides);
    }

    private function seed(): void
    {
        $this->post([
            'scraped_at' => '2026-07-20T10:00:00Z',
            'crawl_type' => 'initial',
            'bids' => [$this->bidItem()],
        ])->assertOk();
    }

    public function test_changed_recurring_fields_write_audit_rows(): void
    {
        $this->seed();

        $this->post([
            'scraped_at' => '2026-07-20T11:00:00Z',
            'bids' => [$this->bidItem([
                'bid_rank' => 3,
                'winning_bid_amount' => 230,
            ])],
        ])->assertOk()->assertJson(['updated' => 1, 'changes' => 2]);

        $bid = BidInsight::firstOrFail();
        $this->assertSame(3, $bid->bid_rank);
        $this->assertSame('230.00', (string) $bid->winning_bid_amount);

        $rankChange = BidInsightChange::where('field', 'bid_rank')->firstOrFail();
        $this->assertSame('5', $rankChange->old_value);
        $this->assertSame('3', $rankChange->new_value);
        $this->assertSame('2026-07-20 11:00:00', $rankChange->observed_at->format('Y-m-d H:i:s'));
    }

    public function test_identical_values_write_no_audit_rows(): void
    {
        $this->seed();

        $this->post([
            'scraped_at' => '2026-07-20T11:00:00Z',
            'bids' => [$this->bidItem()],
        ])->assertOk()->assertJson(['updated' => 1, 'changes' => 0]);

        $this->assertSame(0, BidInsightChange::count());
    }

    public function test_json_field_change_is_detected(): void
    {
        $this->seed();

        $this->post([
            'scraped_at' => '2026-07-20T11:00:00Z',
            'bids' => [$this->bidItem([
                'client_engagement' => ['viewed' => true, 'replied' => true],
            ])],
        ])->assertOk()->assertJson(['changes' => 1]);

        $change = BidInsightChange::where('field', 'client_engagement')->firstOrFail();
        $this->assertStringContainsString('"replied":true', $change->new_value);
    }

    public function test_one_time_field_not_overwritten(): void
    {
        $this->seed();

        $this->post([
            'scraped_at' => '2026-07-20T11:00:00Z',
            'bids' => [$this->bidItem(['client_country' => 'DE', 'bid_amount' => 999])],
        ])->assertOk()->assertJson(['changes' => 0]);

        $bid = BidInsight::firstOrFail();
        $this->assertSame('US', $bid->client_country);
        $this->assertSame('250.00', (string) $bid->bid_amount);
    }

    public function test_one_time_field_filled_in_when_null(): void
    {
        $item = $this->bidItem();
        unset($item['client_country']);
        $this->post(['scraped_at' => '2026-07-20T10:00:00Z', 'bids' => [$item]])->assertOk();

        $this->post([
            'scraped_at' => '2026-07-20T11:00:00Z',
            'bids' => [$this->bidItem()],
        ])->assertOk();

        $this->assertSame('US', BidInsight::firstOrFail()->client_country);
    }

    public function test_absent_recurring_field_is_not_a_change(): void
    {
        $this->seed();

        $item = $this->bidItem();
        unset($item['bid_rank']);
        $this->post(['scraped_at' => '2026-07-20T11:00:00Z', 'bids' => [$item]])
            ->assertOk()->assertJson(['changes' => 0]);

        $this->assertSame(5, BidInsight::firstOrFail()->bid_rank);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=BidInsightsAuditTest`
Expected: FAIL — `test_changed_recurring_fields_write_audit_rows` (changes stays 0), `test_json_field_change_is_detected`, `test_one_time_field_filled_in_when_null` fail; stub never diffs.

- [ ] **Step 3: Implement diffing**

In `app/Http/Controllers/BidInsightsController.php`, add import:

```php
use App\Models\BidInsightChange;
```

Replace the `applyUpdate` stub with:

```php
    private function applyUpdate(BidInsight $existing, array $item, Carbon $scrapedAt): int
    {
        $changeCount = 0;

        foreach (BidInsight::ONE_TIME_FIELDS as $field) {
            if ($existing->{$field} === null && array_key_exists($field, $item)) {
                $existing->{$field} = $item[$field];
            }
        }

        foreach (BidInsight::RECURRING_FIELDS as $field) {
            if (! array_key_exists($field, $item)) {
                continue;
            }
            $old = $existing->{$field};
            $new = $item[$field];
            if ($this->normalize($old) !== $this->normalize($new)) {
                BidInsightChange::create([
                    'bid_insight_id' => $existing->id,
                    'field' => $field,
                    'old_value' => $this->stringify($old),
                    'new_value' => $this->stringify($new),
                    'observed_at' => $scrapedAt,
                ]);
                $existing->{$field} = $new;
                $changeCount++;
            }
        }

        $existing->last_scraped_at = $scrapedAt;
        $existing->raw = $item;
        $existing->save();

        return $changeCount;
    }

    private function normalize(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (is_array($value)) {
            return json_encode($value);
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        if (is_numeric($value)) {
            return (string) (float) $value;
        }

        return (string) $value;
    }

    private function stringify(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (is_array($value)) {
            return json_encode($value);
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        return (string) $value;
    }
```

Note on `normalize`: Eloquent decimal casts return strings (`"220.00"`), payload sends numbers (`220`); numeric branch casts both through float so `"220.00"` and `220` compare equal. JSON fields: model cast gives array, payload gives array — both `json_encode`d. Key order differences in JSON objects WOULD register as change; crawler sends consistent shapes, accepted per spec.

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --filter=BidInsightsAuditTest`
Expected: PASS (6 tests)

Also run: `php artisan test --filter=BidInsightsIngestTest`
Expected: still PASS (4 tests)

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/BidInsightsController.php tests/Feature/BidInsightsAuditTest.php
git commit -m "feat: add field-level audit diffing to bid insights ingest"
```

---

### Task 7: `GET /api/insights/bids` + `GET /api/insights/bids/{id}/changes`

**Files:**
- Modify: `app/Http/Controllers/BidInsightsController.php` (add `index`, `changes` methods)
- Modify: `routes/api.php`
- Test: `tests/Feature/BidInsightsReadTest.php`

**Interfaces:**
- Consumes: models + controller from Tasks 4-6.
- Produces: `GET /api/insights/bids` → Laravel paginator JSON (50/page, ordered `last_scraped_at` desc, `raw` hidden); `GET /api/insights/bids/{bidInsight}/changes` → paginator of change rows ordered `observed_at` desc (50/page).

- [ ] **Step 1: Write the failing test**

`tests/Feature/BidInsightsReadTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\BidInsight;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BidInsightsReadTest extends TestCase
{
    use RefreshDatabase;

    public function test_lists_bids_ordered_by_last_scraped_desc_without_raw(): void
    {
        BidInsight::create(['project_id' => 1, 'bid_rank' => 9, 'last_scraped_at' => '2026-07-19 10:00:00', 'raw' => ['x' => 1]]);
        BidInsight::create(['project_id' => 2, 'bid_rank' => 4, 'last_scraped_at' => '2026-07-20 10:00:00', 'raw' => ['x' => 2]]);

        $res = $this->getJson('/api/insights/bids')->assertOk()->json();

        $this->assertSame(2, $res['total']);
        $this->assertSame(2, $res['data'][0]['project_id']);
        $this->assertSame(1, $res['data'][1]['project_id']);
        $this->assertArrayNotHasKey('raw', $res['data'][0]);
    }

    public function test_pagination_50_per_page(): void
    {
        for ($i = 1; $i <= 55; $i++) {
            BidInsight::create(['project_id' => $i, 'last_scraped_at' => now()]);
        }

        $res = $this->getJson('/api/insights/bids')->assertOk()->json();
        $this->assertCount(50, $res['data']);
        $this->assertSame(2, $res['last_page']);
    }

    public function test_changes_trail_for_one_bid(): void
    {
        $bid = BidInsight::create(['project_id' => 1, 'last_scraped_at' => now()]);
        $other = BidInsight::create(['project_id' => 2, 'last_scraped_at' => now()]);

        $bid->changes()->create(['field' => 'bid_rank', 'old_value' => '5', 'new_value' => '3', 'observed_at' => '2026-07-20 10:00:00']);
        $bid->changes()->create(['field' => 'bid_rank', 'old_value' => '3', 'new_value' => '2', 'observed_at' => '2026-07-20 11:00:00']);
        $other->changes()->create(['field' => 'bid_rank', 'old_value' => '8', 'new_value' => '7', 'observed_at' => '2026-07-20 12:00:00']);

        $res = $this->getJson("/api/insights/bids/{$bid->id}/changes")->assertOk()->json();

        $this->assertSame(2, $res['total']);
        $this->assertSame('2', $res['data'][0]['new_value']); // newest first
        $this->assertSame('3', $res['data'][1]['new_value']);
    }

    public function test_changes_for_unknown_bid_is_404(): void
    {
        $this->getJson('/api/insights/bids/999/changes')->assertStatus(404);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=BidInsightsReadTest`
Expected: FAIL — 404s (routes not defined)

- [ ] **Step 3: Add controller methods**

In `app/Http/Controllers/BidInsightsController.php` add:

```php
    public function index()
    {
        $page = BidInsight::orderByDesc('last_scraped_at')->paginate(50);
        $page->getCollection()->each->makeHidden('raw');

        return response()->json($page);
    }

    public function changes(BidInsight $bidInsight)
    {
        return response()->json(
            $bidInsight->changes()->orderByDesc('observed_at')->paginate(50)
        );
    }
```

- [ ] **Step 4: Add routes**

In `routes/api.php`, next to the bids ingest route:

```php
Route::get('insights/bids', [BidInsightsController::class, 'index']);
Route::get('insights/bids/{bidInsight}/changes', [BidInsightsController::class, 'changes']);
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `php artisan test --filter=BidInsightsReadTest`
Expected: PASS (4 tests)

- [ ] **Step 6: Run full suite**

Run: `php artisan test`
Expected: all green, no regressions.

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/BidInsightsController.php routes/api.php tests/Feature/BidInsightsReadTest.php
git commit -m "feat: add bid insights read endpoints (list + audit trail)"
```
