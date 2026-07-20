# Filters Two-Column Layout — Design

**Date:** 2026-07-20
**Status:** Approved (pending spec review)
**Depends on:** `2026-07-20-filters-form-ui-design.md` (implemented)

## Goal

Restructure the Filters form into two columns per user sketch: a stepped AI-prompts column and a criteria column with a Control box, footer Save Changes button. Blade + tests only; field names, controller, and DB untouched.

## Layout

```
Set Crawler Filter
┌───────────────────────────────┬──────────────────────────┐
│ Step 1 - Qualifier Prompt (i) │ Allowed Countries        │
│ [textarea rows=20]            │ [select]                 │
│ Step 2 - Proposal Drafting    │ Allowed Currencies (lock)│
│ Prompt (i)                    │ [select disabled]        │
│ [textarea rows=20]            │ Min Hourly Project Rate  │
│ Step 3 - Response Allocation  │ [input]                  │
│ Prompt (i)                    │ Min Fixed Project Rate   │
│ [textarea rows=20]            │ [input]                  │
│                               │ ┌─ Control ────────────┐ │
│                               │ │ Enable Crawler  [sw] │ │
│                               │ │ [sw]Countries [sw]Min│ │
│                               │ │ Hourly [sw]Min Fixed │ │
│                               │ └──────────────────────┘ │
└───────────────────────────────┴──────────────────────────┘
                                    [ Save Changes ]
```

## Details

- Columns: `col-lg-7` (prompts) / `col-lg-5` (criteria); stack on <lg.
- **Step labels** (fw-semibold, popover icons preserved with existing copy):
  - "Step 1 - Qualifier Prompt" → textarea `formValidationNegativePrompt`, `rows="20"`.
  - "Step 2 - Proposal Drafting Prompt" → textarea `formValidationPrompt`, `rows="20"`.
  - "Step 3 - Response Allocation Prompt" → textarea `formValidationSummaryPrompt`, `rows="20"` (summary_prompt column; behavior unchanged — user decision: rename only).
- **Criteria labels renamed:** "Allowed Countries", "Allowed Currencies (locked)", "Min Hourly Project Rate", "Min Fixed Project Rate". Field names unchanged.
- **Control box:** bordered rounded panel titled "Control". Row 1: full-size switch `formValidationCrawler` labeled "Enable Crawler". Row 2: three switches left-aligned with labels Countries / Min Hourly Cost / Min Fixed Cost (`useCountries`, `useminhour`, `useminfix`). "Apply criteria" strip and old footer removed.
- **Footer:** `col-12`, right-aligned primary button `Save Changes` (type submit, save icon).
- Sections "Project Criteria"/"AI Prompts" headers with icons/subtitles: replaced by the two-column structure; keep the card header "Set Crawler Filter".
- Toast on save, popover init, vendor includes unchanged.

## Testing

Update `FiltersPageCleanupTest`:
- Order assertion becomes `assertSeeInOrder(['Step 1 - Qualifier Prompt', 'Step 2 - Proposal Drafting Prompt', 'Step 3 - Response Allocation Prompt'])`.
- Assert renamed criteria labels ("Allowed Countries", "Min Hourly Project Rate") and "Enable Crawler" + "Save Changes".
- Keyword-absence, popover-copy, save-flow, toast tests unchanged and stay green.

## Out of scope

Controller/DB/field-name changes; popover copy changes; currencies unlock.
