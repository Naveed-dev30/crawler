# Filters Cleanup: Remove Keywords, Rename to Qualifier Prompt, Info Popovers — Design

**Date:** 2026-07-20
**Status:** Approved (pending spec review)

## Goal

1. Remove Keywords and Negative Keywords from the Filters feature — UI and server logic. Database tables and model files stay (code-only removal, user decision); data becomes inert.
2. Rename the "Negative Prompt" UI label to **"Qualifier Prompt"** and move the field above Prompt. DB column stays `negative_prompt` (label-only rename, user decision).
3. Add info icons (Bootstrap popover, hover/focus, HTML body) to the three prompt titles explaining when each prompt runs and what it does.
4. Cleanup: delete the dead `GET /api/filters` route (`routes/api.php:30` targets `FilterController::getFilters`, which does not exist — route is already broken).

## UI changes — `resources/views/content/pages/filters.blade.php`

- New field order in the form: Countries / Currencies / Min rates (unchanged) → **Qualifier Prompt** → **Prompt** → **Summary Prompt** → Crawler Enabled.
- Delete entirely: Negative Keywords Tagify input, Keywords Tagify input (`TagifyBasic`), the deprecated Keywords multi-select (`formValidationKeywords`), the `usekeywords` toggle switch, Tagify vendor script/css includes and init JS.
- Prompt labels get an info icon:

```blade
<label class="form-label" for="formValidationNegativePrompt">Qualifier Prompt
    <i class="bx bx-info-circle text-muted" tabindex="0"
       data-bs-toggle="popover" data-bs-trigger="hover focus" data-bs-html="true"
       title="Qualifier Prompt"
       data-bs-content="..."></i>
</label>
```

- Page script initializes popovers: `document.querySelectorAll('[data-bs-toggle=&quot;popover&quot;]').forEach(el => new bootstrap.Popover(el));`

### Popover contents

- **Qualifier Prompt:** "Runs first, when the crawler saves a new project. Sent to OpenAI together with the project description to decide if the project matches your skip-criteria. If it matches, the project is marked Not Qualified and no bid is generated. If the AI call fails, the project is treated as not qualified (fail-closed)."
- **Prompt:** "Runs after the Qualifier Prompt gate passes. Used as the AI system message to write the bid cover letter for the project."
- **Summary Prompt:** "Runs only when a project fails qualification and a rejection reason was recorded. Rewrites the raw reason into a short summary shown on the Not Qualified page and in bid details."

Form field `name` attributes for prompts are unchanged (`formValidationPrompt`, `formValidationNegativePrompt`, `formValidationSummaryPrompt`) — no controller/request key changes.

## Server logic changes

- `app/Http/Controllers/FilterController.php`
  - `index()`: stop querying `Keyword` / `NegativeKeyword` / building `tagsValue`; stop passing `keywords`, `negKeywords`, `tagsValue` to the view. Remove now-unused imports.
  - `update()`: delete the keyword create/delete blocks, negative-keyword create/delete blocks, keyword pivot attach/sync, and the `usekeywords` flag save. Prompts, countries, currencies, rates, crawler-enabled handling unchanged.
- `app/Http/Controllers/ProposalController.php` (`getProposals`)
  - Remove the `usekeywords`-gated block that appends the keyword search query param to the Freelancer API call.
  - Remove `NegativeKeyword::pluck('name')` and both `Str::contains` skip checks (title and description). The Qualifier Prompt (AI gate in OpenAIJob) remains the only content-based gate.
  - Remove now-unused imports.
- `app/Models/Filter.php`: remove `keywords()` and `negativeKeywords()` relations.
- `routes/api.php`: delete the `Route::get('filters', ...)` line (dead route).
- Untouched: `Keyword` / `NegativeKeyword` model files, all keyword-related migrations/tables, `filters.usekeywords` column (inert), `negative_prompt` column and every consumer of it (`OpenAIJob`, `ProposalQualifier`).

## Error handling

No new failure paths. Removal only narrows behavior: crawler no longer filters by keyword lists; qualification flow unchanged. Popovers are display-only.

## Testing

1. Filters page (admin) renders: "Qualifier Prompt" appears before "Prompt" (`assertSeeInOrder(['Qualifier Prompt', 'Prompt', 'Summary Prompt'])`); page does not contain "Negative Keywords" or the Tagify inputs.
2. Popover attributes present for all three prompts (assertSee `data-bs-toggle="popover"` thrice or per-label content snippets).
3. `POST /updateFilters` without any keyword payload saves prompts/rates fine (existing FilterNegativePromptSaveTest / FilterSummaryPromptSaveTest stay green — field names unchanged).
4. Crawler: proposal whose title/description contains a term from the `negative_keywords` table is no longer skipped (unit-level: the skip code is gone; assert via existing crawler test adjustments if they reference keywords, else add none — YAGNI).
5. Full suite green (known pre-existing ExampleTest failure excepted).

## Out of scope

- Dropping keyword tables/models (kept per user decision).
- Renaming `negative_prompt` DB column or request field names.
- Any change to qualification, summary, or bid-generation behavior.
