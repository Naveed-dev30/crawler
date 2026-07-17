# Bid Feedback + Not-Qualified Classification — Design

**Date:** 2026-07-17
**Status:** Approved design (from plan-mode session), pending build

## Purpose

Give the operator leverage to analyze bidding decisions. Today the negative-prompt
gate (`ProposalQualifier::qualify()`) returns only `true`/`false`; on reject the
proposal is silently skipped (no reason recorded), and qualified bids carry no
rationale. This feature surfaces **why** a proposal qualified or was rejected,
adds an optional **AI summary** of that reason, and gives gate-rejected proposals
their own **admin tab**.

## Key structural fact

The `Proposal` row is created in `ProposalController::getProposals()` **before**
the gate runs (the gate lives in `OpenAIJob`, which on reject only skips creating
the `Bid`). So the proposal already persists — "Not Qualified" tracking is new
columns + a flag on the existing proposal, not a new persistence path.

## Scope

### In scope
- `ProposalQualifier::qualify()` returns `['qualified' => bool, 'reason' => string]`.
- New `proposals` columns: `qualified` (nullable bool), `qualify_reason` (text),
  `qualify_summary` (text). New `filters` column: `summary_prompt` (text).
- `OpenAIJob` persists `qualified` + `qualify_reason`; dispatches a summary job
  when a summary prompt is set.
- New `SummarizeReasonJob` — OpenAI call, gated on `filters.summary_prompt`,
  writes `qualify_summary`.
- New Filters input **Summary Prompt** (mirrors Negative Prompt).
- New admin **Not Qualified** page + sidebar entry listing proposals where
  `qualified = false`, showing reason (bold) + summary (or "No summary available").
- Bid detail/row show the same reason + summary from the proposal relation.

### Out of scope
- Bids-section tab changes (single Failed tab stays; no skill-vs-failed split).
- Backfilling old proposals (they stay `qualified = null`).
- Mobile API (v1) — web admin only.

## Data model

Migration — `proposals` (all nullable, **no backfill**):
- `qualified` — `boolean`, nullable, default `null` (`null` = gate didn't run,
  `true` = passed, `false` = rejected).
- `qualify_reason` — `longText`, nullable.
- `qualify_summary` — `longText`, nullable.

Migration — `filters`:
- `summary_prompt` — `longText`, nullable, after `negative_prompt`.

`Proposal` model: cast `qualified` => `boolean`; scope
`scopeNotQualified($q) => $q->where('qualified', false)` (strict false excludes
`null`, so old proposals never appear).

## Qualifier contract

`ProposalQualifier::qualify(string $negativePrompt, string $description): array`
returns `['qualified' => bool, 'reason' => string]`.

- System prompt asks the model to reply with a JSON object
  `{"qualified": <true|false>, "reason": "<short reason>"}` — `qualified=false`
  when the description matches the negative criteria (skip), `true` otherwise.
- Parse: strip markdown fences, `json_decode`, read boolean `qualified` and string
  `reason`. Retry once on HTTP error / unparseable.
- **Fail-closed:** any error or unparseable reply after retries →
  `['qualified' => false, 'reason' => '']` (skip). Never throws.

## Pipeline change (`OpenAIJob`)

When `negative_prompt` is set:
1. `$r = qualify($negative, $description)`.
2. `$proposal->qualified = $r['qualified']; $proposal->qualify_reason = $r['reason']; save();`
3. If `filters.summary_prompt` is non-empty AND `$r['reason'] !== ''` →
   `SummarizeReasonJob::dispatch($proposal)` (runs for both qualified and rejected).
4. If `! $r['qualified']` → log + return (skip bid, as today).
5. Else continue to cover-letter + bid creation (unchanged).

When `negative_prompt` is empty: unchanged behavior; `qualified` stays `null`.

## Summary job (`SummarizeReasonJob`)

- Constructed with a `Proposal`. On `handle()`:
  - Load `Filter::find(1)`; if `summary_prompt` empty → return (gated).
  - If `proposal->qualify_reason` empty → return.
  - OpenAI `gpt-3.5-turbo` call: system = `summary_prompt`, user = the reason.
  - On success, store trimmed content in `proposal->qualify_summary`; save.
  - Never throws (log + swallow on error).

## Filters UI

- Add a **Summary Prompt** `<textarea name="formValidationSummaryPrompt">` under
  the Negative Prompt field in `filters.blade.php`, bound to
  `$filter->summary_prompt`.
- `FilterController::update()`: `$filter->summary_prompt = $request->formValidationSummaryPrompt ?? '';`

## Not Qualified page

- Route (auth group): `GET /not-qualified` → `NotQualifiedController@index`.
- Sidebar entry in `verticalMenu.json` (e.g. icon `bx bx-block`, slug
  `not-qualified`).
- `index()`: `Proposal::notQualified()->latest()->paginate(50)` → view.
- View `content/pages/not-qualified.blade.php`: table of project_id, title,
  **reason** (bold), summary (`qualify_summary` or "No summary available"),
  created_at, Freelancer link. Empty state when none.

## Bid display

In `_partials/bid-detail.blade.php` (and a compact line in `_partials/bid-row.blade.php`):
show, when `$bid->proposal->qualify_reason` is present, a "Qualification" block —
bold reason + summary (or "No summary available"). Hidden for proposals with no
reason (old bids).

## Error handling

- Qualifier and summary job never throw; fail-closed / log-and-skip.
- No 500 on missing summary prompt / reason (guards return early).
- Old proposals (`qualified = null`) excluded from the tab and show no
  qualification block.

## Testing

- `ProposalQualifier`: JSON reply → `['qualified'=>true,'reason'=>...]` and
  `false` variants; HTTP error → fail-closed `['qualified'=>false,'reason'=>'']`;
  system prompt contains the negative prompt.
- `SummarizeReasonJob`: with summary prompt + reason → writes `qualify_summary`;
  no summary prompt → no write; no reason → no write.
- `OpenAIJob`: gate false → proposal flagged `qualified=false` + reason, no bid
  created, summary job dispatched when prompt set; gate true → proposal
  `qualified=true`, bid created.
- Filters: `summary_prompt` persists via `/updateFilters`.
- Not Qualified page: lists `qualified=false` only (excludes `null`/`true`);
  requires auth.
- Bid detail: renders reason + summary when present.

## Revert

Roll back the two migrations (drop the four columns), delete
`SummarizeReasonJob`, `NotQualifiedController`, the not-qualified view, the route
+ sidebar entry, and revert `ProposalQualifier`, `OpenAIJob`, `FilterController`,
`filters.blade.php`, and the bid partials.
