# Relevance Review Tab Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a "Relevance" sidebar tab where an admin reviews unlabeled bids one card at a time (infinite scroll) and labels each relevant / irrelevant / scam.

**Architecture:** New nullable `admin_feedback` column on `bids`. A page route renders the first batch of cards; an AJAX endpoint returns server-rendered card HTML for later pages (infinite scroll via IntersectionObserver); a POST endpoint saves the label and the card is removed client-side. Reviewed bids (`admin_feedback` not NULL) never reappear.

**Tech Stack:** Laravel 10 (PHP 8.2), Blade, Sneat Bootstrap 5 admin theme, jQuery (ships with theme), PHPUnit feature tests on sqlite `:memory:`.

## Global Constraints

- PHP `^8.1` (runtime 8.2). No dependency changes.
- `admin_feedback` allowed values, verbatim: `relevant`, `irrelevant`, `scam`. NULL = not reviewed.
- Set model attributes directly then `save()` (match existing `BidController::updateBidCheck` style). Do NOT add `$fillable`.
- All new routes go inside the existing `Route::middleware(['auth'])->group(...)` block in `routes/web.php`.
- Follow existing Blade view conventions: `@extends('layouts.layoutMaster')`, `@section('content')`.
- Page size for pagination: 20.

---

### Task 1: Add `admin_feedback` column + model scope

**Files:**
- Create: `database/migrations/2026_07_14_000000_add_admin_feedback_to_bids_table.php`
- Modify: `app/Models/Bid.php` (add scope after `scopeGroupByDate`)
- Modify: `db.sql` (add column to `CREATE TABLE bids`, line ~40)
- Modify: `phpunit.xml:24-25` (enable sqlite `:memory:` testing DB)
- Test: `tests/Feature/RelevanceColumnTest.php`

**Interfaces:**
- Produces: `bids.admin_feedback` VARCHAR(255) NULL column; `Bid::needsFeedback()` query scope returning bids where `admin_feedback IS NULL`.

- [ ] **Step 1: Enable sqlite testing DB so feature tests don't touch dev MySQL**

Modify `phpunit.xml` — uncomment lines 24-25:

```xml
        <env name="DB_CONNECTION" value="sqlite"/>
        <env name="DB_DATABASE" value=":memory:"/>
```

- [ ] **Step 2: Write the failing test**

Create `tests/Feature/RelevanceColumnTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Bid;
use App\Models\Proposal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RelevanceColumnTest extends TestCase
{
    use RefreshDatabase;

    private function makeBid(?string $feedback = null): Bid
    {
        $proposal = new Proposal();
        $proposal->project_id = 111;
        $proposal->title = 'Test Project';
        $proposal->description = 'Test project description';
        $proposal->save();

        $bid = new Bid();
        $bid->proposal_id = $proposal->id;
        $bid->bid_status = 'pending';
        $bid->price = 100;
        $bid->cover_letter = 'Test cover letter';
        $bid->admin_feedback = $feedback;
        $bid->save();

        return $bid;
    }

    public function test_needs_feedback_scope_returns_only_null_feedback(): void
    {
        $this->makeBid(null);
        $this->makeBid('relevant');
        $this->makeBid('scam');

        $this->assertSame(1, Bid::needsFeedback()->count());
    }
}
```

- [ ] **Step 3: Run test to verify it fails**

Run: `docker compose exec laravel.test php artisan test --filter=RelevanceColumnTest`
Expected: FAIL — `admin_feedback` column missing / `needsFeedback` undefined.

- [ ] **Step 4: Create the migration**

Create `database/migrations/2026_07_14_000000_add_admin_feedback_to_bids_table.php`:

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
            $table->string('admin_feedback')->nullable()->default(null);
        });
    }

    public function down(): void
    {
        Schema::table('bids', function (Blueprint $table) {
            $table->dropColumn('admin_feedback');
        });
    }
};
```

- [ ] **Step 5: Add the `needsFeedback` scope**

In `app/Models/Bid.php`, add after `scopeGroupByDate` (before closing brace):

```php
    public function scopeNeedsFeedback($query)
    {
        return $query->whereNull('admin_feedback');
    }
```

- [ ] **Step 6: Keep `db.sql` schema consistent**

In `db.sql`, in `CREATE TABLE \`bids\``, add after the `error_message` line (the last column, ~line 40) — remember to add a trailing comma to the previous line:

```sql
  `error_message` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `admin_feedback` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL
```

- [ ] **Step 7: Run test to verify it passes**

Run: `docker compose exec laravel.test php artisan test --filter=RelevanceColumnTest`
Expected: PASS.

- [ ] **Step 8: Commit**

```bash
git add database/migrations/2026_07_14_000000_add_admin_feedback_to_bids_table.php app/Models/Bid.php db.sql phpunit.xml tests/Feature/RelevanceColumnTest.php
git commit -m "feat: add admin_feedback column and needsFeedback scope to bids"
```

---

### Task 2: Backend routes + controller methods

**Files:**
- Modify: `routes/web.php` (add 3 routes inside auth group)
- Modify: `app/Http/Controllers/BidController.php` (add 3 methods)
- Modify: `database/factories/BidFactory.php` (fill definition)
- Modify: `database/factories/ProposalFactory.php` (fill definition)
- Test: `tests/Feature/RelevanceControllerTest.php`

**Interfaces:**
- Consumes: `Bid::needsFeedback()` (Task 1).
- Produces:
  - `GET /relevance` → `BidController@relevance` (name `relevance`) — renders `content.pages.relevance` with `$bids` (paginator, page size 20).
  - `GET /relevance/load` → `BidController@loadRelevance` (name `relevance.load`) — JSON `{"html": string, "hasMore": bool}`.
  - `POST /relevance/feedback` → `BidController@storeFeedback` (name `relevance.feedback`) — body `{bid_id, feedback}`; JSON `{"success": true}` on 200, `422` on invalid.

- [ ] **Step 1: Fill the factories so tests can build records**

`database/factories/ProposalFactory.php` — replace the `definition()` body:

```php
    public function definition()
    {
        return [
            'project_id' => $this->faker->numberBetween(1000, 9999),
            'title' => $this->faker->sentence(4),
            'description' => $this->faker->paragraph(),
            'min_budget' => 100,
            'max_budget' => 500,
            'type' => 'fixed',
        ];
    }
```

`database/factories/BidFactory.php` — replace the `definition()` body:

```php
    public function definition()
    {
        return [
            'proposal_id' => \App\Models\Proposal::factory(),
            'bid_status' => 'pending',
            'price' => $this->faker->numberBetween(50, 5000),
            'cover_letter' => $this->faker->paragraph(),
            'admin_feedback' => null,
        ];
    }
```

- [ ] **Step 2: Write the failing tests**

Create `tests/Feature/RelevanceControllerTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Bid;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RelevanceControllerTest extends TestCase
{
    use RefreshDatabase;

    private function actingUser(): User
    {
        return User::factory()->create();
    }

    public function test_relevance_page_requires_auth(): void
    {
        $this->get('/relevance')->assertRedirect('/login');
    }

    public function test_load_returns_json_with_html_and_hasmore(): void
    {
        Bid::factory()->count(25)->create();

        $response = $this->actingAs($this->actingUser())
            ->getJson('/relevance/load?page=1');

        $response->assertOk()
            ->assertJsonStructure(['html', 'hasMore']);
        $this->assertTrue($response->json('hasMore')); // 25 bids, page size 20
    }

    public function test_load_second_page_has_no_more(): void
    {
        Bid::factory()->count(25)->create();

        $response = $this->actingAs($this->actingUser())
            ->getJson('/relevance/load?page=2');

        $response->assertOk();
        $this->assertFalse($response->json('hasMore'));
    }

    public function test_store_feedback_saves_label(): void
    {
        $bid = Bid::factory()->create(['admin_feedback' => null]);

        $this->actingAs($this->actingUser())
            ->postJson('/relevance/feedback', [
                'bid_id' => $bid->id,
                'feedback' => 'scam',
            ])
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->assertSame('scam', $bid->fresh()->admin_feedback);
    }

    public function test_store_feedback_rejects_invalid_value(): void
    {
        $bid = Bid::factory()->create(['admin_feedback' => null]);

        $this->actingAs($this->actingUser())
            ->postJson('/relevance/feedback', [
                'bid_id' => $bid->id,
                'feedback' => 'maybe',
            ])
            ->assertStatus(422);

        $this->assertNull($bid->fresh()->admin_feedback);
    }
}
```

- [ ] **Step 3: Run tests to verify they fail**

Run: `docker compose exec laravel.test php artisan test --filter=RelevanceControllerTest`
Expected: FAIL — routes `/relevance*` not defined (404 / method missing).

- [ ] **Step 4: Add the routes**

In `routes/web.php`, inside the `Route::middleware(['auth'])->group(function () { ... })` block, add:

```php
    Route::get('/relevance', [BidController::class, 'relevance'])->name('relevance');
    Route::get('/relevance/load', [BidController::class, 'loadRelevance'])->name('relevance.load');
    Route::post('/relevance/feedback', [BidController::class, 'storeFeedback'])->name('relevance.feedback');
```

- [ ] **Step 5: Add the controller methods**

In `app/Http/Controllers/BidController.php`, add these methods (before the final closing brace). Note `loadRelevance` renders the card partial per bid to a string:

```php
  public function relevance()
  {
    $bids = Bid::needsFeedback()->with('proposal')->latest()->paginate(20);
    return view('content.pages.relevance', ['bids' => $bids]);
  }

  public function loadRelevance(Request $request)
  {
    $bids = Bid::needsFeedback()->with('proposal')->latest()->paginate(20);

    $html = '';
    foreach ($bids as $bid) {
      $html .= view('_partials.relevance-card', ['bid' => $bid])->render();
    }

    return response()->json([
      'html' => $html,
      'hasMore' => $bids->hasMorePages(),
    ]);
  }

  public function storeFeedback(Request $request)
  {
    $validated = $request->validate([
      'bid_id' => 'required|exists:bids,id',
      'feedback' => 'required|in:relevant,irrelevant,scam',
    ]);

    $bid = Bid::find($validated['bid_id']);
    $bid->admin_feedback = $validated['feedback'];
    $bid->save();

    return response()->json(['success' => true]);
  }
```

Note: the `test_load_*` tests reference `content.pages.relevance` / `_partials.relevance-card` views which are created in Task 3. To let Task 2 tests pass independently, the load/store tests above do NOT assert on `/relevance` page HTML — but `loadRelevance` renders the card partial. Create a minimal stub partial now so Task 2 tests pass; Task 3 replaces it with the full version:

Create `resources/views/_partials/relevance-card.blade.php` (stub):

```blade
<div class="relevance-card" data-bid-id="{{ $bid->id }}">{{ optional($bid->proposal)->title }}</div>
```

- [ ] **Step 6: Run tests to verify they pass**

Run: `docker compose exec laravel.test php artisan test --filter=RelevanceControllerTest`
Expected: PASS (5 tests).

- [ ] **Step 7: Commit**

```bash
git add routes/web.php app/Http/Controllers/BidController.php database/factories/BidFactory.php database/factories/ProposalFactory.php resources/views/_partials/relevance-card.blade.php tests/Feature/RelevanceControllerTest.php
git commit -m "feat: add relevance routes and controller (page, load, feedback)"
```

---

### Task 3: Card partial + page view + menu

**Files:**
- Modify: `resources/views/_partials/relevance-card.blade.php` (replace stub with full card)
- Create: `resources/views/content/pages/relevance.blade.php`
- Modify: `resources/menu/verticalMenu.json` (add Relevance item)
- Test: `tests/Feature/RelevancePageTest.php`

**Interfaces:**
- Consumes: `BidController@relevance` renders `content.pages.relevance` with `$bids`; card partial receives `$bid` with eager-loaded `proposal`.
- Produces: `#relevance-list`, `#relevance-sentinel`, `#relevance-empty` DOM anchors and `.relevance-card` / `.relevance-btn[data-feedback]` markup consumed by Task 4 JS.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/RelevancePageTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Bid;
use App\Models\Proposal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RelevancePageTest extends TestCase
{
    use RefreshDatabase;

    public function test_page_shows_unreviewed_and_hides_reviewed(): void
    {
        $shown = Proposal::factory()->create(['title' => 'SHOWN PROJECT']);
        Bid::factory()->create(['proposal_id' => $shown->id, 'admin_feedback' => null]);

        $hidden = Proposal::factory()->create(['title' => 'HIDDEN PROJECT']);
        Bid::factory()->create(['proposal_id' => $hidden->id, 'admin_feedback' => 'relevant']);

        $response = $this->actingAs(User::factory()->create())->get('/relevance');

        $response->assertOk()
            ->assertSee('SHOWN PROJECT')
            ->assertDontSee('HIDDEN PROJECT');
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose exec laravel.test php artisan test --filter=RelevancePageTest`
Expected: FAIL — view `content.pages.relevance` not found.

- [ ] **Step 3: Replace the card partial with the full card**

Overwrite `resources/views/_partials/relevance-card.blade.php`:

```blade
<div class="card mb-4 relevance-card" data-bid-id="{{ $bid->id }}">
    <div class="card-body">
        <h6 class="fw-bold mb-1">Title:</h6>
        <p class="mb-3">{{ optional($bid->proposal)->title ?? '(no project data)' }}</p>

        <h6 class="fw-bold mb-1">Project Description:</h6>
        <p class="text-muted mb-4">{{ optional($bid->proposal)->description }}</p>

        <div class="d-flex justify-content-end gap-2">
            <button type="button" class="btn btn-success relevance-btn" data-feedback="relevant">Relevant</button>
            <button type="button" class="btn btn-warning relevance-btn" data-feedback="irrelevant">Irrelevant</button>
            <button type="button" class="btn btn-danger relevance-btn" data-feedback="scam">Scam</button>
        </div>
    </div>
</div>
```

- [ ] **Step 4: Create the page view**

Create `resources/views/content/pages/relevance.blade.php`:

```blade
@extends('layouts.layoutMaster')

@section('title', 'Relevance')

@section('content')
    <h4 class="fw-bold py-3 mb-4">Relevance</h4>

    <div id="relevance-list">
        @foreach ($bids as $bid)
            @include('_partials.relevance-card', ['bid' => $bid])
        @endforeach
    </div>

    <div id="relevance-sentinel" class="py-3 text-center text-muted"
         data-next-page="2" data-has-more="{{ $bids->hasMorePages() ? '1' : '0' }}">
    </div>

    <div id="relevance-empty" class="py-5 text-center text-muted"
         style="{{ $bids->total() === 0 ? '' : 'display:none;' }}">
        All bids reviewed 🎉
    </div>
@endsection
```

- [ ] **Step 5: Add the menu item**

In `resources/menu/verticalMenu.json`, add after the Filters object (add a comma after the Filters `}`):

```json
    {
      "url": "/relevance",
      "name": "Relevance",
      "icon": "menu-icon tf-icons bx bx-check-shield",
      "slug": "relevance"
    }
```

- [ ] **Step 6: Run test to verify it passes**

Run: `docker compose exec laravel.test php artisan test --filter=RelevancePageTest`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add resources/views/_partials/relevance-card.blade.php resources/views/content/pages/relevance.blade.php resources/menu/verticalMenu.json tests/Feature/RelevancePageTest.php
git commit -m "feat: add relevance card, page view, and sidebar menu item"
```

---

### Task 4: Infinite scroll + feedback button JS

**Files:**
- Modify: `resources/views/content/pages/relevance.blade.php` (add `@section('page-script')` block)
- Verify: manual browser check (no unit test — client behavior)

**Interfaces:**
- Consumes: `#relevance-list`, `#relevance-sentinel` (`data-next-page`, `data-has-more`), `#relevance-empty`, `.relevance-card[data-bid-id]`, `.relevance-btn[data-feedback]` (Task 3); routes `relevance.load`, `relevance.feedback` (Task 2).

- [ ] **Step 1: Confirm the layout exposes a page-script section**

Run: `grep -n "page-script\|@yield\|@stack" resources/views/layouts/contentNavbarLayout.blade.php resources/views/layouts/sections/scripts.blade.php`
Expected: find the section name used for per-page scripts (commonly `@yield('page-script')`). If the name differs, use that name in Step 2 instead of `page-script`. If none exists, place the `<script>` inline at the end of `@section('content')` instead.

- [ ] **Step 2: Add the script block**

Append to `resources/views/content/pages/relevance.blade.php`:

```blade
@section('page-script')
<script>
document.addEventListener('DOMContentLoaded', function () {
    const list = document.getElementById('relevance-list');
    const sentinel = document.getElementById('relevance-sentinel');
    const empty = document.getElementById('relevance-empty');
    const token = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
    let loading = false;

    function hasMore() { return sentinel.dataset.hasMore === '1'; }

    function maybeShowEmpty() {
        if (!list.querySelector('.relevance-card') && !hasMore()) {
            empty.style.display = '';
            sentinel.style.display = 'none';
        }
    }

    function loadMore() {
        if (loading || !hasMore()) return;
        loading = true;
        const page = sentinel.dataset.nextPage;
        fetch(`/relevance/load?page=${page}`, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(r => r.json())
            .then(data => {
                list.insertAdjacentHTML('beforeend', data.html);
                sentinel.dataset.nextPage = String(parseInt(page, 10) + 1);
                sentinel.dataset.hasMore = data.hasMore ? '1' : '0';
                loading = false;
                if (!hasMore()) maybeShowEmpty();
            })
            .catch(() => { loading = false; });
    }

    const observer = new IntersectionObserver(entries => {
        if (entries[0].isIntersecting) loadMore();
    });
    observer.observe(sentinel);

    list.addEventListener('click', function (e) {
        const btn = e.target.closest('.relevance-btn');
        if (!btn) return;
        const card = btn.closest('.relevance-card');
        const bidId = card.dataset.bidId;
        const feedback = btn.dataset.feedback;

        card.querySelectorAll('.relevance-btn').forEach(b => b.disabled = true);

        fetch('/relevance/feedback', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': token,
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: JSON.stringify({ bid_id: bidId, feedback: feedback }),
        })
            .then(r => { if (!r.ok) throw new Error('failed'); return r.json(); })
            .then(() => {
                card.style.transition = 'opacity .2s';
                card.style.opacity = '0';
                setTimeout(() => { card.remove(); maybeShowEmpty(); if (hasMore()) loadMore(); }, 200);
            })
            .catch(() => {
                card.querySelectorAll('.relevance-btn').forEach(b => b.disabled = false);
            });
    });
});
</script>
@endsection
```

- [ ] **Step 3: Confirm CSRF meta tag exists in the layout**

Run: `grep -rn 'name="csrf-token"' resources/views/layouts/`
Expected: a `<meta name="csrf-token" content="{{ csrf_token() }}">` tag exists. If absent, add it to `resources/views/layouts/commonMaster.blade.php` inside `<head>`:

```blade
<meta name="csrf-token" content="{{ csrf_token() }}">
```

- [ ] **Step 4: Manual browser verification**

1. Ensure the column exists in the running DB: `docker compose exec laravel.test php artisan migrate`
2. Open `http://localhost:8000/relevance` (log in if needed).
3. Confirm: cards render with Title + Project Description + 3 buttons bottom-right.
4. Scroll down → more cards load (network tab shows `/relevance/load?page=N`).
5. Click a button on a card → card fades out and disappears; refresh → same bid does not reappear.
6. Verify in DB: `docker compose exec mysql mysql -uroot -proot laravel -e "SELECT id, admin_feedback FROM bids WHERE admin_feedback IS NOT NULL LIMIT 5;"`

- [ ] **Step 5: Commit**

```bash
git add resources/views/content/pages/relevance.blade.php resources/views/layouts/commonMaster.blade.php
git commit -m "feat: add infinite scroll and feedback button behavior to relevance tab"
```

---

## Notes for the running (non-test) database

The dev DB was imported from `db.sql` (no migrations run on it). After Task 1,
apply the new column to the running DB with:

```bash
docker compose exec laravel.test php artisan migrate
```

Tests use sqlite `:memory:` and run every migration fresh, so they are
unaffected by the dev DB state.
