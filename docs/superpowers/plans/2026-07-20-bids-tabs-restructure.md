# Bids Tabs Restructure Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Bids page tabs become exactly: Completed (default, includes pending) · Not Qualified · Skill Not Matched · Failed.

**Architecture:** `BidController@data` tab whitelist/match rewritten (skill split on `error_message LIKE '%skill%'`); tab nav, JS default tab, and one CSS rule updated in `home.blade.php`. Not-qualified branch untouched.

**Tech Stack:** Laravel 10, existing AJAX tab machinery.

**Spec:** `docs/superpowers/specs/2026-07-20-bids-tabs-restructure-design.md`

## Global Constraints

- Tab params: `completed` (default + fallback for unknown), `not-qualified` (existing branch untouched), `skill-not-matched`, `failed`.
- Completed = `bid_status IN ('pending','completed')`. Skill Not Matched = `bid_status IN ('failed','expired') AND error_message LIKE '%skill%'`. Failed = `bid_status IN ('failed','expired') AND (error_message NOT LIKE '%skill%' OR error_message IS NULL)`.
- `cards` / `statusCounts` computations unchanged; empty-state colspan rule unchanged (`$isCompleted ? 9 : 7`).
- Tab button order: Completed, Not Qualified, Skill Not Matched, Failed. No `data-tab="placed"` anywhere.
- CSS: new amber rule for skill-not-matched (`#ffab00`, `rgba(255,171,0,.12)`); existing completed/failed/not-qualified rules stay; delete nothing else.
- `NotQualifiedTabTest` must stay green (7 tests). Known pre-existing failure: ExampleTest.
- Local commits only; NEVER push. Branch: `filters-and-ui-modifications`.

---

### Task 1: Restructured tabs (controller + view + tests)

**Files:**
- Modify: `app/Http/Controllers/BidController.php` (`data` method, tab whitelist/match at lines ~107-120)
- Modify: `resources/views/content/pages/home.blade.php` (tab nav ~line 182, CSS block, JS `currentTab` line ~233)
- Test: `tests/Feature/BidsDataTest.php` (update + extend)

**Interfaces:**
- Consumes: existing `filteredBidQuery`, `$failed = ['failed','expired']`, not-qualified early branch (before the whitelist — leave untouched).
- Produces: `/bids/data` behavior per Global Constraints.

- [ ] **Step 1: Update the test file**

Replace the whole of `tests/Feature/BidsDataTest.php` with:

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

    private function seedBids(): void
    {
        $p1 = Proposal::factory()->create(['type' => 'fixed', 'title' => 'Laravel API build', 'project_id' => 1234, 'country' => 'India']);
        $p2 = Proposal::factory()->create(['type' => 'hourly', 'title' => 'React app', 'project_id' => 5678, 'country' => 'USA']);

        Bid::factory()->create(['proposal_id' => $p1->id, 'bid_status' => 'pending',   'price' => 100, 'created_at' => '2026-07-10 09:00:00']);
        Bid::factory()->create(['proposal_id' => $p1->id, 'bid_status' => 'completed', 'price' => 500, 'created_at' => '2026-07-11 09:00:00']);
        Bid::factory()->create(['proposal_id' => $p2->id, 'bid_status' => 'failed',    'price' => 200, 'created_at' => '2026-07-12 09:00:00', 'error_message' => 'Skill not matched for this project']);
        Bid::factory()->create(['proposal_id' => $p2->id, 'bid_status' => 'expired',   'price' => 300, 'created_at' => '2026-07-13 09:00:00', 'error_message' => null]);
    }

    public function test_requires_auth(): void
    {
        $this->getJson('/bids/data')->assertUnauthorized();
    }

    public function test_cards_and_default_completed_tab(): void
    {
        $this->seedBids();

        $res = $this->actingAs(User::factory()->create())->getJson('/bids/data')->assertOk();

        $this->assertEquals(4, $res->json('cards.total'));
        $this->assertEquals(2, $res->json('cards.placed'));
        $this->assertEquals(2, $res->json('cards.failed'));
        // default tab = completed → pending + completed rows, no failures
        $this->assertStringContainsString('1234', $res->json('rowsHtml'));
        $this->assertStringContainsString('pending', $res->json('rowsHtml'));
        $this->assertStringNotContainsString('expired', $res->json('rowsHtml'));
    }

    public function test_failed_tab_excludes_skill_errors(): void
    {
        $this->seedBids();

        $res = $this->actingAs(User::factory()->create())->getJson('/bids/data?tab=failed')->assertOk();

        // only the expired bid (price 300, null error); the skill-error bid (price 200) is excluded
        $this->assertStringContainsString('expired', $res->json('rowsHtml'));
        $this->assertStringContainsString('300', $res->json('rowsHtml'));
        $this->assertStringNotContainsString('200', $res->json('rowsHtml'));
        $this->assertStringNotContainsString('pending', $res->json('rowsHtml'));
    }

    public function test_skill_not_matched_tab(): void
    {
        $this->seedBids();

        $res = $this->actingAs(User::factory()->create())->getJson('/bids/data?tab=skill-not-matched')->assertOk();

        // only the failed bid with the skill error (price 200)
        $this->assertStringContainsString('200', $res->json('rowsHtml'));
        $this->assertStringNotContainsString('300', $res->json('rowsHtml'));
        $this->assertStringNotContainsString('pending', $res->json('rowsHtml'));
    }

    public function test_unknown_tab_falls_back_to_completed(): void
    {
        $this->seedBids();

        $res = $this->actingAs(User::factory()->create())->getJson('/bids/data?tab=placed')->assertOk();

        $this->assertStringContainsString('pending', $res->json('rowsHtml'));
        $this->assertStringContainsString('1234', $res->json('rowsHtml'));
        $this->assertStringNotContainsString('expired', $res->json('rowsHtml'));
    }

    public function test_bids_page_tab_buttons(): void
    {
        $res = $this->actingAs(User::factory()->create())->get('/bids')->assertOk();
        $res->assertSeeInOrder([
            'data-tab="completed"',
            'data-tab="not-qualified"',
            'data-tab="skill-not-matched"',
            'data-tab="failed"',
        ], false);
        $res->assertDontSee('data-tab="placed"', false);
    }

    public function test_type_filter(): void
    {
        $this->seedBids();

        $res = $this->actingAs(User::factory()->create())->getJson('/bids/data?type=hourly')->assertOk();

        $this->assertEquals(2, $res->json('cards.total'));   // both p2 bids
        $this->assertEquals(0, $res->json('cards.placed'));
        $this->assertEquals(2, $res->json('cards.failed'));
    }

    public function test_min_price_filter(): void
    {
        $this->seedBids();

        $res = $this->actingAs(User::factory()->create())->getJson('/bids/data?min=250')->assertOk();

        // prices >= 250 : completed(500) + expired(300)
        $this->assertEquals(2, $res->json('cards.total'));
        $this->assertEquals(1, $res->json('cards.placed'));
        $this->assertEquals(1, $res->json('cards.failed'));
    }

    public function test_search_by_title_and_project_id(): void
    {
        $this->seedBids();
        $user = User::factory()->create();

        $byTitle = $this->actingAs($user)->getJson('/bids/data?q=Laravel')->assertOk();
        $this->assertEquals(2, $byTitle->json('cards.total')); // both p1 bids

        $byId = $this->actingAs($user)->getJson('/bids/data?q=5678')->assertOk();
        $this->assertEquals(2, $byId->json('cards.total')); // both p2 bids
    }
}
```

Note: if `Bid::factory()` lacks `error_message` in its definition, passing it as an attribute still works as long as `error_message` is fillable or the factory uses `state`; if a mass-assignment error occurs, set it via `Bid::factory()->create([...])->forceFill(['error_message' => ...])->save()` — check `Bid::$fillable` first (`app/Models/Bid.php`); most likely it is fillable since BidNowJob writes it.

- [ ] **Step 2: Run tests to verify new ones fail**

Run: `php artisan test --filter=BidsDataTest`
Expected: FAIL — `test_skill_not_matched_tab` (unknown tab → placed default returns pending rows), `test_failed_tab_excludes_skill_errors` (skill bid still included, '200' present), `test_bids_page_tab_buttons` (placed button still present). `test_cards_and_default_completed_tab` and `test_unknown_tab_falls_back_to_completed` may already pass (old default placed = pending+completed too).

- [ ] **Step 3: Controller — new whitelist and per-tab queries**

In `app/Http/Controllers/BidController.php` `data()`, replace this block (currently after the not-qualified branch):

```php
    $tab = in_array($request->query('tab'), ['failed', 'completed'], true)
      ? $request->query('tab')
      : 'placed';
    $statuses = match ($tab) {
      'failed' => $failed,
      'completed' => ['completed'],
      default => $placed,
    };
    $isCompleted = $tab === 'completed';

    $bids = (clone $base)
      ->whereIn('bids.bid_status', $statuses)
      ->with('proposal')
      ->latest('bids.created_at')
      ->paginate(100)
      ->withQueryString();
```

with:

```php
    $tab = in_array($request->query('tab'), ['failed', 'skill-not-matched'], true)
      ? $request->query('tab')
      : 'completed';
    $isCompleted = $tab === 'completed';

    $bids = (clone $base)
      ->when($tab === 'completed', fn ($q) => $q->whereIn('bids.bid_status', $placed))
      ->when($tab === 'skill-not-matched', fn ($q) => $q
        ->whereIn('bids.bid_status', $failed)
        ->where('bids.error_message', 'like', '%skill%'))
      ->when($tab === 'failed', fn ($q) => $q
        ->whereIn('bids.bid_status', $failed)
        ->where(function ($sub) {
          $sub->where('bids.error_message', 'not like', '%skill%')
            ->orWhereNull('bids.error_message');
        }))
      ->with('proposal')
      ->latest('bids.created_at')
      ->paginate(100)
      ->withQueryString();
```

(`$placed = ['pending', 'completed']` already defined at the top of `data()` — reused; the not-qualified early branch above this code stays untouched.)

- [ ] **Step 4: View — tab nav, CSS, JS default**

In `resources/views/content/pages/home.blade.php`:

Replace the tab nav `<ul>` contents (currently Placed/Completed/Failed/Not Qualified) with:

```html
                <li class="nav-item"><button class="nav-link active" data-tab="completed" type="button">Completed</button></li>
                <li class="nav-item"><button class="nav-link" data-tab="not-qualified" type="button">Not Qualified</button></li>
                <li class="nav-item"><button class="nav-link" data-tab="skill-not-matched" type="button">Skill Not Matched</button></li>
                <li class="nav-item"><button class="nav-link" data-tab="failed" type="button">Failed</button></li>
```

In the `<style>` block, after the `[data-tab="not-qualified"].active` rule, add:

```css
        #bids-tabs .nav-link[data-tab="skill-not-matched"].active {
            color: #ffab00;
            background-color: rgba(255, 171, 0, .12);
            border-bottom-color: #ffab00;
        }
```

Change the JS initial tab (line ~233):

```javascript
            let currentTab = 'completed';
```

- [ ] **Step 5: Run tests**

Run: `php artisan test --filter=BidsDataTest`
Expected: PASS (9 tests)

Run: `php artisan test --filter=NotQualifiedTabTest`
Expected: PASS (7 tests)

Run: `php artisan test`
Expected: all green except pre-existing ExampleTest failure.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/BidController.php resources/views/content/pages/home.blade.php tests/Feature/BidsDataTest.php
git commit -m "feat: restructure bids tabs to completed/not-qualified/skill-not-matched/failed"
```
