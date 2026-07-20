# Filters Form UI Polish — Design

**Date:** 2026-07-20
**Status:** Approved (pending spec review)
**Depends on:** `2026-07-20-filters-cleanup-design.md` (implemented on branch `filters-and-ui-modifications`)

## Goal

Restructure the Filters form into a sectioned layout: criteria fields with their enable-switches inline, full-width prompt textareas, and a footer with a Crawler Enabled switch + Submit. Blade-only change — no controller, route, or field-name changes.

## Layout

```
Set Crawler Filter
1. PROJECT CRITERIA
Countries [switch]         | Currencies (locked)
[select]                   | [select disabled]
Min Hourly Rate [switch]   | Min Fixed Rate [switch]
[input]                    | [input]

2. AI PROMPTS
Qualifier Prompt (i)   [full-width textarea rows=4]
Prompt (i)             [full-width textarea rows=4]
Summary Prompt (i)     [full-width textarea rows=4]
----------------------------------------------------
[Crawler Enabled switch]                    [Submit]
```

## Details

- **Section headers:** existing style — `<h6 class="mt-2 fw-normal">1. Project Criteria</h6><hr class="mt-0"/>` and `2. AI Prompts` before the prompt block.
- **Inline switches:** compact `switch switch-success switch-sm` placed in the label row (`d-flex justify-content-between align-items-center`) of Countries (`name="useCountries"`), Min Hourly Rate (`name="useminhour"`), Min Fixed Rate (`name="useminfix"`). Checked state from `$filter->usecountries` / `useminhour` / `useminfix` as before. The old bottom toggles row is removed. Inputs keep their server-rendered `disabled` attribute behavior.
- **Currencies:** unchanged select, still `disabled`; label gets muted `<small class="text-muted">(locked)</small>`.
- **Prompts:** each in `col-12`, `rows="4"`; labels, popover icons/copy, textarea ids/names, and order (Qualifier → Prompt → Summary) exactly as current.
- **Footer:** after an `<hr/>`, `d-flex justify-content-between align-items-center`: left a `switch switch-success` labeled "Crawler Enabled" with `name="formValidationCrawler" value="1"` checked from `$filter->crawler_on` (replaces the plain checkbox — same submitted semantics: present when on, absent when off; controller already treats truthy/absent); right the Submit button (`btn btn-primary`).
- **Unchanged:** form action/method/csrf, all field names, popover init script, vendor includes, controller, routes, tests' assumptions.

## Behavior notes

- Switch inputs are checkboxes — submit `on` when checked, absent when not, identical to the removed toggle row (`$request->useCountries == "on"`) and to `formValidationCrawler` truthy check. No controller change needed.
- No new JS. Enabling/disabling inputs still takes effect after save (server-rendered `disabled`), as today.

## Testing

Extend `tests/Feature/FiltersPageCleanupTest.php`:
1. Page shows section headings in order: "1. Project Criteria", "2. AI Prompts" (`assertSeeInOrder`).
2. Inline switch names present: `name="useCountries"`, `name="useminhour"`, `name="useminfix"`, `name="formValidationCrawler"` (raw assertions).
3. All existing assertions stay green (prompt order, popover copy, no keyword remnants, save flow).

## Out of scope

- Controller/server changes, live JS enable/disable of fields, currencies unlock, any styling framework changes.
