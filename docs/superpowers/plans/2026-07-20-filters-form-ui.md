# Filters Form UI Polish Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Sectioned Filters form — criteria fields with inline enable-switches, full-width prompt textareas, footer with Crawler Enabled switch + Submit.

**Architecture:** Blade-only rewrite of `filters.blade.php`. No controller, route, or field-name changes; submitted form data is byte-identical in semantics to today (checkbox/switch inputs submit `on`/absent exactly as before).

**Tech Stack:** Laravel 10 Blade, Sneat/Bootstrap 5 classes (`switch`, `switch-sm`, `d-flex`), existing popover init.

**Spec:** `docs/superpowers/specs/2026-07-20-filters-form-ui-design.md`

## Global Constraints

- Field names/ids UNCHANGED: `formValidationCountries[]`, `formValidationCurrencies[]`, `formValidationMinHourlyRate`, `formValidationMinFixedRate`, `formValidationNegativePrompt`, `formValidationPrompt`, `formValidationSummaryPrompt`, `formValidationCrawler` (value="1"), `useCountries`, `useminfix`, `useminhour`.
- Prompt order stays Qualifier Prompt → Prompt → Summary Prompt; popover icons and copy verbatim from current file (do not alter `data-bs-content` texts).
- Section headings exactly: "1. Project Criteria" and "2. AI Prompts".
- Existing tests must stay green: FiltersPageCleanupTest, FilterNegativePromptSaveTest, FilterSummaryPromptSaveTest. Known pre-existing failure: ExampleTest (unrelated).
- Local commits only; NEVER push. Branch: `filters-and-ui-modifications`.

---

### Task 1: Sectioned form layout

**Files:**
- Modify: `resources/views/content/pages/filters.blade.php` (full rewrite of `@section('content')` block only — keep title/vendor-style/vendor-script/page-script sections exactly as they are)
- Test: `tests/Feature/FiltersPageCleanupTest.php` (add one test method)

**Interfaces:**
- Consumes: `$filter`, `$countries`, `$currencies` from `FilterController@index` (unchanged).
- Produces: same POST payload shape to `/updateFilters`.

- [ ] **Step 1: Add the failing test**

Append to `tests/Feature/FiltersPageCleanupTest.php` (inside the class):

```php
    public function test_sectioned_layout_with_inline_switches(): void
    {
        Filter::factory()->create(['id' => 1]);

        $res = $this->actingAs($this->admin())->get('/filters')->assertOk();
        $res->assertSeeInOrder(['1. Project Criteria', '2. AI Prompts']);
        $res->assertSee('name="useCountries"', false);
        $res->assertSee('name="useminhour"', false);
        $res->assertSee('name="useminfix"', false);
        $res->assertSee('name="formValidationCrawler"', false);
        $res->assertSee('Crawler Enabled');
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=FiltersPageCleanupTest`
Expected: FAIL — `test_sectioned_layout_with_inline_switches` ("1. Project Criteria" not in page). The 4 existing tests still pass.

- [ ] **Step 3: Rewrite the content section**

In `resources/views/content/pages/filters.blade.php`, leave `@section('title')`, `@section('vendor-style')`, `@section('vendor-script')`, and `@section('page-script')` untouched. Replace the whole `@section('content') ... @endsection` block with:

```blade
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

                    <div class="col-12">
                        <h6 class="mt-2 fw-normal">1. Project Criteria</h6>
                        <hr class="mt-0"/>
                    </div>

                    <div class="col-md-6">
                        <div class="d-flex justify-content-between align-items-center">
                            <label class="form-label mb-0" for="formValidationCountries">Countries</label>
                            <label class="switch switch-success switch-sm mb-0">
                                <input type="checkbox" class="switch-input" name="useCountries"
                                       @if ($filter->usecountries) checked @endif />
                                <span class="switch-toggle-slider">
                                    <span class="switch-on"><i class="bx bx-check"></i></span>
                                    <span class="switch-off"><i class="bx bx-x"></i></span>
                                </span>
                            </label>
                        </div>
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
                        <label class="form-label" for="formValidationCurrencies">Currencies
                            <small class="text-muted">(locked)</small></label>
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
                        <div class="d-flex justify-content-between align-items-center">
                            <label class="form-label mb-0" for="formValidationMinHourlyRate">Min Hourly Rate</label>
                            <label class="switch switch-success switch-sm mb-0">
                                <input type="checkbox" class="switch-input" name="useminhour"
                                       @if ($filter->useminhour) checked @endif />
                                <span class="switch-toggle-slider">
                                    <span class="switch-on"><i class="bx bx-check"></i></span>
                                    <span class="switch-off"><i class="bx bx-x"></i></span>
                                </span>
                            </label>
                        </div>
                        <input type="number" class="form-control" name="formValidationMinHourlyRate"
                               value="{{ $filter->min_hourly_amount }}"
                               @if (!$filter->useminhour) disabled @endif />
                    </div>

                    <div class="col-md-6">
                        <div class="d-flex justify-content-between align-items-center">
                            <label class="form-label mb-0" for="formValidationMinFixedRate">Min Fixed Rate</label>
                            <label class="switch switch-success switch-sm mb-0">
                                <input type="checkbox" class="switch-input" name="useminfix"
                                       @if ($filter->useminfix) checked @endif />
                                <span class="switch-toggle-slider">
                                    <span class="switch-on"><i class="bx bx-check"></i></span>
                                    <span class="switch-off"><i class="bx bx-x"></i></span>
                                </span>
                            </label>
                        </div>
                        <input type="number" class="form-control" name="formValidationMinFixedRate"
                               value="{{ $filter->min_fixed_amount }}"
                               @if (!$filter->useminfix) disabled @endif />
                    </div>

                    <div class="col-12">
                        <h6 class="mt-4 fw-normal">2. AI Prompts</h6>
                        <hr class="mt-0"/>
                    </div>

                    <div class="col-12">
                        <label class="form-label" for="formValidationNegativePrompt">Qualifier Prompt
                            <i class="bx bx-info-circle text-muted" tabindex="0"
                               data-bs-toggle="popover" data-bs-trigger="hover focus"
                               title="Qualifier Prompt"
                               data-bs-content="Runs first, when the crawler saves a new project. Sent to OpenAI together with the project description to decide if the project matches your skip-criteria. If it matches, the project is marked Not Qualified and no bid is generated. If the AI call fails, the project is treated as not qualified (fail-closed)."></i>
                        </label>
                        <textarea class="form-control" id="formValidationNegativePrompt"
                                  name="formValidationNegativePrompt" rows="4">{{ $filter->negative_prompt }}</textarea>
                    </div>

                    <div class="col-12">
                        <label class="form-label" for="formValidationPrompt">Prompt
                            <i class="bx bx-info-circle text-muted" tabindex="0"
                               data-bs-toggle="popover" data-bs-trigger="hover focus"
                               title="Prompt"
                               data-bs-content="Runs after the Qualifier Prompt gate passes. Used as the AI system message to write the bid cover letter for the project."></i>
                        </label>
                        <textarea class="form-control" id="formValidationPrompt" name="formValidationPrompt"
                                  rows="4">{{ $filter->prompt }}</textarea>
                    </div>

                    <div class="col-12">
                        <label class="form-label" for="formValidationSummaryPrompt">Summary Prompt
                            <i class="bx bx-info-circle text-muted" tabindex="0"
                               data-bs-toggle="popover" data-bs-trigger="hover focus"
                               title="Summary Prompt"
                               data-bs-content="Runs only when a project fails qualification and a rejection reason was recorded. Rewrites the raw reason into a short summary shown on the Not Qualified page and in bid details."></i>
                        </label>
                        <textarea class="form-control" id="formValidationSummaryPrompt"
                                  name="formValidationSummaryPrompt" rows="4">{{ $filter->summary_prompt }}</textarea>
                    </div>

                    <div class="col-12">
                        <hr class="mb-0"/>
                    </div>

                    <div class="col-12 d-flex justify-content-between align-items-center">
                        <label class="switch switch-success mb-0">
                            <input type="checkbox" class="switch-input" name="formValidationCrawler" value="1"
                                   @if ($filter->crawler_on) checked @endif />
                            <span class="switch-toggle-slider">
                                <span class="switch-on"><i class="bx bx-check"></i></span>
                                <span class="switch-off"><i class="bx bx-x"></i></span>
                            </span>
                            <span class="switch-label">Crawler Enabled</span>
                        </label>
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

Removed vs the previous version: the bottom toggles row (`usekeywords` already gone; `useCountries`/`useminfix`/`useminhour` moved inline), the plain Crawler Enabled checkbox (now footer switch), the stray `</input>` tag and `rows="3"` attributes on number inputs.

- [ ] **Step 4: Run tests**

Run: `php artisan test --filter=FiltersPageCleanupTest`
Expected: PASS (5 tests)

Run: `php artisan test`
Expected: all green except pre-existing ExampleTest failure.

- [ ] **Step 5: Commit**

```bash
git add resources/views/content/pages/filters.blade.php tests/Feature/FiltersPageCleanupTest.php
git commit -m "feat: sectioned filters form with inline switches and full-width prompts"
```
