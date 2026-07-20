# Filters Cleanup Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Remove Keywords/Negative Keywords from Filters UI + server logic, rename Negative Prompt label to "Qualifier Prompt" (shown before Prompt) with info popovers on all three prompt titles, and delete the dead `GET /api/filters` route.

**Architecture:** Pure removal + relabel. Blade form loses all keyword inputs and the `usekeywords` toggle; `FilterController` loses keyword processing; `ProposalController@getProposals` loses the keyword search param and negative-keyword skip checks. DB schema, `Keyword`/`NegativeKeyword` model files, and the `negative_prompt` column stay untouched (code-only removal, label-only rename — user decisions).

**Tech Stack:** Laravel 10 Blade, Bootstrap 5 popovers (bundled in Sneat template), PHPUnit (sqlite in-memory).

**Spec:** `docs/superpowers/specs/2026-07-20-filters-cleanup-design.md`

## Global Constraints

- Form field names for prompts UNCHANGED: `formValidationPrompt`, `formValidationNegativePrompt`, `formValidationSummaryPrompt`. Only the visible label of the negative-prompt field changes, to exactly "Qualifier Prompt".
- Field order in the form: Qualifier Prompt → Prompt → Summary Prompt.
- Popover pattern: `<i class="bx bx-info-circle text-muted" tabindex="0" data-bs-toggle="popover" data-bs-trigger="hover focus" title="..." data-bs-content="...">` + page-script init `new bootstrap.Popover(el)`. Popover texts are fixed copy — use verbatim from the tasks below.
- Do NOT touch: `Keyword.php` / `NegativeKeyword.php` model files, any migration, the `negative_prompt` / `usekeywords` columns, `OpenAIJob`, `ProposalQualifier`.
- Admin-only pages/tests: `User::factory()->create(['role' => 'admin'])` (pattern of `tests/Feature/FilterNegativePromptSaveTest.php`).
- Known pre-existing failure: ExampleTest (unrelated). Everything else must stay green.
- Local commits only; NEVER push. Current branch: `filters-and-ui-modifications`.

---

### Task 1: Filters page — remove keyword inputs, rename + reorder prompts, add popovers

**Files:**
- Modify: `resources/views/content/pages/filters.blade.php`
- Modify: `app/Http/Controllers/FilterController.php` (`index` lines 24-38, `update` lines 89-208)
- Test: `tests/Feature/FiltersPageCleanupTest.php` (new)

**Interfaces:**
- Consumes: existing route `GET /filters` (admin middleware), `POST /updateFilters`.
- Produces: `index()` passes only `filter`, `countries`, `currencies` to the view. Task 2 does not depend on this task's outputs.

- [ ] **Step 1: Write the failing test**

`tests/Feature/FiltersPageCleanupTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Filter;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FiltersPageCleanupTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    public function test_qualifier_prompt_shown_before_prompt(): void
    {
        Filter::factory()->create(['id' => 1]);

        $this->actingAs($this->admin())->get('/filters')
            ->assertOk()
            ->assertSeeInOrder(['Qualifier Prompt', 'Prompt', 'Summary Prompt']);
    }

    public function test_keyword_inputs_removed(): void
    {
        Filter::factory()->create(['id' => 1]);

        $res = $this->actingAs($this->admin())->get('/filters')->assertOk();
        $res->assertDontSee('Negative Keywords');
        $res->assertDontSee('TagifyBasic');
        $res->assertDontSee('tagifyNegativeKeywords');
        $res->assertDontSee('formValidationKeywords');
        $res->assertDontSee('name="usekeywords"', false);
    }

    public function test_prompt_labels_have_info_popovers(): void
    {
        Filter::factory()->create(['id' => 1]);

        $res = $this->actingAs($this->admin())->get('/filters')->assertOk();
        $res->assertSee('data-bs-toggle="popover"', false);
        $res->assertSee('Runs first, when the crawler saves a new project');
        $res->assertSee('Used as the AI system message to write the bid cover letter');
        $res->assertSee('Rewrites the raw reason into a short summary');
    }

    public function test_update_without_keyword_fields_saves_prompts(): void
    {
        Filter::factory()->create(['id' => 1]);

        $this->actingAs($this->admin())
            ->post('/updateFilters', [
                'formValidationPrompt' => 'write a great proposal',
                'formValidationNegativePrompt' => 'skip crypto',
                'formValidationSummaryPrompt' => 'summarize briefly',
            ])
            ->assertRedirect('/filters');

        $filter = Filter::find(1);
        $this->assertSame('write a great proposal', $filter->prompt);
        $this->assertSame('skip crypto', $filter->negative_prompt);
        $this->assertSame('summarize briefly', $filter->summary_prompt);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=FiltersPageCleanupTest`
Expected: FAIL — `test_qualifier_prompt_shown_before_prompt` (label doesn't exist), `test_keyword_inputs_removed` (inputs still present), `test_prompt_labels_have_info_popovers` (no popovers). `test_update_without_keyword_fields_saves_prompts` may already pass.

- [ ] **Step 3: Rewrite the Blade view**

Replace the whole content of `resources/views/content/pages/filters.blade.php` with:

```blade
@extends('layouts.layoutMaster')


@section('title', 'Filters')


@section('vendor-style')
    <link rel="stylesheet" href="{{ asset('assets/vendor/libs/bootstrap-select/bootstrap-select.css') }}"/>
    <link rel="stylesheet" href="{{ asset('assets/vendor/libs/select2/select2.css') }}"/>
    <link rel="stylesheet" href="{{ asset('assets/vendor/libs/flatpickr/flatpickr.css') }}"/>
    <link rel="stylesheet" href="{{ asset('assets/vendor/libs/typeahead-js/typeahead.css') }}"/>
    <link rel="stylesheet" href="{{ asset('assets/vendor/libs/formvalidation/dist/css/formValidation.min.css') }}"/>
@endsection

@section('vendor-script')
    <script src="{{ asset('assets/vendor/libs/select2/select2.js') }}"></script>
    <script src="{{ asset('assets/vendor/libs/bootstrap-select/bootstrap-select.js') }}"></script>
    <script src="{{ asset('assets/vendor/libs/moment/moment.js') }}"></script>
    <script src="{{ asset('assets/vendor/libs/flatpickr/flatpickr.js') }}"></script>
    <script src="{{ asset('assets/vendor/libs/typeahead-js/typeahead.js') }}"></script>
    <script src="{{ asset('assets/vendor/libs/formvalidation/dist/js/FormValidation.min.js') }}"></script>
    <script src="{{ asset('assets/vendor/libs/formvalidation/dist/js/plugins/Bootstrap5.min.js') }}"></script>
    <script src="{{ asset('assets/vendor/libs/formvalidation/dist/js/plugins/AutoFocus.min.js') }}"></script>
@endsection

@section('page-script')
    <script src="{{ asset('assets/js/form-validation.js') }}"></script>
    <script>
        document.addEventListener("DOMContentLoaded", function () {
            document.querySelectorAll('[data-bs-toggle="popover"]').forEach(function (el) {
                new bootstrap.Popover(el);
            });
        });
    </script>
@endsection

@section('content')
    <h4 class="page-title">Filters</h4>
    <div class="row">
        <!-- FormValidation -->
        <div class="col-12">
            <div class="card">
                <h5 class="card-header">Set Crawler Filter</h5>
                <div class="card-body">

                    <form id="formValidationExamples" method="POST" action={{ route('updateFilters') }} class="row g-3
                    ">
                    @csrf
                    <!-- Personal Info -->
                    <div class="col-12">
                        <h6 class="mt-2 fw-normal">1. Filter Data</h6>
                        <hr class="mt-0"/>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label" for="formValidationCountries">Countries</label>
                        <select class="selectpicker w-100" id="formValidationCountries" data-style="btn-default"
                                data-icon-base="bx" data-tick-icon="bx-check text-white"
                                name="formValidationCountries[]"
                                multiple @if (!$filter->usecountries) disabled @endif>
                            @foreach ($countries as $country)
                                <option value="{{ $country->id }}" @if (in_array(
                                            $country->id,
                                            $filter->countries()->pluck('countries.id')->all())) selected @endif>
                                    {{ $country->country }} - {{ $country->language }}
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label" for="formValidationCurrencies">Currencies</label>
                        <select class="selectpicker w-100" id="formValidationCurrencies" data-style="btn-default"
                                data-icon-base="bx" data-tick-icon="bx-check text-white"
                                name="formValidationCurrencies[]"
                                multiple disabled>
                            @foreach ($currencies as $currency)
                                <option value="{{ $currency->id }}" @if (in_array(
                                            $currency->id,
                                            $filter->currencies()->pluck('currencies.id')->all())) selected @endif>
                                    {{ $currency->currency_name }} - {{ $currency->curreny_symbol }}
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label" for="formValidationMinHourlyRate">Min Hourly Rate</label>
                        <input type="number" class="form-control" name="formValidationMinHourlyRate"
                               value="{{ $filter->min_hourly_amount }}" rows="3"
                               @if (!$filter->useminhour) disabled @endif></input>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label" for="formValidationMinFixedRate">Min Fixed Rate</label>
                        <input type="number" class="form-control" name="formValidationMinFixedRate" rows="3"
                               value="{{ $filter->min_fixed_amount }}"
                               @if (!$filter->useminfix) disabled @endif />
                    </div>

                    <div class="col-md-6">
                        <label class="form-label" for="formValidationNegativePrompt">Qualifier Prompt
                            <i class="bx bx-info-circle text-muted" tabindex="0"
                               data-bs-toggle="popover" data-bs-trigger="hover focus"
                               title="Qualifier Prompt"
                               data-bs-content="Runs first, when the crawler saves a new project. Sent to OpenAI together with the project description to decide if the project matches your skip-criteria. If it matches, the project is marked Not Qualified and no bid is generated. If the AI call fails, the project is treated as not qualified (fail-closed)."></i>
                        </label>
                        <textarea class="form-control" id="formValidationNegativePrompt"
                                  name="formValidationNegativePrompt" rows="3">{{ $filter->negative_prompt }}</textarea>
                        <label class="form-label mt-3" for="formValidationPrompt">Prompt
                            <i class="bx bx-info-circle text-muted" tabindex="0"
                               data-bs-toggle="popover" data-bs-trigger="hover focus"
                               title="Prompt"
                               data-bs-content="Runs after the Qualifier Prompt gate passes. Used as the AI system message to write the bid cover letter for the project."></i>
                        </label>
                        <textarea class="form-control" id="formValidationPrompt" name="formValidationPrompt"
                                  rows="3">{{ $filter->prompt }}</textarea>
                        <label class="form-label mt-3" for="formValidationSummaryPrompt">Summary Prompt
                            <i class="bx bx-info-circle text-muted" tabindex="0"
                               data-bs-toggle="popover" data-bs-trigger="hover focus"
                               title="Summary Prompt"
                               data-bs-content="Runs only when a project fails qualification and a rejection reason was recorded. Rewrites the raw reason into a short summary shown on the Not Qualified page and in bid details."></i>
                        </label>
                        <textarea class="form-control" id="formValidationSummaryPrompt"
                                  name="formValidationSummaryPrompt" rows="3">{{ $filter->summary_prompt }}</textarea>
                    </div>

                    <div class="form-check">
                        <input type="checkbox" class="form-check-input" id="formValidationCheckbox"
                               name="formValidationCrawler" value="1"
                               @if ($filter->crawler_on) checked @endif />
                        <label class="form-check-label">Crawler Enabled</label>
                    </div>

                    <div class="row">
                        {{-- Use Countries --}}
                        <label class="switch switch-success mt-4 col-auto">
                            <input type="checkbox" class="switch-input" name="useCountries"
                                   @if ($filter->usecountries) checked @endif />
                            <span class="switch-toggle-slider">
                                    <span class="switch-on">
                                        <i class="bx bx-check"></i>
                                    </span>
                                    <span class="switch-off">
                                        <i class="bx bx-x"></i>
                                    </span>
                                </span>
                            <span class="switch-label">Countries</span>
                        </label>

                        {{-- Use Min Fixed --}}
                        <label class="switch switch-success mt-4 col-auto">
                            <input type="checkbox" class="switch-input" name="useminfix"
                                   @if ($filter->useminfix) checked @endif />
                            <span class="switch-toggle-slider">
                                    <span class="switch-on">
                                        <i class="bx bx-check"></i>
                                    </span>
                                    <span class="switch-off">
                                        <i class="bx bx-x"></i>
                                    </span>
                                </span>
                            <span class="switch-label">Min Fixed Cost</span>
                        </label>

                        {{-- Use Min Hourly --}}
                        <label class="switch switch-success mt-4 col-auto">
                            <input type="checkbox" class="switch-input" name="useminhour"
                                   @if ($filter->useminhour) checked @endif />
                            <span class="switch-toggle-slider">
                                    <span class="switch-on">
                                        <i class="bx bx-check"></i>
                                    </span>
                                    <span class="switch-off">
                                        <i class="bx bx-x"></i>
                                    </span>
                                </span>
                            <span class="switch-label">Min Hourly Cost</span>
                        </label>
                    </div>

                    <div class="col-12">
                        <button type="Save" name="submitButton" class="btn btn-primary">Submit</button>
                    </div>
                    </form>
                </div>
            </div>
        </div>
        <!-- /FormValidation -->
    </div>
@endsection
```

Removed vs the old file: Tagify css/js includes and init, the `@php $tags/$negTags @endphp` + two `@foreach` blocks above the content section, both Tagify inputs, the deprecated `formValidationKeywords` select, the empty `<span class="col-md-6"></span>` spacer, and the `usekeywords` switch. Prompt block reordered with Qualifier Prompt first.

- [ ] **Step 4: Slim down FilterController**

In `app/Http/Controllers/FilterController.php`:

Replace `index()` (lines 24-38) with:

```php
    public function index()
    {
        $filter = Filter::find(1);
        $countries = Country::all();
        $currencies = Currency::all();

        return view('content.pages.filters', ['filter' => $filter, 'countries' => $countries, 'currencies' => $currencies]);
    }
```

In `update()`:
- Delete lines 99-101 (`$tags`, `$negTags`, `$selectedKeywords` assignments).
- Delete the entire `if ($tags) { ... }` block (lines 105-127).
- Delete the entire `if ($negTags) { ... }` block (lines 129-152, including the `/// [Neg Tags]` comment).
- Delete the `if ($selectedKeywords) { ... }` block (lines 175-180).
- Delete line 197: `$filter->usekeywords = $request->usekeywords == "on" ? 1 : 0;`

Remove now-unused imports (lines 10-11):

```php
use App\Models\Keyword;
use App\Models\NegativeKeyword;
```

- [ ] **Step 5: Run tests**

Run: `php artisan test --filter=FiltersPageCleanupTest`
Expected: PASS (4 tests)

Run: `php artisan test --filter=FilterNegativePromptSaveTest` and `php artisan test --filter=FilterSummaryPromptSaveTest`
Expected: PASS (unchanged field names)

- [ ] **Step 6: Commit**

```bash
git add resources/views/content/pages/filters.blade.php app/Http/Controllers/FilterController.php tests/Feature/FiltersPageCleanupTest.php
git commit -m "feat: remove keyword inputs from filters, rename to Qualifier Prompt with info popovers"
```

---

### Task 2: Remove keyword gates from crawler + Filter model + dead API route

**Files:**
- Modify: `app/Http/Controllers/ProposalController.php` (lines 16, 125-139, 170, 226-236)
- Modify: `app/Models/Filter.php` (lines 46-54)
- Modify: `routes/api.php` (line 30)
- Test: `tests/Feature/CrawlerNegativeKeywordRemovalTest.php` (new)

**Interfaces:**
- Consumes: `ProposalController::getProposals()` crawler flow, `Filter` model.
- Produces: crawler that ignores keyword tables entirely; `Filter` model without `keywords()` / `negativeKeywords()` relations.

- [ ] **Step 1: Write the failing test**

`tests/Feature/CrawlerNegativeKeywordRemovalTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Http\Controllers\ProposalController;
use App\Models\Country;
use App\Models\Filter;
use App\Models\NegativeKeyword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class CrawlerNegativeKeywordRemovalTest extends TestCase
{
    use RefreshDatabase;

    /** Build a minimal valid Freelancer project payload. */
    private function project(int $id, string $title, string $description): array
    {
        return [
            'id' => $id,
            'title' => $title,
            'description' => $description,
            'seo_url' => 'project-' . $id,
            'type' => 'fixed',
            'language' => 'en',
            'owner_id' => 1,
            'time_submitted' => 1700000000,
            'budget' => ['minimum' => 250, 'maximum' => 750],
            'currency' => ['code' => 'USD', 'sign' => '$', 'country' => 'US', 'exchange_rate' => 1],
            'upgrades' => ['NDA' => false, 'sealed' => false],
            'jobs' => [['id' => 1, 'name' => 'PHP']],
        ];
    }

    public function test_project_with_negative_keyword_term_is_no_longer_skipped(): void
    {
        Queue::fake();

        $filter = Filter::factory()->create([
            'id' => 1,
            'crawler_on' => 1,
            'useminfix' => 0,
            'useminhour' => 0,
            'usekeywords' => 0,
            'usecountries' => 1,
        ]);
        $country = new Country();
        $country->country = 'US';
        $country->language = 'US';
        $country->save();
        $filter->countries()->attach($country->id);

        // Table still exists; entries must be inert now.
        NegativeKeyword::create(['name' => 'gambling']);

        Http::fake([
            '*support*' => Http::response(['result' => null], 200),
            '*projects/active*' => Http::response([
                'status' => 'success',
                'result' => ['projects' => [
                    $this->project(901, 'Build a gambling website', 'A gambling platform project.'),
                ]],
            ], 200),
        ]);

        (new ProposalController())->getProposals();

        $this->assertDatabaseHas('proposals', ['project_id' => 901]);
    }
}
```

Note: if `NegativeKeyword::create(['name' => ...])` fails with a mass-assignment error, use `$nk = new NegativeKeyword(); $nk->name = 'gambling'; $nk->save();` instead — do not add `$fillable` to the model (it stays untouched).

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=CrawlerNegativeKeywordRemovalTest`
Expected: FAIL — proposal 901 missing (skipped by the negative-keyword title check).

- [ ] **Step 3: Remove keyword logic from ProposalController**

In `app/Http/Controllers/ProposalController.php`:

Delete the import (line 16):

```php
use App\Models\NegativeKeyword;
```

Delete the `usekeywords` query-param block (lines 125-139):

```php
        if ($filter->usekeywords) {
            $textQuery = '';

            $keywords = $filter->keywords()->pluck('name')->toArray();

            if ($keywords) {
                foreach ($keywords as $keyword) {
                    $textQuery = $textQuery . ' ' . $keyword;
                }
            }

            if ($textQuery) {
                $params['query'] = $textQuery;
            }
        }
```

Delete line 170:

```php
            $negativeKeywords = NegativeKeyword::pluck('name')->toArray();
```

Delete the title check (lines 226-229):

```php
                    if (Str::contains($proposal->title, $negativeKeywords)) {
                        \Log::info("Cannot Proceed because Negative Keywords Exists in Title");
                        continue;
                    }
```

Delete the description check (lines 233-236):

```php
                    if (Str::contains($proposal->description, $negativeKeywords)) {
                        \Log::info("Cannot Proceed because Negative Keywords Exists in Description");
                        continue;
                    }
```

Keep the `$proposal->title = ...` and `$proposal->description = ...` assignments that surrounded those checks. If `Str` has no other usage left in the file after this (check with `grep -n "Str::" app/Http/Controllers/ProposalController.php`), leave the `use Illuminate\Support\Str;` import only if still used elsewhere in the file; remove it if now unused.

- [ ] **Step 4: Remove relations from Filter model**

In `app/Models/Filter.php`, delete lines 46-54:

```php
  public function keywords()
  {
    return $this->belongsToMany(Keyword::class);
  }

  public function negativeKeywords()
  {
    return $this->belongsToMany(NegativeKeyword::class);
  }
```

Remove the now-unused `use App\Models\Keyword;` / `use App\Models\NegativeKeyword;` imports if present at the top of the model (check the file's use block; only remove ones that become unused).

- [ ] **Step 5: Remove dead API route**

In `routes/api.php`, delete line 30:

```php
Route::get('filters', [FilterController::class, 'getFilters']);
```

Remove the `use App\Http\Controllers\FilterController;` import (line 8) if no other route in the file references `FilterController` (check with grep — it doesn't).

- [ ] **Step 6: Run tests**

Run: `php artisan test --filter=CrawlerNegativeKeywordRemovalTest`
Expected: PASS (1 test)

Run: `php artisan test --filter=CrawlerCountryFilterTest`
Expected: PASS (crawler flow intact)

- [ ] **Step 7: Run full suite**

Run: `php artisan test`
Expected: all green except known pre-existing ExampleTest failure.

- [ ] **Step 8: Commit**

```bash
git add app/Http/Controllers/ProposalController.php app/Models/Filter.php routes/api.php tests/Feature/CrawlerNegativeKeywordRemovalTest.php
git commit -m "feat: remove keyword filtering from crawler and dead filters API route"
```
