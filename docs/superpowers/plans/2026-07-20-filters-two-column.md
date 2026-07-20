# Filters Two-Column Layout Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Two-column Filters form: stepped prompt column (Step 1-3, tall textareas) left, criteria + Control box right, Save Changes footer.

**Architecture:** Blade-only rewrite of the form body inside `@section('content')` of `filters.blade.php` (toast block, page-title, card shell, form tag, csrf all preserved). Test expectations updated. No controller/DB/field-name changes.

**Tech Stack:** Laravel 10 Blade, Bootstrap 5 grid + Sneat switches.

**Spec:** `docs/superpowers/specs/2026-07-20-filters-two-column-design.md`

## Global Constraints

- Field names/ids UNCHANGED: `formValidationCountries[]`, `formValidationCurrencies[]`, `formValidationMinHourlyRate`, `formValidationMinFixedRate`, `formValidationNegativePrompt`, `formValidationPrompt`, `formValidationSummaryPrompt`, `formValidationCrawler` (value="1"), `useCountries`, `useminhour`, `useminfix`.
- Step labels exactly: "Step 1 - Qualifier Prompt", "Step 2 - Proposal Drafting Prompt", "Step 3 - Response Allocation Prompt". Textareas `rows="20"`.
- Criteria labels exactly: "Allowed Countries", "Allowed Currencies" (with muted "(locked)"), "Min Hourly Project Rate", "Min Fixed Project Rate".
- Control box titled "Control": row 1 "Enable Crawler" switch, row 2 three left-aligned switches (Countries / Min Hourly Cost / Min Fixed Cost).
- Button text exactly "Save Changes", right-aligned full-width footer row, type submit.
- Popover icons + `data-bs-content` copy carried over VERBATIM from the current file (do not edit the three content strings).
- Toast markup/JS, vendor includes, form action/method/csrf untouched.
- No Claude co-author trailer in commit messages.
- Known pre-existing failure: ExampleTest. Local commits only; NEVER push. Branch: `hotFixes`.

---

### Task 1: Two-column layout

**Files:**
- Modify: `resources/views/content/pages/filters.blade.php` (lines 74-202 — everything between `@csrf` and `</form>`)
- Test: `tests/Feature/FiltersPageCleanupTest.php`

**Interfaces:**
- Consumes: `$filter`, `$countries`, `$currencies` from `FilterController@index` (unchanged).
- Produces: same POST payload to `/updateFilters`.

- [ ] **Step 1: Update the tests**

In `tests/Feature/FiltersPageCleanupTest.php`:

Replace `test_qualifier_prompt_shown_before_prompt` with:

```php
    public function test_stepped_prompts_shown_in_order(): void
    {
        Filter::factory()->create(['id' => 1]);

        $this->actingAs($this->admin())->get('/filters')
            ->assertOk()
            ->assertSeeInOrder([
                'Step 1 - Qualifier Prompt',
                'Step 2 - Proposal Drafting Prompt',
                'Step 3 - Response Allocation Prompt',
            ]);
    }
```

Replace `test_sectioned_layout_with_inline_switches` with:

```php
    public function test_two_column_layout_criteria_and_control(): void
    {
        Filter::factory()->create(['id' => 1]);

        $res = $this->actingAs($this->admin())->get('/filters')->assertOk();
        $res->assertSeeInOrder(['Allowed Countries', 'Allowed Currencies', 'Min Hourly Project Rate', 'Min Fixed Project Rate', 'Control', 'Enable Crawler']);
        $res->assertSee('name="useCountries"', false);
        $res->assertSee('name="useminhour"', false);
        $res->assertSee('name="useminfix"', false);
        $res->assertSee('name="formValidationCrawler"', false);
        $res->assertSee('Save Changes');
        $res->assertSee('rows="20"', false);
    }
```

Leave every other test untouched.

- [ ] **Step 2: Run tests to verify the new ones fail**

Run: `php artisan test --filter=FiltersPageCleanupTest`
Expected: FAIL — both new tests (labels don't exist yet). Other 4 pass.

- [ ] **Step 3: Rewrite the form body**

In `resources/views/content/pages/filters.blade.php`, replace everything between the `@csrf` line and the closing `</form>` tag (currently lines 74-202: the two section headers, criteria fields, apply-criteria strip, three prompt blocks, and the footer strip) with:

```blade
                    <div class="col-lg-7">
                        <div class="mb-3">
                            <label class="form-label fw-semibold" for="formValidationNegativePrompt">Step 1 - Qualifier Prompt
                                <i class="bx bx-info-circle text-muted" tabindex="0"
                                   data-bs-toggle="popover" data-bs-trigger="hover focus"
                                   title="Qualifier Prompt"
                                   data-bs-content="Runs first, when the crawler saves a new project. Sent to OpenAI together with the project description to decide if the project matches your skip-criteria. If it matches, the project is marked Not Qualified and no bid is generated. If the AI call fails, the project is treated as not qualified (fail-closed)."></i>
                            </label>
                            <textarea class="form-control" id="formValidationNegativePrompt"
                                      name="formValidationNegativePrompt" rows="20">{{ $filter->negative_prompt }}</textarea>
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-semibold" for="formValidationPrompt">Step 2 - Proposal Drafting Prompt
                                <i class="bx bx-info-circle text-muted" tabindex="0"
                                   data-bs-toggle="popover" data-bs-trigger="hover focus"
                                   title="Proposal Drafting Prompt"
                                   data-bs-content="Runs after the Qualifier Prompt gate passes. Used as the AI system message to write the bid cover letter for the project."></i>
                            </label>
                            <textarea class="form-control" id="formValidationPrompt" name="formValidationPrompt"
                                      rows="20">{{ $filter->prompt }}</textarea>
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-semibold" for="formValidationSummaryPrompt">Step 3 - Response Allocation Prompt
                                <i class="bx bx-info-circle text-muted" tabindex="0"
                                   data-bs-toggle="popover" data-bs-trigger="hover focus"
                                   title="Response Allocation Prompt"
                                   data-bs-content="Runs when a project fails qualification. Sends the project itself (title and description) to OpenAI to produce a short project summary shown on the Not Qualified page and in bid details."></i>
                            </label>
                            <textarea class="form-control" id="formValidationSummaryPrompt"
                                      name="formValidationSummaryPrompt" rows="20">{{ $filter->summary_prompt }}</textarea>
                        </div>
                    </div>

                    <div class="col-lg-5">
                        <div class="mb-3">
                            <label class="form-label" for="formValidationCountries">Allowed Countries</label>
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

                        <div class="mb-3">
                            <label class="form-label" for="formValidationCurrencies">Allowed Currencies
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

                        <div class="mb-3">
                            <label class="form-label" for="formValidationMinHourlyRate">Min Hourly Project Rate</label>
                            <input type="number" class="form-control" name="formValidationMinHourlyRate"
                                   value="{{ $filter->min_hourly_amount }}"
                                   @if (!$filter->useminhour) disabled @endif />
                        </div>

                        <div class="mb-3">
                            <label class="form-label" for="formValidationMinFixedRate">Min Fixed Project Rate</label>
                            <input type="number" class="form-control" name="formValidationMinFixedRate"
                                   value="{{ $filter->min_fixed_amount }}"
                                   @if (!$filter->useminfix) disabled @endif />
                        </div>

                        <div class="border rounded p-3">
                            <h6 class="fw-semibold mb-3">Control</h6>
                            <div class="mb-3">
                                <label class="switch switch-success mb-0">
                                    <input type="checkbox" class="switch-input" name="formValidationCrawler" value="1"
                                           @if ($filter->crawler_on) checked @endif />
                                    <span class="switch-toggle-slider"></span>
                                    <span class="switch-label fw-semibold">Enable Crawler</span>
                                </label>
                            </div>
                            <div class="d-flex flex-wrap align-items-center gap-4">
                                <label class="switch switch-success mb-0">
                                    <input type="checkbox" class="switch-input" name="useCountries"
                                           @if ($filter->usecountries) checked @endif />
                                    <span class="switch-toggle-slider"></span>
                                    <span class="switch-label">Countries</span>
                                </label>
                                <label class="switch switch-success mb-0">
                                    <input type="checkbox" class="switch-input" name="useminhour"
                                           @if ($filter->useminhour) checked @endif />
                                    <span class="switch-toggle-slider"></span>
                                    <span class="switch-label">Min Hourly Cost</span>
                                </label>
                                <label class="switch switch-success mb-0">
                                    <input type="checkbox" class="switch-input" name="useminfix"
                                           @if ($filter->useminfix) checked @endif />
                                    <span class="switch-toggle-slider"></span>
                                    <span class="switch-label">Min Fixed Cost</span>
                                </label>
                            </div>
                        </div>
                    </div>

                    <div class="col-12 d-flex justify-content-end">
                        <button type="submit" name="submitButton" class="btn btn-primary px-4">
                            <i class="bx bx-save me-1"></i>Save Changes
                        </button>
                    </div>
```

Keep untouched: everything above `@csrf` (toast block, page title, card, form tag) and everything after `</form>`.

Note: the popover `data-bs-content` strings are verbatim copies of the current file; only the two `title` attributes for Steps 2-3 change to match the new labels ("Proposal Drafting Prompt", "Response Allocation Prompt") — this is intended.

- [ ] **Step 4: Run tests**

Run: `php artisan test --filter=FiltersPageCleanupTest`
Expected: PASS (6 tests)

Run: `php artisan test`
Expected: all green except pre-existing ExampleTest failure.

- [ ] **Step 5: Commit (NO co-author trailer)**

```bash
git add resources/views/content/pages/filters.blade.php tests/Feature/FiltersPageCleanupTest.php
git commit -m "feat: two-column filters layout with stepped prompts and control box"
```
