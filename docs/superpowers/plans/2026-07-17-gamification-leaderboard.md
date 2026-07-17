# Gamification Leaderboard Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Receive the Freelancer gamification JSON from an external scraper via a token-guarded API, snapshot it (structured fields + raw), and show a Leaderboard page with current rank/score/level, top-5, and trend over time.

**Architecture:** A `POST /api/gamification/ingest` route behind an `EnsureGamificationToken` middleware writes idempotent `gamification_snapshots` (keyed on `scraped_at`). A `GamificationController@index` renders a new Leaderboard web page from the latest snapshot plus history. All additive and isolated.

**Tech Stack:** Laravel 10, Eloquent, PHPUnit 10 Feature tests (RefreshDatabase), Blade, ApexCharts (already bundled), Bootstrap theme, data-driven sidebar JSON.

## Global Constraints

- Ingest route: `POST /api/gamification/ingest` (under the `/api` prefix in `routes/api.php`), guarded by middleware alias `gamification.token`.
- Auth: shared secret. Env `GAMIFICATION_INGEST_TOKEN`, exposed as `config('variables.gamificationIngestToken')`. Middleware reads `Authorization: Bearer <token>` OR `X-Ingest-Token: <token>`, compares with `hash_equals()`. Blank config or mismatch → `401 {"message":"Unauthorized"}`. Never leaks the expected value.
- Ingest is **idempotent**: `updateOrCreate` keyed on `scraped_at`. Re-posting the same `scraped_at` updates, never duplicates.
- Extraction: self = the `leaderboard.nearby[]` entry with `is_current_user === true` (nullable); `self_score` falls back to `level.xp_total`, `self_level` falls back to `level.level`. `top5` = `leaderboard.top[]` mapped to `{rank, user_id, username, public_name, level, score, is_current_user}`. `scraped_at` = `source.scraped_at` (fallback `now()`).
- Malformed body (not JSON, or no `leaderboard.top` array) → `422 {"message":"Invalid payload"}`. Never `500` on bad input.
- Leaderboard web page: `GET /leaderboard` inside the authenticated route group; sidebar entry added.
- Rank trend: lower rank = better → render the Rank chart on a **reversed** y-axis.
- Do NOT modify bids/stats/filters/crawl or any existing feature.
- All commands via Sail: `./vendor/bin/sail test --filter=<Name>`.
- Known pre-existing unrelated failure: `ExampleTest` (root `/` 302). Ignore it.

---

### Task 1: Migration + `GamificationSnapshot` model

**Files:**
- Create: `database/migrations/2026_07_17_000000_create_gamification_snapshots_table.php`
- Create: `app/Models/GamificationSnapshot.php`
- Test: `tests/Feature/GamificationSnapshotModelTest.php`

**Interfaces:**
- Produces: `gamification_snapshots` table; `App\Models\GamificationSnapshot` with fillable `scraped_at, self_rank, self_score, self_level, self_username, self_public_name, top5, raw` and casts `scraped_at => datetime`, `top5 => array`.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/GamificationSnapshotModelTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\GamificationSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GamificationSnapshotModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_snapshot_persists_fields_and_casts_top5_to_array(): void
    {
        $snap = GamificationSnapshot::create([
            'scraped_at' => '2026-07-16T11:35:45Z',
            'self_rank' => 268,
            'self_score' => 309961,
            'self_level' => 20,
            'self_username' => 'ahmadayaz',
            'self_public_name' => 'Raja Ahmad Ayaz N.',
            'top5' => [['rank' => 1, 'public_name' => 'Chandrasekhar G.', 'score' => 4593118]],
            'raw' => '{"ok":true}',
        ]);

        $fresh = $snap->fresh();
        $this->assertSame(268, $fresh->self_rank);
        $this->assertSame(309961, $fresh->self_score);
        $this->assertIsArray($fresh->top5);
        $this->assertSame('Chandrasekhar G.', $fresh->top5[0]['public_name']);
        $this->assertInstanceOf(\Illuminate\Support\Carbon::class, $fresh->scraped_at);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/sail test --filter=GamificationSnapshotModelTest`
Expected: FAIL — model/table do not exist.

- [ ] **Step 3: Write the migration**

Create `database/migrations/2026_07_17_000000_create_gamification_snapshots_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gamification_snapshots', function (Blueprint $table) {
            $table->id();
            $table->timestamp('scraped_at')->unique();
            $table->unsignedInteger('self_rank')->nullable();
            $table->unsignedBigInteger('self_score')->nullable();
            $table->unsignedInteger('self_level')->nullable();
            $table->string('self_username')->nullable();
            $table->string('self_public_name')->nullable();
            $table->json('top5')->nullable();
            $table->longText('raw')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gamification_snapshots');
    }
};
```

- [ ] **Step 4: Write the model**

Create `app/Models/GamificationSnapshot.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GamificationSnapshot extends Model
{
    protected $fillable = [
        'scraped_at',
        'self_rank',
        'self_score',
        'self_level',
        'self_username',
        'self_public_name',
        'top5',
        'raw',
    ];

    protected $casts = [
        'scraped_at' => 'datetime',
        'top5' => 'array',
    ];
}
```

- [ ] **Step 5: Run test to verify it passes**

Run: `./vendor/bin/sail test --filter=GamificationSnapshotModelTest`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add database/migrations/2026_07_17_000000_create_gamification_snapshots_table.php app/Models/GamificationSnapshot.php tests/Feature/GamificationSnapshotModelTest.php
git commit -m "feat: add gamification_snapshots table and model"
```

---

### Task 2: Token-guarded ingest endpoint

**Files:**
- Modify: `config/variables.php` (add `gamificationIngestToken`)
- Create: `app/Http/Middleware/EnsureGamificationToken.php`
- Modify: `app/Http/Kernel.php` (register `gamification.token` alias)
- Create: `app/Http/Controllers/GamificationController.php` (with `ingest`)
- Modify: `routes/api.php` (add the ingest route)
- Create: `tests/Fixtures/gamification-sample.json` (test payload)
- Test: `tests/Feature/GamificationIngestTest.php`

**Interfaces:**
- Consumes: `GamificationSnapshot` (Task 1).
- Produces: `POST /api/gamification/ingest` (middleware `gamification.token`) → `GamificationController@ingest`. Config key `variables.gamificationIngestToken`. Middleware alias `gamification.token`.

- [ ] **Step 1: Create the test fixture**

Create `tests/Fixtures/gamification-sample.json`:

```json
{
  "source": { "site": "Freelancer.com", "url": "https://www.freelancer.com/users/game/", "scraped_at": "2026-07-16T11:35:45.357000Z" },
  "user": { "id": 7032685, "username": "ahmadayaz", "public_name": "Raja Ahmad Ayaz N." },
  "level": { "level": 20, "rank": "Colt", "xp_total": 309961 },
  "leaderboard": {
    "top": [
      { "rank": 1, "user_id": 7480467, "username": "cgullapalli", "public_name": "Chandrasekhar G.", "level": 20, "score": 4593118, "is_current_user": false },
      { "rank": 2, "user_id": 6847977, "username": "OSSAUK", "public_name": "Allan H.", "level": 20, "score": 4557612, "is_current_user": false },
      { "rank": 3, "user_id": 2239716, "username": "mikehurley", "public_name": "Elite Information Tech", "level": 20, "score": 2813920, "is_current_user": false },
      { "rank": 4, "user_id": 1539095, "username": "RSGeneral", "public_name": "Aaron W.", "level": 20, "score": 2474810, "is_current_user": false },
      { "rank": 5, "user_id": 2740131, "username": "ravinder246", "public_name": "Websyms", "level": 20, "score": 2426533, "is_current_user": false }
    ],
    "nearby": [
      { "rank": 267, "user_id": 53408, "username": "madcap76", "public_name": "David Ray S.", "level": 20, "score": 310344, "is_current_user": false },
      { "rank": 268, "user_id": 7032685, "username": "ahmadayaz", "public_name": "Raja Ahmad Ayaz N.", "level": 20, "score": 309961, "is_current_user": true },
      { "rank": 269, "user_id": 33712082, "username": "MasterUday", "public_name": "Pro Digital Experts", "level": 20, "score": 309726, "is_current_user": false }
    ]
  }
}
```

- [ ] **Step 2: Write the failing test**

Create `tests/Feature/GamificationIngestTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\GamificationSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GamificationIngestTest extends TestCase
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
        return json_decode(file_get_contents(base_path('tests/Fixtures/gamification-sample.json')), true);
    }

    public function test_rejects_missing_token(): void
    {
        $this->postJson('/api/gamification/ingest', $this->payload())
            ->assertStatus(401);
        $this->assertSame(0, GamificationSnapshot::count());
    }

    public function test_rejects_wrong_token(): void
    {
        $this->withHeader('Authorization', 'Bearer nope')
            ->postJson('/api/gamification/ingest', $this->payload())
            ->assertStatus(401);
        $this->assertSame(0, GamificationSnapshot::count());
    }

    public function test_valid_token_stores_extracted_snapshot(): void
    {
        $this->withHeader('Authorization', 'Bearer ' . self::TOKEN)
            ->postJson('/api/gamification/ingest', $this->payload())
            ->assertOk()
            ->assertJson(['success' => true]);

        $snap = GamificationSnapshot::firstOrFail();
        $this->assertSame(268, $snap->self_rank);
        $this->assertSame(309961, $snap->self_score);
        $this->assertSame(20, $snap->self_level);
        $this->assertSame('Raja Ahmad Ayaz N.', $snap->self_public_name);
        $this->assertCount(5, $snap->top5);
        $this->assertSame('Chandrasekhar G.', $snap->top5[0]['public_name']);
        $this->assertTrue($snap->top5[1]['is_current_user'] === false);
        // raw retained and decodes back to the payload
        $this->assertSame(7032685, json_decode($snap->raw, true)['user']['id']);
    }

    public function test_accepts_x_ingest_token_header(): void
    {
        $this->withHeader('X-Ingest-Token', self::TOKEN)
            ->postJson('/api/gamification/ingest', $this->payload())
            ->assertOk();
        $this->assertSame(1, GamificationSnapshot::count());
    }

    public function test_rejects_payload_without_leaderboard_top(): void
    {
        $this->withHeader('Authorization', 'Bearer ' . self::TOKEN)
            ->postJson('/api/gamification/ingest', ['source' => ['scraped_at' => '2026-07-16T11:35:45Z']])
            ->assertStatus(422);
        $this->assertSame(0, GamificationSnapshot::count());
    }

    public function test_reposting_same_scraped_at_is_idempotent(): void
    {
        $p = $this->payload();
        $this->withHeader('Authorization', 'Bearer ' . self::TOKEN)->postJson('/api/gamification/ingest', $p)->assertOk();
        $this->withHeader('Authorization', 'Bearer ' . self::TOKEN)->postJson('/api/gamification/ingest', $p)->assertOk();
        $this->assertSame(1, GamificationSnapshot::count());
    }
}
```

- [ ] **Step 3: Run test to verify it fails**

Run: `./vendor/bin/sail test --filter=GamificationIngestTest`
Expected: FAIL — route/middleware/controller do not exist.

- [ ] **Step 4: Add the config token**

In `config/variables.php`, add this line inside the returned array (next to `openAIKey`):

```php
    "gamificationIngestToken" => env('GAMIFICATION_INGEST_TOKEN'),
```

- [ ] **Step 5: Create the middleware**

Create `app/Http/Middleware/EnsureGamificationToken.php`:

```php
<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureGamificationToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) config('variables.gamificationIngestToken');
        $provided = (string) ($request->bearerToken() ?? $request->header('X-Ingest-Token', ''));

        if ($expected === '' || ! hash_equals($expected, $provided)) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        return $next($request);
    }
}
```

- [ ] **Step 6: Register the middleware alias**

In `app/Http/Kernel.php`, add to the `$middlewareAliases` array (after the `'admin'` line):

```php
    'gamification.token' => \App\Http\Middleware\EnsureGamificationToken::class,
```

- [ ] **Step 7: Create the controller with `ingest`**

Create `app/Http/Controllers/GamificationController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Models\GamificationSnapshot;
use Carbon\Carbon;
use Illuminate\Http\Request;

class GamificationController extends Controller
{
    public function ingest(Request $request)
    {
        $payload = $request->all();

        $top = $payload['leaderboard']['top'] ?? null;
        if (! is_array($top)) {
            return response()->json(['message' => 'Invalid payload'], 422);
        }

        $top5 = collect($top)->map(fn ($e) => [
            'rank' => $e['rank'] ?? null,
            'user_id' => $e['user_id'] ?? null,
            'username' => $e['username'] ?? null,
            'public_name' => $e['public_name'] ?? null,
            'level' => $e['level'] ?? null,
            'score' => $e['score'] ?? null,
            'is_current_user' => (bool) ($e['is_current_user'] ?? false),
        ])->values()->all();

        $self = collect($payload['leaderboard']['nearby'] ?? [])
            ->first(fn ($e) => ($e['is_current_user'] ?? false) === true);

        $scrapedAt = Carbon::parse($payload['source']['scraped_at'] ?? now());

        $snapshot = GamificationSnapshot::updateOrCreate(
            ['scraped_at' => $scrapedAt],
            [
                'self_rank' => $self['rank'] ?? null,
                'self_score' => $self['score'] ?? ($payload['level']['xp_total'] ?? null),
                'self_level' => $self['level'] ?? ($payload['level']['level'] ?? null),
                'self_username' => $self['username'] ?? null,
                'self_public_name' => $self['public_name'] ?? null,
                'top5' => $top5,
                'raw' => json_encode($payload),
            ]
        );

        return response()->json(['success' => true, 'id' => $snapshot->id]);
    }
}
```

- [ ] **Step 8: Register the ingest route**

In `routes/api.php`, add (import the controller at the top: `use App\Http\Controllers\GamificationController;`):

```php
Route::post('gamification/ingest', [GamificationController::class, 'ingest'])
    ->middleware('gamification.token');
```

- [ ] **Step 9: Run test to verify it passes**

Run: `./vendor/bin/sail test --filter=GamificationIngestTest`
Expected: PASS (6 tests).

- [ ] **Step 10: Commit**

```bash
git add config/variables.php app/Http/Middleware/EnsureGamificationToken.php app/Http/Kernel.php app/Http/Controllers/GamificationController.php routes/api.php tests/Fixtures/gamification-sample.json tests/Feature/GamificationIngestTest.php
git commit -m "feat: add token-guarded gamification ingest endpoint"
```

---

### Task 3: Leaderboard page

**Files:**
- Modify: `app/Http/Controllers/GamificationController.php` (add `index`)
- Create: `resources/views/content/pages/leaderboard.blade.php`
- Modify: `routes/web.php` (add `/leaderboard` in the auth group)
- Modify: `resources/menu/verticalMenu.json` (add Leaderboard entry)
- Test: `tests/Feature/LeaderboardPageTest.php`

**Interfaces:**
- Consumes: `GamificationSnapshot` (Task 1), `GamificationController` (Task 2).
- Produces: `GET /leaderboard` (name `leaderboard`) → `content.pages.leaderboard` with `latest` (nullable snapshot) and `history` (array of `{date, rank, score}`).

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/LeaderboardPageTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\GamificationSnapshot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LeaderboardPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_requires_auth(): void
    {
        $this->get('/leaderboard')->assertRedirect();
    }

    public function test_renders_latest_snapshot(): void
    {
        GamificationSnapshot::create([
            'scraped_at' => '2026-07-16T11:35:45Z',
            'self_rank' => 268,
            'self_score' => 309961,
            'self_level' => 20,
            'self_username' => 'ahmadayaz',
            'self_public_name' => 'Raja Ahmad Ayaz N.',
            'top5' => [
                ['rank' => 1, 'user_id' => 7480467, 'username' => 'cgullapalli', 'public_name' => 'Chandrasekhar G.', 'level' => 20, 'score' => 4593118, 'is_current_user' => false],
            ],
            'raw' => '{}',
        ]);

        $res = $this->actingAs(User::factory()->create())->get('/leaderboard')->assertOk();
        $res->assertSee('268');
        $res->assertSee('Chandrasekhar G.');
    }

    public function test_empty_state_when_no_snapshots(): void
    {
        $this->actingAs(User::factory()->create())->get('/leaderboard')
            ->assertOk()
            ->assertSee('No leaderboard data yet');
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/sail test --filter=LeaderboardPageTest`
Expected: FAIL — route `/leaderboard` not defined.

- [ ] **Step 3: Add `index` to the controller**

In `app/Http/Controllers/GamificationController.php`, add:

```php
    public function index()
    {
        $latest = GamificationSnapshot::orderByDesc('scraped_at')->first();

        $history = GamificationSnapshot::orderBy('scraped_at')
            ->limit(90)
            ->get(['scraped_at', 'self_rank', 'self_score'])
            ->map(fn ($s) => [
                'date' => $s->scraped_at->format('Y-m-d'),
                'rank' => $s->self_rank,
                'score' => $s->self_score,
            ])
            ->all();

        return view('content.pages.leaderboard', [
            'latest' => $latest,
            'history' => $history,
        ]);
    }
```

- [ ] **Step 4: Register the web route**

In `routes/web.php`, inside the authenticated route group (next to the other `Route::get` entries like `/relevance`/`/review` area), add:

```php
    Route::get('/leaderboard', [\App\Http\Controllers\GamificationController::class, 'index'])->name('leaderboard');
```

- [ ] **Step 5: Create the view**

Create `resources/views/content/pages/leaderboard.blade.php`:

```blade
@extends('layouts/layoutMaster')

@section('title', 'Leaderboard')

@section('vendor-script')
    <script src="{{ asset('assets/vendor/libs/apex-charts/apexcharts.js') }}"></script>
@endsection

@section('content')
    <h4 class="page-title">Leaderboard</h4>

    @if (! $latest)
        <div class="card"><div class="card-body">
            <p class="text-muted mb-0 py-4 text-center">No leaderboard data yet.</p>
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
```

- [ ] **Step 6: Add the sidebar entry**

In `resources/menu/verticalMenu.json`, add this object to the `menu` array (e.g. after the `bids` entry):

```json
    {
      "url": "/leaderboard",
      "name": "Leaderboard",
      "icon": "menu-icon tf-icons bx bx-trophy",
      "slug": "leaderboard"
    },
```

- [ ] **Step 7: Run test to verify it passes**

Run: `./vendor/bin/sail test --filter=LeaderboardPageTest`
Expected: PASS (3 tests).

- [ ] **Step 8: Run the full gamification suite + broad check**

Run: `./vendor/bin/sail test --filter=Gamification`
Expected: PASS — model + ingest tests.

Run: `./vendor/bin/sail test`
Expected: all pass except the known pre-existing `ExampleTest` (root `/` 302).

- [ ] **Step 9: Commit**

```bash
git add app/Http/Controllers/GamificationController.php resources/views/content/pages/leaderboard.blade.php routes/web.php resources/menu/verticalMenu.json tests/Feature/LeaderboardPageTest.php
git commit -m "feat: add Leaderboard page with rank/score/level, top-5, and trend"
```

---

## Manual Verification (after deploy)

1. `php artisan migrate` → `gamification_snapshots` exists.
2. Set `GAMIFICATION_INGEST_TOKEN` in prod `.env` (long random string). Clear config cache if cached.
3. `curl -X POST https://<host>/api/gamification/ingest -H "Authorization: Bearer <token>" -H "Content-Type: application/json" --data @gamification-data.txt` → `{"success":true,...}`. Without the header → `401`.
4. Open `/leaderboard` → Rank/Score/Level cards, Top-5 table (your row highlighted), and the two trend charts once ≥2 snapshots exist.
5. Point the external scraper at the endpoint with the token; confirm daily snapshots accumulate and the trend fills in.

## Revert Plan

1. `php artisan migrate:rollback` (drops `gamification_snapshots`).
2. Delete `GamificationController`, `EnsureGamificationToken`, `GamificationSnapshot`, the migration, `leaderboard.blade.php`, the api route line, the web route line, the Kernel alias, the config line, the sidebar entry, the fixture, and the three test files.
Nothing else is touched.

## Self-Review Notes

- **Spec coverage:** token middleware (Task 2), config token (T2), ingest route/idempotency/extraction/422 (T2), snapshot table+model (T1), leaderboard page cards/top-5/trend/empty-state/auth (T3), reversed rank axis (T3), sidebar entry (T3), fixture-based tests (T2/T3). ✓
- **Type consistency:** `gamification.token`, `variables.gamificationIngestToken`, `self_rank/self_score/self_level/self_username/self_public_name`, `top5` (array incl. `is_current_user`), `scraped_at`, `updateOrCreate` key, `latest`/`history` view vars are consistent across tasks. ✓
- **Placeholder scan:** none — all steps carry concrete code/commands. ✓
- **Security:** 401 leaks nothing, `hash_equals` timing-safe, blank config rejects, ingest never 500s on bad input. ✓
