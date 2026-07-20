# Not Qualified Bids Tab Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Fourth "Not Qualified" tab on the Bids page serving `qualified = false` proposals via the existing `/bids/data` AJAX endpoint; standalone `/not-qualified` page removed.

**Architecture:** `BidController@data` gets an early branch for `tab=not-qualified` returning proposal rows (new partial) in the same `{cards, statusCounts, rowsHtml, paginationHtml}` JSON shape. Front end adds the tab button, a second thead row swapped by JS, and hides bid-only filters on that tab. Old page/controller/route/menu entry deleted.

**Tech Stack:** Laravel 10, Blade partial rendering to JSON (existing pattern), vanilla JS on home.blade.php.

**Spec:** `docs/superpowers/specs/2026-07-20-not-qualified-tab-design.md`

## Global Constraints

- JSON contract of `/bids/data` unchanged for existing tabs; not-qualified branch returns the SAME keys (`cards`, `statusCounts`, `rowsHtml`, `paginationHtml`) — front-end JS reads `data.cards.total` unguarded, so `cards` must stay present.
- Search uses the existing `q` request param (front end's `buildParams()` sends `q`), filtering proposal `title` or `project_id`.
- Row content matches the old page exactly: project_id, `Str::limit(title, 40)`, bold `qualify_reason`, `qualify_summary` or italic "No summary available", `created_at->diffForHumans(null, true)`, Freelancer link `https://www.freelancer.com/projects/{project_id}`.
- Pagination 50/page for the tab; view `vendor.pagination.bootstrap-5` (existing).
- Deletions: route `GET /not-qualified`, `NotQualifiedController`, `not-qualified.blade.php`, menu entry, `NotQualifiedPageTest.php`.
- Do not touch: qualification logic, Proposal model, mobile API.
- Known pre-existing failure: ExampleTest. Local commits only; NEVER push. Branch: `filters-and-ui-modifications`.

---

### Task 1: Backend — `tab=not-qualified` branch + row partial

**Files:**
- Modify: `app/Http/Controllers/BidController.php` (`data` method, after `$statusCounts` at line ~76)
- Create: `resources/views/_partials/not-qualified-row.blade.php`
- Test: `tests/Feature/NotQualifiedTabTest.php` (new)

**Interfaces:**
- Consumes: `Proposal::notQualified()` scope (`app/Models/Proposal.php`, filters `qualified = false`), existing `$cards`/`$statusCounts` computed earlier in `data()`.
- Produces: `GET /bids/data?tab=not-qualified[&q=...]` → `{cards, statusCounts, rowsHtml, paginationHtml}` where rowsHtml is proposal rows. Task 2's JS relies on colspan-6 empty state and these keys.

- [ ] **Step 1: Write the failing test**

`tests/Feature/NotQualifiedTabTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Proposal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NotQualifiedTabTest extends TestCase
{
    use RefreshDatabase;

    private function user(): User
    {
        return User::factory()->create();
    }

    public function test_tab_returns_only_not_qualified_proposals(): void
    {
        Proposal::factory()->create([
            'project_id' => 111,
            'title' => 'Bad crypto project',
            'qualified' => false,
            'qualify_reason' => 'Matches crypto criteria',
            'qualify_summary' => 'A crypto bot build request',
        ]);
        Proposal::factory()->create(['project_id' => 222, 'title' => 'Good qualified project', 'qualified' => true]);
        Proposal::factory()->create(['project_id' => 333, 'title' => 'Unjudged project', 'qualified' => null]);

        $res = $this->actingAs($this->user())
            ->getJson('/bids/data?tab=not-qualified')
            ->assertOk()
            ->json();

        $this->assertArrayHasKey('cards', $res);
        $this->assertStringContainsString('Bad crypto project', $res['rowsHtml']);
        $this->assertStringContainsString('Matches crypto criteria', $res['rowsHtml']);
        $this->assertStringContainsString('A crypto bot build request', $res['rowsHtml']);
        $this->assertStringContainsString('freelancer.com/projects/111', $res['rowsHtml']);
        $this->assertStringNotContainsString('Good qualified project', $res['rowsHtml']);
        $this->assertStringNotContainsString('Unjudged project', $res['rowsHtml']);
    }

    public function test_search_filters_by_title(): void
    {
        Proposal::factory()->create(['project_id' => 1, 'title' => 'Crypto bot', 'qualified' => false, 'qualify_reason' => 'r']);
        Proposal::factory()->create(['project_id' => 2, 'title' => 'Laravel site', 'qualified' => false, 'qualify_reason' => 'r']);

        $res = $this->actingAs($this->user())
            ->getJson('/bids/data?tab=not-qualified&q=Crypto')
            ->assertOk()
            ->json();

        $this->assertStringContainsString('Crypto bot', $res['rowsHtml']);
        $this->assertStringNotContainsString('Laravel site', $res['rowsHtml']);
    }

    public function test_missing_summary_shows_placeholder(): void
    {
        Proposal::factory()->create(['project_id' => 5, 'title' => 'No summary one', 'qualified' => false, 'qualify_reason' => 'r', 'qualify_summary' => null]);

        $res = $this->actingAs($this->user())
            ->getJson('/bids/data?tab=not-qualified')
            ->assertOk()
            ->json();

        $this->assertStringContainsString('No summary available', $res['rowsHtml']);
    }

    public function test_empty_state_row(): void
    {
        $res = $this->actingAs($this->user())
            ->getJson('/bids/data?tab=not-qualified')
            ->assertOk()
            ->json();

        $this->assertStringContainsString('No not-qualified proposals yet.', $res['rowsHtml']);
        $this->assertStringContainsString('colspan="6"', $res['rowsHtml']);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=NotQualifiedTabTest`
Expected: FAIL — `tab=not-qualified` falls through to the `placed` branch, rowsHtml contains bid empty-state text ("No bids match these filters."), assertions on proposal content fail.

- [ ] **Step 3: Create the row partial**

`resources/views/_partials/not-qualified-row.blade.php`:

```blade
<tr>
    <td>{{ $proposal->project_id }}</td>
    <td>{{ \Illuminate\Support\Str::limit($proposal->title, 40) }}</td>
    <td><span class="fw-bold">{{ $proposal->qualify_reason }}</span></td>
    <td>
        @if (trim((string) $proposal->qualify_summary) !== '')
            <span class="fw-light">{{ $proposal->qualify_summary }}</span>
        @else
            <span class="text-muted fst-italic">No summary available</span>
        @endif
    </td>
    <td>{{ $proposal->created_at->diffForHumans(null, true) }}</td>
    <td>
        <a href="https://www.freelancer.com/projects/{{ $proposal->project_id }}" target="_blank" rel="noopener"
           class="btn btn-sm btn-label-primary">
            <i class="fa fa-external-link me-1"></i> View
        </a>
    </td>
</tr>
```

- [ ] **Step 4: Add controller branch**

In `app/Http/Controllers/BidController.php`, inside `data()`, directly after the `$statusCounts = ...->pluck('c', 's');` statement (line ~76) and before the `$tab = in_array(...)` line, insert:

```php
    if ($request->query('tab') === 'not-qualified') {
      $proposals = \App\Models\Proposal::notQualified()
        ->when($request->filled('q'), function ($query) use ($request) {
          $q = $request->query('q');
          $query->where(function ($sub) use ($q) {
            $sub->where('title', 'like', "%{$q}%")
              ->orWhere('project_id', 'like', "%{$q}%");
          });
        })
        ->orderByDesc('created_at')
        ->paginate(50)
        ->withQueryString();

      $rowsHtml = '';
      foreach ($proposals as $proposal) {
        $rowsHtml .= view('_partials.not-qualified-row', ['proposal' => $proposal])->render();
      }
      if ($proposals->isEmpty()) {
        $rowsHtml = '<tr><td colspan="6" class="text-center text-muted py-4">No not-qualified proposals yet.</td></tr>';
      }

      return response()->json([
        'cards' => $cards,
        'statusCounts' => $statusCounts,
        'rowsHtml' => $rowsHtml,
        'paginationHtml' => $proposals->links('vendor.pagination.bootstrap-5')->render(),
      ]);
    }
```

- [ ] **Step 5: Run tests**

Run: `php artisan test --filter=NotQualifiedTabTest`
Expected: PASS (4 tests)

Run: `php artisan test --filter=BidsDataTest`
Expected: PASS (existing tabs untouched)

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/BidController.php resources/views/_partials/not-qualified-row.blade.php tests/Feature/NotQualifiedTabTest.php
git commit -m "feat: serve not-qualified proposals through /bids/data tab"
```

---

### Task 2: Front end tab + removals

**Files:**
- Modify: `resources/views/content/pages/home.blade.php` (tab nav line ~176-180, thead line ~184-196, loadData JS line ~237-262, filter bar line ~17-46, CSS line ~119-129)
- Modify: `resources/menu/verticalMenu.json` (remove Not Qualified entry)
- Modify: `routes/web.php` (remove `/not-qualified` route)
- Delete: `app/Http/Controllers/NotQualifiedController.php`, `resources/views/content/pages/not-qualified.blade.php`, `tests/Feature/NotQualifiedPageTest.php`
- Test: `tests/Feature/NotQualifiedTabTest.php` (add two tests)

**Interfaces:**
- Consumes: Task 1's endpoint behavior (colspan-6 rows, same JSON keys).
- Produces: Bids page with 4 tabs; `/not-qualified` gone everywhere.

- [ ] **Step 1: Add the failing tests**

Append to `tests/Feature/NotQualifiedTabTest.php` (inside the class):

```php
    public function test_bids_page_has_fourth_tab(): void
    {
        $this->actingAs($this->user())->get('/bids')
            ->assertOk()
            ->assertSee('data-tab="not-qualified"', false)
            ->assertSee('Not Qualified');
    }

    public function test_old_standalone_route_removed(): void
    {
        $this->actingAs($this->user())->get('/not-qualified')->assertNotFound();
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=NotQualifiedTabTest`
Expected: FAIL — fourth-tab assertion (button missing) and old-route assertion (page still 200).

- [ ] **Step 3: home.blade.php — tab button + CSS**

In the tab nav (line ~176-180), add after the Failed Bids `<li>`:

```html
                <li class="nav-item"><button class="nav-link" data-tab="not-qualified" type="button">Not Qualified</button></li>
```

In the `<style>` block, after the `[data-tab="completed"].active` rule (line ~129), add:

```css
        #bids-tabs .nav-link[data-tab="not-qualified"].active {
            color: #ff9800;
            background-color: rgba(255, 152, 0, .12);
            border-bottom-color: #ff9800;
        }
```

- [ ] **Step 4: home.blade.php — thead swap**

Replace the existing single `<tr>` inside `<thead>` (lines ~185-195) with two rows:

```html
                    <tr id="thead-bids">
                        <th>ID</th>
                        <th>Title</th>
                        <th>Price</th>
                        <th>Status</th>
                        <th>Type</th>
                        <th class="completed-col d-none">Awarded</th>
                        <th class="completed-col d-none">Awarded Price</th>
                        <th>Time</th>
                        <th>Review</th>
                    </tr>
                    <tr id="thead-nq" class="d-none">
                        <th>Project</th>
                        <th>Title</th>
                        <th>Reason</th>
                        <th>Summary</th>
                        <th>When</th>
                        <th></th>
                    </tr>
```

- [ ] **Step 5: home.blade.php — bid-only filters + JS toggles**

Add the class `bid-only-filter` to the five bid-specific filter column divs (From, To, Min amount, Max amount, Type — lines ~18-41), e.g. `<div class="col-6 col-md-2 bid-only-filter">`. The Search div keeps no extra class.

In `loadData()`, right after the existing `completed-col` toggle (line ~256-257), add:

```javascript
                const nq = currentTab === 'not-qualified';
                el('thead-bids').classList.toggle('d-none', nq);
                el('thead-nq').classList.toggle('d-none', !nq);
                document.querySelectorAll('.bid-only-filter').forEach(d => d.classList.toggle('d-none', nq));
```

- [ ] **Step 6: Removals**

- `routes/web.php`: delete the line `Route::get('/not-qualified', [\App\Http\Controllers\NotQualifiedController::class, 'index'])->name('not-qualified');`
- Delete files:

```bash
rm app/Http/Controllers/NotQualifiedController.php resources/views/content/pages/not-qualified.blade.php tests/Feature/NotQualifiedPageTest.php
```

- `resources/menu/verticalMenu.json`: remove the whole `{ "url": "/not-qualified", ... }` object (keep valid JSON — watch the commas).

- [ ] **Step 7: Run tests**

Run: `php artisan test --filter=NotQualifiedTabTest`
Expected: PASS (6 tests)

Run: `php artisan test`
Expected: all green except pre-existing ExampleTest failure (NotQualifiedPageTest no longer exists).

- [ ] **Step 8: Commit**

```bash
git add -A
git commit -m "feat: move not-qualified into bids page as fourth tab, drop standalone page"
```
