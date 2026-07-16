# Review Tab (LLM Labeling) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add an isolated "Review" page where a human labels crawled projects Relevant / Not Relevant Skill / Scam, building a training dataset — without touching the existing Relevance feature.

**Architecture:** New `proposals.review_label` column stores the label. A new `ReviewController` serves a labeling page with Old/New sub-tabs (rolling 7-day window on `created_at`), an infinite-scroll queue of unlabeled proposals, and a POST endpoint that persists a label and drops the card. Views and JS are cloned from the Relevance page so behavior is proven; Relevance code is untouched.

**Tech Stack:** Laravel 10, Blade, Eloquent, PHPUnit 10 Feature tests (RefreshDatabase), Bootstrap 5 theme, data-driven sidebar (`resources/menu/verticalMenu.json`).

## Global Constraints

- **Do NOT modify** the Relevance page, its routes, its views, `BidController`, or the `bids.admin_feedback` column. Review is fully additive and revertible.
- Label values are exactly: `relevant`, `not_relevant_skill`, `scam`. No other values.
- Old/New split: **New = `created_at >= now()->subDays(7)`**, Old = older. Window constant `NEW_WINDOW_DAYS = 7`.
- Page size for infinite scroll: **20** (fetch 21 to compute `hasMore`).
- The label POST identifies the proposal by its **primary key** (`proposal_id` = `proposals.id`), NOT the Freelancer `project_id`.
- All `/review*` routes live inside the existing authenticated route group (they require login, like `/bids/data`).
- Phase 1 labels only stored proposals. Do NOT modify `ProposalController::getProposals` or add rejected-project capture.

---

### Task 1: Migration + Proposal `needsReview` scope

**Files:**
- Create: `database/migrations/2026_07_16_000000_add_review_label_to_proposals.php`
- Modify: `app/Models/Proposal.php` (add a query scope)
- Test: `tests/Feature/ReviewColumnTest.php`

**Interfaces:**
- Produces: `proposals.review_label` (nullable string column); `Proposal::needsReview()` scope returning proposals where `review_label IS NULL`.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/ReviewColumnTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Proposal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReviewColumnTest extends TestCase
{
    use RefreshDatabase;

    private function makeProposal(?string $label, int $projectId): Proposal
    {
        $proposal = new Proposal();
        $proposal->project_id = $projectId;
        $proposal->title = 'Test Project';
        $proposal->description = 'Test project description';
        $proposal->review_label = $label;
        $proposal->save();

        return $proposal;
    }

    public function test_needs_review_scope_returns_only_null_label(): void
    {
        $this->makeProposal(null, 101);
        $this->makeProposal('relevant', 102);
        $this->makeProposal('scam', 103);

        $this->assertSame(1, Proposal::needsReview()->count());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/sail test --filter=ReviewColumnTest`
Expected: FAIL — column `review_label` does not exist / `needsReview` undefined.

- [ ] **Step 3: Write the migration**

Create `database/migrations/2026_07_16_000000_add_review_label_to_proposals.php`:

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
            $table->string('review_label')->nullable()->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('proposals', function (Blueprint $table) {
            $table->dropColumn('review_label');
        });
    }
};
```

- [ ] **Step 4: Add the scope to the Proposal model**

In `app/Models/Proposal.php`, add this method inside the class (after the `bid()` relation):

```php
    public function scopeNeedsReview($query)
    {
        return $query->whereNull('review_label');
    }
```

- [ ] **Step 5: Run test to verify it passes**

Run: `./vendor/bin/sail test --filter=ReviewColumnTest`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add database/migrations/2026_07_16_000000_add_review_label_to_proposals.php app/Models/Proposal.php tests/Feature/ReviewColumnTest.php
git commit -m "feat: add review_label column and needsReview scope to proposals"
```

---

### Task 2: `ReviewController::storeFeedback` + route

**Files:**
- Create: `app/Http/Controllers/ReviewController.php`
- Modify: `routes/web.php` (add `/review/feedback` inside the auth group)
- Test: `tests/Feature/ReviewFeedbackTest.php`

**Interfaces:**
- Consumes: `Proposal::needsReview()`, `proposals.review_label` (Task 1).
- Produces: `POST /review/feedback` (name `review.feedback`) accepting `{ proposal_id, label }`, persisting `review_label`, returning `{ success: true }`. Class constant `ReviewController::NEW_WINDOW_DAYS = 7` and `PER_PAGE = 20` (used by Task 3).

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/ReviewFeedbackTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Proposal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReviewFeedbackTest extends TestCase
{
    use RefreshDatabase;

    private function makeProposal(): Proposal
    {
        $proposal = new Proposal();
        $proposal->project_id = 555;
        $proposal->title = 'Labelling target';
        $proposal->description = 'desc';
        $proposal->save();

        return $proposal;
    }

    public function test_requires_auth(): void
    {
        $this->postJson('/review/feedback', ['proposal_id' => 1, 'label' => 'relevant'])
            ->assertUnauthorized();
    }

    public function test_valid_label_persists(): void
    {
        $proposal = $this->makeProposal();

        $this->actingAs(User::factory()->create())
            ->postJson('/review/feedback', ['proposal_id' => $proposal->id, 'label' => 'not_relevant_skill'])
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->assertSame('not_relevant_skill', $proposal->fresh()->review_label);
    }

    public function test_invalid_label_rejected(): void
    {
        $proposal = $this->makeProposal();

        $this->actingAs(User::factory()->create())
            ->postJson('/review/feedback', ['proposal_id' => $proposal->id, 'label' => 'maybe'])
            ->assertStatus(422);

        $this->assertNull($proposal->fresh()->review_label);
    }

    public function test_unknown_proposal_rejected(): void
    {
        $this->actingAs(User::factory()->create())
            ->postJson('/review/feedback', ['proposal_id' => 999999, 'label' => 'relevant'])
            ->assertStatus(422);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/sail test --filter=ReviewFeedbackTest`
Expected: FAIL — route `/review/feedback` not defined (404/500).

- [ ] **Step 3: Create the controller with `storeFeedback`**

Create `app/Http/Controllers/ReviewController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Models\Proposal;
use Illuminate\Http\Request;

class ReviewController extends Controller
{
    private const NEW_WINDOW_DAYS = 7;
    private const PER_PAGE = 20;

    public function storeFeedback(Request $request)
    {
        $validated = $request->validate([
            'proposal_id' => 'required|exists:proposals,id',
            'label' => 'required|in:relevant,not_relevant_skill,scam',
        ]);

        $proposal = Proposal::find($validated['proposal_id']);
        $proposal->review_label = $validated['label'];
        $proposal->save();

        return response()->json(['success' => true]);
    }
}
```

- [ ] **Step 4: Register the route**

In `routes/web.php`, inside the same authenticated group that holds `/relevance/feedback`, add:

```php
    Route::post('/review/feedback', [\App\Http\Controllers\ReviewController::class, 'storeFeedback'])->name('review.feedback');
```

(If the file already imports controllers with `use` at the top, add `use App\Http\Controllers\ReviewController;` and use the short name instead.)

- [ ] **Step 5: Run test to verify it passes**

Run: `./vendor/bin/sail test --filter=ReviewFeedbackTest`
Expected: PASS (all four tests).

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/ReviewController.php routes/web.php tests/Feature/ReviewFeedbackTest.php
git commit -m "feat: add review feedback endpoint to persist project labels"
```

---

### Task 3: `ReviewController::load` — Old/New window + cursor pagination

**Files:**
- Modify: `app/Http/Controllers/ReviewController.php` (add `tabQuery` + `load`)
- Create: `resources/views/_partials/review-card.blade.php` (needed to render `load` output)
- Modify: `routes/web.php` (add `/review/load`)
- Test: `tests/Feature/ReviewLoadTest.php`

**Interfaces:**
- Consumes: `Proposal::needsReview()`, `NEW_WINDOW_DAYS`, `PER_PAGE` (Task 2).
- Produces: `GET /review/load?tab=old|new&after_id=<id>` (name `review.load`) → JSON `{ html, hasMore }`, newest-id-first, 20 per page. Private `tabQuery(string $tab)` returning a `Proposal::needsReview()` query filtered to the tab's window. `_partials.review-card` partial rendering one proposal (expects `$proposal`).

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/ReviewLoadTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Proposal;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReviewLoadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-07-16 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function makeProposal(int $projectId, string $createdAt, ?string $label = null): Proposal
    {
        $proposal = new Proposal();
        $proposal->project_id = $projectId;
        $proposal->title = "Project {$projectId}";
        $proposal->description = 'desc';
        $proposal->review_label = $label;
        $proposal->created_at = $createdAt;
        $proposal->updated_at = $createdAt;
        $proposal->save();

        return $proposal;
    }

    public function test_new_tab_returns_recent_unlabeled_only(): void
    {
        $this->makeProposal(1, '2026-07-15 09:00:00');            // within 7 days → new
        $this->makeProposal(2, '2026-07-01 09:00:00');            // older → old
        $this->makeProposal(3, '2026-07-15 09:00:00', 'scam');   // labeled → excluded

        $res = $this->actingAs(User::factory()->create())
            ->getJson('/review/load?tab=new')->assertOk();

        $this->assertStringContainsString('Project 1', $res->json('html'));
        $this->assertStringNotContainsString('Project 2', $res->json('html'));
        $this->assertStringNotContainsString('Project 3', $res->json('html'));
        $this->assertFalse($res->json('hasMore'));
    }

    public function test_old_tab_returns_older_unlabeled_only(): void
    {
        $this->makeProposal(1, '2026-07-15 09:00:00');   // new
        $this->makeProposal(2, '2026-07-01 09:00:00');   // old

        $res = $this->actingAs(User::factory()->create())
            ->getJson('/review/load?tab=old')->assertOk();

        $this->assertStringContainsString('Project 2', $res->json('html'));
        $this->assertStringNotContainsString('Project 1', $res->json('html'));
    }

    public function test_cursor_pagination_hasmore_and_after_id(): void
    {
        // 21 recent unlabeled proposals → first page hasMore=true, 20 rows
        for ($i = 1; $i <= 21; $i++) {
            $this->makeProposal(1000 + $i, '2026-07-15 09:00:00');
        }
        $user = User::factory()->create();

        $first = $this->actingAs($user)->getJson('/review/load?tab=new')->assertOk();
        $this->assertTrue($first->json('hasMore'));
        $this->assertSame(20, substr_count($first->json('html'), 'review-card'));

        // lowest id on the page is the 1st created proposal (id 1). after_id=2 → only id 1 remains.
        $second = $this->actingAs($user)->getJson('/review/load?tab=new&after_id=2')->assertOk();
        $this->assertFalse($second->json('hasMore'));
        $this->assertSame(1, substr_count($second->json('html'), 'review-card'));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/sail test --filter=ReviewLoadTest`
Expected: FAIL — route `/review/load` not defined.

- [ ] **Step 3: Create the card partial**

Create `resources/views/_partials/review-card.blade.php`:

```blade
<div class="card mb-4 review-card" data-proposal-id="{{ $proposal->id }}">
    <div class="card-body">
        <h6 class="fw-bold mb-1">Title:</h6>
        <p class="mb-3">{{ $proposal->title ?? '(no title)' }}</p>

        <h6 class="fw-bold mb-1">Project Description:</h6>
        <p class="text-muted mb-3">{{ $proposal->description }}</p>

        <div class="mb-3">
            @foreach (($proposal->skills ?? []) as $skill)
                <span class="badge bg-label-primary me-1">{{ $skill }}</span>
            @endforeach
        </div>

        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
            <div class="text-muted small">
                {{ $proposal->min_budget }} {{ $proposal->currency_name }} · {{ $proposal->type }} · {{ $proposal->country }}
            </div>
            <div class="d-flex gap-2">
                <button type="button" class="btn btn-success review-btn" data-label="relevant">Relevant</button>
                <button type="button" class="btn btn-warning review-btn" data-label="not_relevant_skill">Not Relevant Skill</button>
                <button type="button" class="btn btn-danger review-btn" data-label="scam">Scam</button>
            </div>
        </div>
    </div>
</div>
```

- [ ] **Step 4: Add `tabQuery` + `load` to the controller**

In `app/Http/Controllers/ReviewController.php`, add these two methods inside the class (above `storeFeedback` is fine):

```php
    private function tabQuery(string $tab)
    {
        $cutoff = now()->subDays(self::NEW_WINDOW_DAYS);
        $query = Proposal::needsReview();

        return $tab === 'old'
            ? $query->where('created_at', '<', $cutoff)
            : $query->where('created_at', '>=', $cutoff);
    }

    public function load(Request $request)
    {
        $tab = $request->query('tab') === 'old' ? 'old' : 'new';

        $query = $this->tabQuery($tab)->orderByDesc('id');
        if ($request->filled('after_id')) {
            $query->where('id', '<', (int) $request->query('after_id'));
        }

        $proposals = $query->limit(self::PER_PAGE + 1)->get();
        $hasMore = $proposals->count() > self::PER_PAGE;
        $proposals = $proposals->take(self::PER_PAGE);

        $html = '';
        foreach ($proposals as $proposal) {
            $html .= view('_partials.review-card', ['proposal' => $proposal])->render();
        }

        return response()->json(['html' => $html, 'hasMore' => $hasMore]);
    }
```

- [ ] **Step 5: Register the route**

In `routes/web.php`, next to `review.feedback`, add:

```php
    Route::get('/review/load', [\App\Http\Controllers\ReviewController::class, 'load'])->name('review.load');
```

- [ ] **Step 6: Run test to verify it passes**

Run: `./vendor/bin/sail test --filter=ReviewLoadTest`
Expected: PASS (all three tests).

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/ReviewController.php resources/views/_partials/review-card.blade.php routes/web.php tests/Feature/ReviewLoadTest.php
git commit -m "feat: add review load endpoint with old/new window and cursor pagination"
```

---

### Task 4: Review page (`index` + view), sidebar entry

**Files:**
- Modify: `app/Http/Controllers/ReviewController.php` (add `index`)
- Create: `resources/views/content/pages/review.blade.php`
- Modify: `routes/web.php` (add `/review`)
- Modify: `resources/menu/verticalMenu.json` (add Review nav item)
- Test: `tests/Feature/ReviewPageTest.php`

**Interfaces:**
- Consumes: `tabQuery`, `PER_PAGE` (Task 3); `_partials.review-card` (Task 3); `GET /review/load`, `POST /review/feedback` (Tasks 2–3, called from page JS).
- Produces: `GET /review` (name `review`) → renders `content.pages.review` with `$proposals`, `$hasMore`, `$newCount`, `$oldCount`.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/ReviewPageTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Proposal;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReviewPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-07-16 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function makeProposal(int $projectId, string $createdAt): void
    {
        $proposal = new Proposal();
        $proposal->project_id = $projectId;
        $proposal->title = "Project {$projectId}";
        $proposal->description = 'desc';
        $proposal->created_at = $createdAt;
        $proposal->updated_at = $createdAt;
        $proposal->save();
    }

    public function test_requires_auth(): void
    {
        $this->get('/review')->assertRedirect();
    }

    public function test_page_renders_tabs_and_new_projects_by_default(): void
    {
        $this->makeProposal(1, '2026-07-15 09:00:00'); // new
        $this->makeProposal(2, '2026-07-01 09:00:00'); // old

        $res = $this->actingAs(User::factory()->create())->get('/review')->assertOk();

        $res->assertSee('New Projects');
        $res->assertSee('Old Projects');
        // default tab is New → shows Project 1, not Project 2
        $res->assertSee('Project 1');
        $res->assertDontSee('Project 2');
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/sail test --filter=ReviewPageTest`
Expected: FAIL — route `/review` not defined.

- [ ] **Step 3: Add `index` to the controller**

In `app/Http/Controllers/ReviewController.php`, add:

```php
    public function index()
    {
        $proposals = $this->tabQuery('new')
            ->orderByDesc('id')
            ->limit(self::PER_PAGE + 1)
            ->get();
        $hasMore = $proposals->count() > self::PER_PAGE;
        $proposals = $proposals->take(self::PER_PAGE);

        return view('content.pages.review', [
            'proposals' => $proposals,
            'hasMore' => $hasMore,
            'newCount' => $this->tabQuery('new')->count(),
            'oldCount' => $this->tabQuery('old')->count(),
        ]);
    }
```

- [ ] **Step 4: Create the page view**

Create `resources/views/content/pages/review.blade.php`:

```blade
@extends('layouts.layoutMaster')

@section('title', 'Review')

@section('content')
    <h4 class="page-title">Review</h4>

    <ul class="nav nav-tabs mb-4" id="review-tabs">
        <li class="nav-item">
            <button type="button" class="nav-link active" data-tab="new">
                New Projects <span class="badge bg-label-primary ms-1" id="count-new">{{ $newCount }}</span>
            </button>
        </li>
        <li class="nav-item">
            <button type="button" class="nav-link" data-tab="old">
                Old Projects <span class="badge bg-label-secondary ms-1" id="count-old">{{ $oldCount }}</span>
            </button>
        </li>
    </ul>

    <div id="review-list">
        @foreach ($proposals as $proposal)
            @include('_partials.review-card', ['proposal' => $proposal])
        @endforeach
    </div>

    <div id="review-sentinel" class="py-3 text-center text-muted" data-has-more="{{ $hasMore ? '1' : '0' }}"></div>

    <div id="review-empty" class="py-5 text-center text-muted"
         style="{{ $proposals->count() === 0 ? '' : 'display:none;' }}">
        All projects reviewed 🎉
    </div>
@endsection

@section('page-script')
<script>
document.addEventListener('DOMContentLoaded', function () {
    const list = document.getElementById('review-list');
    const sentinel = document.getElementById('review-sentinel');
    const empty = document.getElementById('review-empty');
    const token = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
    let currentTab = 'new';
    let loading = false;

    function hasMore() { return sentinel.dataset.hasMore === '1'; }

    function activeCountEl() {
        return document.getElementById(currentTab === 'old' ? 'count-old' : 'count-new');
    }

    function maybeShowEmpty() {
        if (!list.querySelector('.review-card') && !hasMore()) {
            empty.style.display = '';
            sentinel.style.display = 'none';
        }
    }

    function lastProposalId() {
        const cards = list.querySelectorAll('.review-card');
        return cards.length ? cards[cards.length - 1].dataset.proposalId : '';
    }

    function loadMore() {
        if (loading || !hasMore()) return;
        loading = true;
        const afterId = lastProposalId();
        fetch(`/review/load?tab=${currentTab}&after_id=${afterId}`, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(r => r.json())
            .then(data => {
                list.insertAdjacentHTML('beforeend', data.html);
                sentinel.dataset.hasMore = data.hasMore ? '1' : '0';
                loading = false;
                if (!hasMore()) maybeShowEmpty();
            })
            .catch(() => { loading = false; });
    }

    function switchTab(tab) {
        if (tab === currentTab) return;
        currentTab = tab;
        document.querySelectorAll('#review-tabs .nav-link').forEach(b =>
            b.classList.toggle('active', b.dataset.tab === tab));
        list.innerHTML = '';
        empty.style.display = 'none';
        sentinel.style.display = '';
        sentinel.dataset.hasMore = '1';
        loadMore();
    }

    document.getElementById('review-tabs').addEventListener('click', function (e) {
        const btn = e.target.closest('.nav-link');
        if (btn) switchTab(btn.dataset.tab);
    });

    const observer = new IntersectionObserver(entries => {
        if (entries[0].isIntersecting) loadMore();
    });
    observer.observe(sentinel);

    list.addEventListener('click', function (e) {
        const btn = e.target.closest('.review-btn');
        if (!btn) return;
        const card = btn.closest('.review-card');
        const proposalId = card.dataset.proposalId;
        const label = btn.dataset.label;

        card.querySelectorAll('.review-btn').forEach(b => b.disabled = true);

        fetch('/review/feedback', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': token,
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: JSON.stringify({ proposal_id: proposalId, label: label }),
        })
            .then(r => { if (!r.ok) throw new Error('failed'); return r.json(); })
            .then(() => {
                const badge = activeCountEl();
                if (badge) badge.textContent = Math.max(0, parseInt(badge.textContent || '0', 10) - 1);
                card.style.transition = 'opacity .2s';
                card.style.opacity = '0';
                setTimeout(() => { card.remove(); maybeShowEmpty(); if (hasMore()) loadMore(); }, 200);
            })
            .catch(() => {
                card.querySelectorAll('.review-btn').forEach(b => b.disabled = false);
            });
    });
});
</script>
@endsection
```

- [ ] **Step 5: Register the route**

In `routes/web.php`, next to the other review routes, add:

```php
    Route::get('/review', [\App\Http\Controllers\ReviewController::class, 'index'])->name('review');
```

- [ ] **Step 6: Add the sidebar entry**

In `resources/menu/verticalMenu.json`, add this object to the `menu` array immediately after the Relevance entry:

```json
    {
      "url": "/review",
      "name": "Review",
      "icon": "menu-icon tf-icons bx bx-list-check",
      "slug": "review"
    },
```

- [ ] **Step 7: Run test to verify it passes**

Run: `./vendor/bin/sail test --filter=ReviewPageTest`
Expected: PASS.

- [ ] **Step 8: Run the full review test suite + isolation check**

Run: `./vendor/bin/sail test --filter=Review`
Expected: PASS — ReviewColumnTest, ReviewFeedbackTest, ReviewLoadTest, ReviewPageTest.

Run: `./vendor/bin/sail test --filter=Relevance`
Expected: PASS — Relevance behavior unchanged (isolation confirmed).

- [ ] **Step 9: Commit**

```bash
git add app/Http/Controllers/ReviewController.php resources/views/content/pages/review.blade.php routes/web.php resources/menu/verticalMenu.json tests/Feature/ReviewPageTest.php
git commit -m "feat: add Review page with old/new tabs and sidebar entry"
```

---

## Manual Verification (after all tasks)

1. `./vendor/bin/sail artisan migrate` (if not auto-run) → `proposals.review_label` exists.
2. Visit `/review` → **Review** appears in sidebar; New Projects tab active with count badge.
3. Click a label on a card → card fades out, count decrements, next card appears.
4. Scroll → more cards load until "All projects reviewed 🎉".
5. Switch to **Old Projects** → older unlabeled projects load, same loop.
6. Confirm `/relevance` still works exactly as before (unchanged).
7. Revert check (optional): `migrate:rollback` drops the column cleanly.

## Revert Plan

1. `php artisan migrate:rollback` (drops `proposals.review_label`).
2. Delete `ReviewController`, `review.blade.php`, `review-card.blade.php`, the four `/review*` routes, the ReviewColumn/Feedback/Load/Page tests, and the `verticalMenu.json` Review entry.
Relevance and all bid flows remain untouched.

## Self-Review Notes

- **Spec coverage:** Old/New sub-tabs (Task 4 + Task 3 window) ✓; three buttons Relevant/Not Relevant Skill/Scam (Task 3 card) ✓; press-and-disappear loop (Task 4 JS) ✓; label storage isolated on proposals (Task 1) ✓; Relevance untouched (isolation test, Task 4 Step 8) ✓; filtered-out capture explicitly deferred (Global Constraints) ✓.
- **Type consistency:** `proposal_id`/`label` field names, `review_label` values, `NEW_WINDOW_DAYS`, `PER_PAGE`, `tabQuery`, `needsReview`, `.review-card`/`data-proposal-id`, and route names (`review`, `review.load`, `review.feedback`) are consistent across all tasks.
- **Placeholder scan:** none — all steps contain concrete code/commands.
