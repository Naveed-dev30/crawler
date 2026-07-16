# Bids Page Redesign Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Redesign `/bids` into a filterable, auto-refreshing page with summary cards, Placed/Failed table tabs, and a left slide-over detail panel.

**Architecture:** `BidController` gains a shared filtered-query helper feeding a JSON `data` endpoint (cards + rendered rows + pagination) and a `detail` endpoint (offcanvas panel HTML). The rewritten `home.blade.php` is JS-driven: it fetches `/bids/data` on load / filter change / tab switch / pagination / 15s poll, and opens one reused left offcanvas whose content is swapped per row. `updateBidCheck` returns JSON so Correct/Incorrect works in-panel.

**Tech Stack:** Laravel 10, Eloquent, Blade partials, Bootstrap 5.2.3 (offcanvas), vanilla `fetch`, PHPUnit feature tests (SQLite in-memory, RefreshDatabase).

## Global Constraints

- **Placed** = `bid_status` in `{pending, completed}`. **Failed** = `{failed, expired}`.
- Amount filter targets `bids.price`; date filter targets `bids.created_at`.
- Text search matches `proposals.title` (LIKE `%q%`) OR `proposals.project_id` (LIKE `%q%`).
- `type` filter validated against `{fixed, hourly}`; anything else = no type filter.
- Cards (total/placed/failed) are computed over the filtered set **independent of the active tab**.
- Base query MUST `->select('bids.*')` after joining `proposals` (else the join overwrites `bids.id`).
- All new endpoints live inside the `Route::middleware(['auth'])` group; JSON requests → 401 when unauthenticated.
- CSRF for POST: read `meta[name="csrf-token"]` and send header `X-CSRF-TOKEN` (existing pattern in `relevance.blade.php`).
- Bootstrap offcanvas via global `bootstrap.Offcanvas.getOrCreateInstance(el)`.
- Tests: `use RefreshDatabase;`, auth via `actingAs(User::factory()->create())`, freeze time with `Carbon::setTestNow(...)`. Run one test: `docker exec crawler-laravel.test-1 php artisan test --filter=Name` (DB host `mysql` only resolves inside the container; tests use SQLite so any env works, but use the container to be safe).

---

### Task 1: `/bids/data` endpoint + filtered-query helper + row partial

**Files:**
- Modify: `app/Http/Controllers/BidController.php` (add `filteredBidQuery()`, `data()`; add `use Carbon\Carbon;` if missing — it is already imported)
- Modify: `routes/web.php` (add `bids.data` route)
- Create: `resources/views/_partials/bid-row.blade.php`
- Test: `tests/Feature/BidsDataTest.php`

**Interfaces:**
- Produces: `GET /bids/data?tab=&from=&to=&min=&max=&type=&q=&page=` → JSON `{ cards: {total, placed, failed}, rowsHtml, paginationHtml }`.
- Produces (private): `filteredBidQuery(Request $request): \Illuminate\Database\Eloquent\Builder` — Bid query joined to proposals, `select('bids.*')`, all filters applied, NO status/tab filter.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/BidsDataTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Bid;
use App\Models\Proposal;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BidsDataTest extends TestCase
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

    private function seed(): void
    {
        $p1 = Proposal::factory()->create(['type' => 'fixed', 'title' => 'Laravel API build', 'project_id' => 1234, 'country' => 'India']);
        $p2 = Proposal::factory()->create(['type' => 'hourly', 'title' => 'React app', 'project_id' => 5678, 'country' => 'USA']);

        Bid::factory()->create(['proposal_id' => $p1->id, 'bid_status' => 'pending',   'price' => 100, 'created_at' => '2026-07-10 09:00:00']);
        Bid::factory()->create(['proposal_id' => $p1->id, 'bid_status' => 'completed', 'price' => 500, 'created_at' => '2026-07-11 09:00:00']);
        Bid::factory()->create(['proposal_id' => $p2->id, 'bid_status' => 'failed',    'price' => 200, 'created_at' => '2026-07-12 09:00:00']);
        Bid::factory()->create(['proposal_id' => $p2->id, 'bid_status' => 'expired',   'price' => 300, 'created_at' => '2026-07-13 09:00:00']);
    }

    public function test_requires_auth(): void
    {
        $this->getJson('/bids/data')->assertUnauthorized();
    }

    public function test_cards_and_default_placed_tab(): void
    {
        $this->seed();

        $res = $this->actingAs(User::factory()->create())->getJson('/bids/data')->assertOk();

        $this->assertEquals(4, $res->json('cards.total'));
        $this->assertEquals(2, $res->json('cards.placed'));
        $this->assertEquals(2, $res->json('cards.failed'));
        // default tab = placed → rows contain the pending+completed bids' projects, not the failed ones
        $this->assertStringContainsString('1234', $res->json('rowsHtml'));
        $this->assertStringContainsString('pending', $res->json('rowsHtml'));
        $this->assertStringNotContainsString('expired', $res->json('rowsHtml'));
    }

    public function test_failed_tab(): void
    {
        $this->seed();

        $res = $this->actingAs(User::factory()->create())->getJson('/bids/data?tab=failed')->assertOk();

        $this->assertStringContainsString('failed', $res->json('rowsHtml'));
        $this->assertStringContainsString('expired', $res->json('rowsHtml'));
        $this->assertStringNotContainsString('pending', $res->json('rowsHtml'));
    }

    public function test_type_filter(): void
    {
        $this->seed();

        $res = $this->actingAs(User::factory()->create())->getJson('/bids/data?type=hourly')->assertOk();

        $this->assertEquals(2, $res->json('cards.total'));   // both p2 bids
        $this->assertEquals(0, $res->json('cards.placed'));
        $this->assertEquals(2, $res->json('cards.failed'));
    }

    public function test_min_price_filter(): void
    {
        $this->seed();

        $res = $this->actingAs(User::factory()->create())->getJson('/bids/data?min=250')->assertOk();

        // prices >= 250 : completed(500) + expired(300)
        $this->assertEquals(2, $res->json('cards.total'));
        $this->assertEquals(1, $res->json('cards.placed'));
        $this->assertEquals(1, $res->json('cards.failed'));
    }

    public function test_search_by_title_and_project_id(): void
    {
        $this->seed();
        $user = User::factory()->create();

        $byTitle = $this->actingAs($user)->getJson('/bids/data?q=Laravel')->assertOk();
        $this->assertEquals(2, $byTitle->json('cards.total')); // both p1 bids

        $byId = $this->actingAs($user)->getJson('/bids/data?q=5678')->assertOk();
        $this->assertEquals(2, $byId->json('cards.total')); // both p2 bids
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker exec crawler-laravel.test-1 php artisan test --filter=BidsDataTest`
Expected: FAIL — route `/bids/data` not defined (404/method missing).

- [ ] **Step 3: Create the row partial**

Create `resources/views/_partials/bid-row.blade.php`:

```blade
@php
    $statusClass = $bid->bid_status === 'completed'
        ? 'bg-label-success'
        : ($bid->bid_status === 'pending' ? 'bg-label-primary' : 'bg-label-danger');
    $checkIcon = $bid->check === 'Correct'
        ? 'fa fa-check text-success'
        : ($bid->check === 'Incorrect' ? 'fa fa-close text-danger' : 'fa fa-warning text-warning');
@endphp
<tr>
    <td>{{ $bid->proposal->project_id }}</td>
    <td>{{ \Illuminate\Support\Str::limit($bid->proposal->title, 30) }}</td>
    <td>{{ $bid->price }}$ - {{ $bid->proposal->country }}</td>
    <td><span class="badge {{ $statusClass }} me-1">{{ $bid->bid_status }}</span></td>
    <td>{{ $bid->proposal->type }}</td>
    <td>
        <div class="col">
            <div class="row">{{ $bid->created_at->format('h:i a') }}</div>
            <div class="row text-light">{{ $bid->created_at->diffForHumans(null, true) }}</div>
        </div>
    </td>
    <td>
        <button type="button" class="btn btn-sm btn-outline-primary bid-view-btn" data-bid-id="{{ $bid->id }}">
            <i class="{{ $bid->is_seen ? 'fa fa-eye text-success' : 'fa fa-eye' }} me-1"></i> View
        </button>
        <i class="{{ $checkIcon }} ms-1" data-check-dot="{{ $bid->id }}"></i>
    </td>
</tr>
```

- [ ] **Step 4: Add the route**

In `routes/web.php`, add the data route immediately after the existing `->name('bids');` line and before `Route::resource('bids', ...)`:

```php
    Route::get('/bids/data', [BidController::class, 'data'])->name('bids.data');
```

(The final block becomes:
```php
    Route::get('/bids', [BidController::class, 'index'])->name('bids');
    Route::get('/bids/data', [BidController::class, 'data'])->name('bids.data');
    Route::resource('bids', BidController::class)->except(['index']);
```
`/bids/data` is registered before the resource's `/bids/{bid}` so it is never captured as a `{bid}`.)

- [ ] **Step 5: Add the helper + `data()` to BidController**

In `app/Http/Controllers/BidController.php`, add these two methods (place after `index()`):

```php
  private function filteredBidQuery(Request $request)
  {
    $query = Bid::query()
      ->join('proposals', 'bids.proposal_id', '=', 'proposals.id')
      ->select('bids.*');

    if ($request->filled('from')) {
      $query->where('bids.created_at', '>=', Carbon::parse($request->query('from'))->startOfDay());
    }
    if ($request->filled('to')) {
      $query->where('bids.created_at', '<=', Carbon::parse($request->query('to'))->endOfDay());
    }
    if (is_numeric($request->query('min'))) {
      $query->where('bids.price', '>=', (float) $request->query('min'));
    }
    if (is_numeric($request->query('max'))) {
      $query->where('bids.price', '<=', (float) $request->query('max'));
    }
    if (in_array($request->query('type'), ['fixed', 'hourly'], true)) {
      $query->where('proposals.type', $request->query('type'));
    }
    if ($request->filled('q')) {
      $q = $request->query('q');
      $query->where(function ($sub) use ($q) {
        $sub->where('proposals.title', 'like', "%{$q}%")
          ->orWhere('proposals.project_id', 'like', "%{$q}%");
      });
    }

    return $query;
  }

  public function data(Request $request)
  {
    $placed = ['pending', 'completed'];
    $failed = ['failed', 'expired'];

    $base = $this->filteredBidQuery($request);

    $cards = [
      'total'  => (clone $base)->count(),
      'placed' => (clone $base)->whereIn('bids.bid_status', $placed)->count(),
      'failed' => (clone $base)->whereIn('bids.bid_status', $failed)->count(),
    ];

    $tab = $request->query('tab') === 'failed' ? 'failed' : 'placed';
    $statuses = $tab === 'failed' ? $failed : $placed;

    $bids = (clone $base)
      ->whereIn('bids.bid_status', $statuses)
      ->with('proposal')
      ->latest('bids.created_at')
      ->paginate(100)
      ->withQueryString();

    $rowsHtml = '';
    foreach ($bids as $bid) {
      $rowsHtml .= view('_partials.bid-row', ['bid' => $bid])->render();
    }
    if ($bids->isEmpty()) {
      $rowsHtml = '<tr><td colspan="7" class="text-center text-muted py-4">No bids match these filters.</td></tr>';
    }

    return response()->json([
      'cards' => $cards,
      'rowsHtml' => $rowsHtml,
      'paginationHtml' => $bids->links('vendor.pagination.bootstrap-5')->render(),
    ]);
  }
```

- [ ] **Step 6: Run test to verify it passes**

Run: `docker exec crawler-laravel.test-1 php artisan test --filter=BidsDataTest`
Expected: PASS (6 tests).

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/BidController.php routes/web.php resources/views/_partials/bid-row.blade.php tests/Feature/BidsDataTest.php
git commit -m "feat: add /bids/data filtered json endpoint with cards and rows"
```

---

### Task 2: `/bids/{bid}/detail` slide-over panel endpoint

**Files:**
- Modify: `app/Http/Controllers/BidController.php` (add `detail()`)
- Modify: `routes/web.php` (add `bids.detail` route)
- Create: `resources/views/_partials/bid-detail.blade.php`
- Test: `tests/Feature/BidsDetailTest.php`

**Interfaces:**
- Produces: `GET /bids/{bid}/detail` → offcanvas panel HTML (string); sets `is_seen = true`. Route model binding on `{bid}`.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/BidsDetailTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Bid;
use App\Models\Proposal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BidsDetailTest extends TestCase
{
    use RefreshDatabase;

    public function test_requires_auth(): void
    {
        $bid = Bid::factory()->create();
        $this->get('/bids/' . $bid->id . '/detail')->assertRedirect('/login');
    }

    public function test_detail_returns_panel_and_marks_seen(): void
    {
        $proposal = Proposal::factory()->create(['project_id' => 4242, 'title' => 'Seeded project']);
        $bid = Bid::factory()->create(['proposal_id' => $proposal->id, 'is_seen' => false, 'bid_status' => 'pending']);

        $res = $this->actingAs(User::factory()->create())->get('/bids/' . $bid->id . '/detail')->assertOk();

        $res->assertSee('View on Freelancer');
        $res->assertSee('4242'); // freelancer project link uses project_id
        $res->assertSee('Correct');
        $res->assertSee('Incorrect');

        $this->assertTrue((bool) Bid::find($bid->id)->is_seen);
    }

    public function test_unknown_bid_returns_404(): void
    {
        $this->actingAs(User::factory()->create())->get('/bids/999999/detail')->assertNotFound();
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker exec crawler-laravel.test-1 php artisan test --filter=BidsDetailTest`
Expected: FAIL — route `/bids/{bid}/detail` not defined.

- [ ] **Step 3: Create the detail partial**

Create `resources/views/_partials/bid-detail.blade.php`:

```blade
@php
    $checkBadge = $bid->check === 'Correct'
        ? 'bg-success'
        : ($bid->check === 'Incorrect' ? 'bg-danger' : 'bg-warning');
@endphp
<div class="offcanvas-header">
    <h5 class="offcanvas-title">Bid #{{ $bid->id }}</h5>
    <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close"></button>
</div>
<div class="offcanvas-body" data-bid-id="{{ $bid->id }}">
    <span class="badge {{ $checkBadge }}" data-check-badge>{{ $bid->check }}</span>

    <p class="mt-3 mb-1">Last Updated: {{ $bid->proposal->updated_at->format('d-M, Y') }}</p>
    <h6>Project Min Budget: <span class="fw-light">{{ $bid->proposal->min_budget }}$</span></h6>
    <h6>Project Max Budget: <span class="fw-light">{{ $bid->proposal->max_budget }}$</span></h6>
    <h6>Project Quoted: <span class="fw-light">{{ $bid->price }}</span></h6>
    <h6>Bid Status: <span class="fw-light">{{ $bid->bid_status }}</span></h6>
    @if (strtolower($bid->bid_status) === 'failed')
        <span class="fw-light text-danger">{{ $bid->error_message }}</span>
    @endif
    <h6>Type: <span class="fw-light">{{ $bid->proposal->type }}</span></h6>

    <div class="divider divider-primary"><div class="divider-text">Title/Description</div></div>
    <h6>Title:</h6>
    <span class="fw-light">{{ $bid->proposal->title }}</span>
    <h6 class="mt-4">Project Description:</h6>
    <span class="fw-light">{{ $bid->proposal->description }}</span>

    <div class="divider divider-primary"><div class="divider-text">Bid</div></div>
    <h6>Coverletter</h6>
    <span class="fw-light">{{ $bid->cover_letter }}</span>

    <div class="mt-4 d-flex flex-column gap-2">
        <a href="https://www.freelancer.com/projects/{{ $bid->proposal->project_id }}" target="_blank" class="btn btn-primary">
            View on Freelancer
        </a>
        <div class="d-flex gap-2">
            <button type="button" class="btn btn-outline-success bid-check-btn" data-bid-id="{{ $bid->id }}" data-check="Correct">Correct</button>
            <button type="button" class="btn btn-outline-danger bid-check-btn" data-bid-id="{{ $bid->id }}" data-check="Incorrect">Incorrect</button>
        </div>
    </div>
</div>
```

- [ ] **Step 4: Add the route**

In `routes/web.php`, add right after the `bids.data` route line:

```php
    Route::get('/bids/{bid}/detail', [BidController::class, 'detail'])->name('bids.detail');
```

- [ ] **Step 5: Add `detail()` to BidController**

In `app/Http/Controllers/BidController.php`, add after `data()`:

```php
  public function detail(Bid $bid)
  {
    $bid->is_seen = true;
    $bid->save();
    $bid->load('proposal');

    return view('_partials.bid-detail', ['bid' => $bid])->render();
  }
```

- [ ] **Step 6: Run test to verify it passes**

Run: `docker exec crawler-laravel.test-1 php artisan test --filter=BidsDetailTest`
Expected: PASS (3 tests).

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/BidController.php routes/web.php resources/views/_partials/bid-detail.blade.php tests/Feature/BidsDetailTest.php
git commit -m "feat: add /bids/{bid}/detail slide-over panel endpoint"
```

---

### Task 3: `updateBidCheck` returns JSON

**Files:**
- Modify: `app/Http/Controllers/BidController.php` (`updateBidCheck()`)
- Test: `tests/Feature/BidCheckUpdateTest.php`

**Interfaces:**
- Produces: `POST /updateBidCheck` (existing route `updateBidCheck`, params `bid_id`, `check`) → JSON `{ success: true, check }`; 404 JSON when bid missing.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/BidCheckUpdateTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Bid;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BidCheckUpdateTest extends TestCase
{
    use RefreshDatabase;

    public function test_requires_auth(): void
    {
        $bid = Bid::factory()->create();
        $this->postJson('/updateBidCheck', ['bid_id' => $bid->id, 'check' => 'Correct'])
            ->assertUnauthorized();
    }

    public function test_updates_check_and_returns_json(): void
    {
        $bid = Bid::factory()->create(['check' => 'Unreviewed']);

        $this->actingAs(User::factory()->create())
            ->postJson('/updateBidCheck', ['bid_id' => $bid->id, 'check' => 'Correct'])
            ->assertOk()
            ->assertJson(['success' => true, 'check' => 'Correct']);

        $this->assertEquals('Correct', Bid::find($bid->id)->check);
    }

    public function test_missing_bid_returns_404(): void
    {
        $this->actingAs(User::factory()->create())
            ->postJson('/updateBidCheck', ['bid_id' => 999999, 'check' => 'Correct'])
            ->assertNotFound();
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker exec crawler-laravel.test-1 php artisan test --filter=BidCheckUpdateTest`
Expected: FAIL — current `updateBidCheck` redirects (returns 302, not JSON) and errors on missing bid.

- [ ] **Step 3: Rewrite `updateBidCheck`**

In `app/Http/Controllers/BidController.php`, replace the existing `updateBidCheck` method:

```php
  public function updateBidCheck(Request $request)
  {
    $bid = Bid::find($request->bid_id);

    if (!$bid) {
      return response()->json(['success' => false, 'message' => 'Bid not found.'], 404);
    }

    $bid->check = $request->check;
    $bid->save();

    return response()->json(['success' => true, 'check' => $bid->check]);
  }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `docker exec crawler-laravel.test-1 php artisan test --filter=BidCheckUpdateTest`
Expected: PASS (3 tests).

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/BidController.php tests/Feature/BidCheckUpdateTest.php
git commit -m "feat: updateBidCheck returns json for in-panel review"
```

---

### Task 4: Bids dashboard UI (filter bar, cards, tabs, offcanvas, JS)

**Files:**
- Modify: `app/Http/Controllers/BidController.php` (`index()` → render shell, drop `$bids`)
- Modify: `resources/views/content/pages/home.blade.php` (full rewrite)
- Test: manual (browser) + full suite regression.

**Interfaces:**
- Consumes: `GET /bids/data`, `GET /bids/{bid}/detail`, `POST /updateBidCheck`.

- [ ] **Step 1: Simplify `index()`**

In `app/Http/Controllers/BidController.php`, replace the body of `index()`:

```php
  public function index()
  {
    return view('content.pages.home');
  }
```

- [ ] **Step 2: Rewrite the Bids view**

Overwrite `resources/views/content/pages/home.blade.php`:

```blade
@extends('layouts.layoutMaster')

@section('title', 'Bids')

@section('content')
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
        <h4 class="page-title mb-0">Bids</h4>
        <form action="{{ route('expire_bids') }}" method="POST" class="mb-0">
            @csrf
            <button type="submit" class="btn btn-danger">Expire Pending</button>
        </form>
    </div>

    {{-- Filter bar --}}
    <div class="card mb-3"><div class="card-body">
        <div class="row g-2 align-items-end">
            <div class="col-6 col-md-2">
                <label class="form-label mb-1">From</label>
                <input type="date" id="f-from" class="form-control form-control-sm">
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label mb-1">To</label>
                <input type="date" id="f-to" class="form-control form-control-sm">
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label mb-1">Min amount</label>
                <input type="number" id="f-min" class="form-control form-control-sm" min="0">
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label mb-1">Max amount</label>
                <input type="number" id="f-max" class="form-control form-control-sm" min="0">
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label mb-1">Type</label>
                <select id="f-type" class="form-select form-select-sm">
                    <option value="">All</option>
                    <option value="hourly">Hourly</option>
                    <option value="fixed">Fixed</option>
                </select>
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label mb-1">Search</label>
                <input type="text" id="f-search" class="form-control form-control-sm" placeholder="Title or project id">
            </div>
        </div>
    </div></div>

    {{-- Cards --}}
    <div class="row g-3 mb-3">
        <div class="col-md-4"><div class="card"><div class="card-body">
            <span class="text-muted">Total</span><h3 id="card-total">—</h3>
        </div></div></div>
        <div class="col-md-4"><div class="card"><div class="card-body">
            <span class="text-muted">Placed</span><h3 id="card-placed" class="text-primary">—</h3>
        </div></div></div>
        <div class="col-md-4"><div class="card"><div class="card-body">
            <span class="text-muted">Failed</span><h3 id="card-failed" class="text-danger">—</h3>
        </div></div></div>
    </div>

    {{-- Tabs + table --}}
    <div class="card">
        <div class="card-header pb-0">
            <ul class="nav nav-tabs card-header-tabs" id="bids-tabs">
                <li class="nav-item"><button class="nav-link active" data-tab="placed" type="button">Placed Bids</button></li>
                <li class="nav-item"><button class="nav-link" data-tab="failed" type="button">Failed Bids</button></li>
            </ul>
        </div>
        <div class="table-responsive">
            <table class="table mb-0">
                <thead>
                    <tr>
                        <th>ID</th><th>Title</th><th>Price</th><th>Status</th><th>Type</th><th>Time</th><th>Review</th>
                    </tr>
                </thead>
                <tbody id="bids-tbody">
                    <tr><td colspan="7" class="text-center text-muted py-4">Loading…</td></tr>
                </tbody>
            </table>
        </div>
    </div>
    <div class="mt-4 card px-4 pt-3" id="bids-pagination"></div>

    {{-- Reused left slide-over --}}
    <div class="offcanvas offcanvas-start" tabindex="-1" id="bidOffcanvas" style="width: 32rem; max-width: 90vw;">
        <div id="bidOffcanvasContent"></div>
    </div>
@endsection

@section('page-script')
    <script>
        (function () {
            const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
            let currentTab = 'placed';
            let currentPage = 1;
            let searchFocused = false;

            const el = id => document.getElementById(id);

            function buildParams() {
                const p = new URLSearchParams();
                p.set('tab', currentTab);
                p.set('page', currentPage);
                const from = el('f-from').value; if (from) p.set('from', from);
                const to = el('f-to').value; if (to) p.set('to', to);
                const min = el('f-min').value; if (min) p.set('min', min);
                const max = el('f-max').value; if (max) p.set('max', max);
                const type = el('f-type').value; if (type) p.set('type', type);
                const q = el('f-search').value.trim(); if (q) p.set('q', q);
                return p;
            }

            async function loadData() {
                let data;
                try {
                    const res = await fetch('/bids/data?' + buildParams().toString(), {
                        headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
                    });
                    if (!res.ok) return;           // keep last render, retry next tick
                    data = await res.json();
                } catch (e) { return; }

                el('card-total').textContent = data.cards.total;
                el('card-placed').textContent = data.cards.placed;
                el('card-failed').textContent = data.cards.failed;
                el('bids-tbody').innerHTML = data.rowsHtml;
                el('bids-pagination').innerHTML = data.paginationHtml;
            }

            function reload() { currentPage = 1; loadData(); }

            // Filters
            ['f-from', 'f-to', 'f-min', 'f-max', 'f-type'].forEach(id => el(id).addEventListener('change', reload));
            let searchTimer;
            el('f-search').addEventListener('input', () => { clearTimeout(searchTimer); searchTimer = setTimeout(reload, 400); });
            el('f-search').addEventListener('focus', () => searchFocused = true);
            el('f-search').addEventListener('blur', () => searchFocused = false);

            // Tabs
            document.querySelectorAll('#bids-tabs .nav-link').forEach(btn => {
                btn.addEventListener('click', function () {
                    document.querySelectorAll('#bids-tabs .nav-link').forEach(b => b.classList.remove('active'));
                    this.classList.add('active');
                    currentTab = this.dataset.tab;
                    reload();
                });
            });

            // Delegated: pagination links
            el('bids-pagination').addEventListener('click', function (ev) {
                const a = ev.target.closest('a');
                if (!a) return;
                ev.preventDefault();
                const url = new URL(a.href, window.location.origin);
                const page = url.searchParams.get('page');
                if (page) { currentPage = parseInt(page, 10); loadData(); }
            });

            // Delegated: open slide-over (swap content if already open)
            el('bids-tbody').addEventListener('click', async function (ev) {
                const btn = ev.target.closest('.bid-view-btn');
                if (!btn) return;
                const id = btn.dataset.bidId;
                const res = await fetch('/bids/' + id + '/detail', { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
                if (!res.ok) return;
                el('bidOffcanvasContent').innerHTML = await res.text();
                // mark the row's eye as seen
                const icon = btn.querySelector('i');
                if (icon) { icon.classList.add('text-success'); }
                bootstrap.Offcanvas.getOrCreateInstance(el('bidOffcanvas')).show();
            });

            // Delegated: Correct/Incorrect inside the panel
            el('bidOffcanvasContent').addEventListener('click', async function (ev) {
                const btn = ev.target.closest('.bid-check-btn');
                if (!btn) return;
                const id = btn.dataset.bidId;
                const check = btn.dataset.check;
                const res = await fetch('/updateBidCheck', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf },
                    body: JSON.stringify({ bid_id: id, check: check })
                });
                if (!res.ok) return;
                const badge = el('bidOffcanvasContent').querySelector('[data-check-badge]');
                if (badge) {
                    badge.textContent = check;
                    badge.className = 'badge ' + (check === 'Correct' ? 'bg-success' : 'bg-danger');
                }
                const dot = document.querySelector('[data-check-dot="' + id + '"]');
                if (dot) {
                    dot.className = (check === 'Correct' ? 'fa fa-check text-success' : 'fa fa-close text-danger') + ' ms-1';
                    dot.setAttribute('data-check-dot', id);
                }
            });

            // Auto-refresh: skip while typing search or past page 1
            setInterval(() => { if (!searchFocused && currentPage === 1) loadData(); }, 15000);

            // Initial load
            loadData();
        })();
    </script>
@endsection
```

- [ ] **Step 3: Run the full suite (regression)**

Run: `docker exec crawler-laravel.test-1 php artisan test`
Expected: all Bids + Statistics + Relevance tests PASS (pre-existing `ExampleTest` GET `/`→302 remains the only failure, unrelated).

- [ ] **Step 4: Manual browser check**

`localhost:8000/bids` (logged in): confirm cards populate; Placed/Failed tabs switch the table; filters (date/min/max/type/search) narrow cards + rows; a row's **View** opens a **left** slide-over; clicking another row's **View** swaps the panel content while it stays open; **View on Freelancer** opens the project; **Correct/Incorrect** update the panel badge + row dot without reload; leaving the page idle ~15s refreshes cards+table. (Data already seeded by `StatisticsDemoSeeder`.)

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/BidController.php resources/views/content/pages/home.blade.php
git commit -m "feat: build bids dashboard ui with filters, tabs, and slide-over"
```

---

## Self-Review

**Spec coverage:**
- Filter bar (date from/to, min/max on `bids.price`, type, search title+project_id) → Task 1 `filteredBidQuery` + Task 4 UI. ✓
- 3 cards total/placed/failed, filter-reactive, tab-independent → Task 1 `data()` cards + Task 4. ✓
- Placed/Failed table tabs → Task 1 `tab` param + Task 4 tabs. ✓
- Auto-refresh 15s, pause on search-focus / page>1 → Task 4 `setInterval`. ✓
- Review col = single View; left slide-over; swap-in-place when reopening → Task 4 delegated handler + `offcanvas-start`. ✓
- Panel: detail + View on Freelancer + Correct/Incorrect (kept, AJAX) → Task 2 partial + Task 3 JSON + Task 4 handler. ✓
- Marks `is_seen` on view → Task 2 `detail()`. ✓
- Placed=pending+completed, Failed=failed+expired → Task 1 status arrays. ✓
- Removed external-link + copy-id from row → Task 1 row partial omits them. ✓
- Endpoints auth-gated, JSON→401 → Task 1/2/3 tests. ✓

**Placeholder scan:** No TBD/TODO; every code step shows full code. ✓

**Type consistency:** JSON keys (`cards.total/placed/failed`, `rowsHtml`, `paginationHtml`, `success`, `check`) match between controller and JS. DOM ids (`f-from/to/min/max/type/search`, `card-total/placed/failed`, `bids-tbody`, `bids-pagination`, `bidOffcanvas`, `bidOffcanvasContent`) and data attributes (`data-bid-id`, `data-check`, `data-check-dot`, `data-check-badge`, `.bid-view-btn`, `.bid-check-btn`) are consistent across partials and the page JS. ✓

## Notes / Known Limits

- `filter_edit.blade.php` and `BidController::show()` remain (no longer linked from the row) — left in place per spec, not deleted.
- Search on `proposals.project_id` uses LIKE against the integer column (DB casts it) — matches partial/whole id strings; acceptable for this UI.
- Auto-refresh is interval polling (no websockets), matching the spec.
- Time column uses `bids.created_at` (consistent with the date filter), whereas the old table used `proposal.created_at`.
