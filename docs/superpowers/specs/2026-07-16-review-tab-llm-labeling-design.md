# Review Tab — Bid Qualification Labeling (LLM Training Data) — Design

**Date:** 2026-07-16
**Branch:** statistics_and_bids
**Status:** Approved design, pending spec review

## Purpose

Collect human labels on crawled projects to build a training dataset for an
LLM/classifier that will eventually auto-qualify bids. A reviewer sweeps through
project descriptions and tags each **Relevant**, **Not Relevant Skill**, or
**Scam**. Each click persists a label and removes the card, so labeling is a fast
one-at-a-time loop.

This is a **new, isolated feature**. The existing Relevance page stays untouched
as a working backup — no Relevance code, routes, views, or the `bids.admin_feedback`
column are modified. The whole Review feature can be reverted by dropping one
column and deleting the new files.

## Scope

### In scope (Phase 1)
- New **Review** page with two sub-tabs: **Old Projects** and **New Projects**.
- Rapid labeling UI: one card per project, three buttons, card disappears on click,
  infinite scroll.
- Labels stored on a new `proposals.review_label` column.
- Only projects already stored in the DB (i.e. projects that passed all filters —
  bid-placed or pending). These are all `Proposal` rows.

### Explicitly NOT in scope (Phase 1)
- **Capturing filtered-out / not-shortlisted projects.** Today `getProposals()`
  discards rejected projects (country / negative-keyword / NDA / sealed / min-budget)
  with `continue` *before* `$proposal->save()`, so there is no data for them. Adding
  that capture is deferred to a later phase. `getProposals()` is not modified here.
- Model training, embeddings, or inference.
- Any change to the Relevance page or `bids.admin_feedback`.

## Data Model

### Migration
Add a nullable column to `proposals`:

- `review_label` — `string`, nullable, default `null`.
  - Allowed values: `relevant`, `not_relevant_skill`, `scam`.
  - `null` = not yet reviewed (in the labeling queue).

Revert = drop this one column.

### Proposal model
- Add `review_label` to `$fillable` (or ensure mass-assignment works; direct
  assignment + `save()` is fine and matches existing style).
- Add scope:
  ```php
  public function scopeNeedsReview($query)
  {
      return $query->whereNull('review_label');
  }
  ```

No change to the `bids` table or the `Bid` model.

## Backend

New `ReviewController` (separate from `BidController` for isolation).

### Routes (web.php, inside the existing auth group)
```php
Route::get('/review', [ReviewController::class, 'index'])->name('review');
Route::get('/review/load', [ReviewController::class, 'load'])->name('review.load');
Route::post('/review/feedback', [ReviewController::class, 'storeFeedback'])->name('review.feedback');
```

### Old vs New split (rolling window)
- Window constant: `NEW_WINDOW_DAYS = 7` (private const on the controller;
  single place to change).
- **New Projects**: `proposals.created_at >= now()->subDays(7)`.
- **Old Projects**: `proposals.created_at < now()->subDays(7)`.
- `created_at` = when we crawled/stored the project → represents the day-to-day
  incoming stream. (Not `project_added_time`, which is Freelancer's submit time.)

### `index()`
- Returns the Review view.
- Provides initial data for the default tab (New) plus the unlabeled counts for
  both tabs (for the tab-header badges): `Proposal::needsReview()` filtered by each
  window.

### `load(Request)` — infinite scroll / cursor
- Query params: `tab` (`old` | `new`, default `new`), `after_id` (optional cursor).
- Base query: `Proposal::needsReview()` + the tab's window filter,
  `orderByDesc('id')`.
- Cursor: if `after_id` present, `where('id', '<', after_id)`.
- Fetch `limit + 1` (e.g. 21) to compute `hasMore`; return 20.
- Render each via `_partials.review-card` → return JSON `{ html, hasMore }`.
- Mirrors the existing `loadRelevance()` pattern so behavior is proven.

### `storeFeedback(Request)`
- Validate:
  - `proposal_id` — required, `exists:proposals,id`. This is the proposal's
    primary key (NOT the Freelancer `project_id`), to keep the lookup unambiguous.
  - `label` — required, `in:relevant,not_relevant_skill,scam`.
- Find the proposal by id, set `review_label`, save.
- Return `{ success: true }`.

## Frontend

### Sidebar
Add a **Review** nav item (alongside Relevance) in the layout menu. Isolated entry.

### `review.blade.php`
- Page title "Review".
- Two sub-tabs (Bootstrap nav): **Old Projects** / **New Projects**, each showing a
  remaining-count badge. New is the default active tab.
- A scroll container holding cards, plus a sentinel element for infinite scroll.
- Page script: on tab switch, reset the list and load that tab from the top; on
  scroll-to-sentinel, load the next page with the `after_id` cursor. Clone the
  Relevance page's fetch/observer logic (isolated copy, not shared).

### `review-card.blade.php`
- Shows: **title**, **full description**, **skills** (badges), **budget + type**,
  **country**.
- Three action buttons: **Relevant** (green), **Not Relevant Skill** (grey/warning),
  **Scam** (red).
- On click: POST `/review/feedback` with `{ proposal_id: <proposal.id>, label }`;
  on success, fade the card out and remove it; when the list runs low, the observer
  loads more. Decrement the active tab's count badge.
- Empty state text when a tab has no more unlabeled projects.

## Data Flow

1. Reviewer opens `/review` → New Projects tab loads first page of unlabeled
   proposals (created in last 7 days), plus both count badges.
2. Reviewer clicks a label on a card → POST saves `review_label` → card fades out.
3. Scrolling loads more via `after_id` cursor until `hasMore` is false → empty state.
4. Switching to Old Projects loads unlabeled proposals older than 7 days, same loop.
5. Labeled proposals (`review_label` not null) drop out of the queue permanently.

## Error Handling
- `storeFeedback` validation failure → 422 with messages; card stays, surface a
  small inline error (reuse Relevance's approach).
- Network error on POST → keep the card, allow retry (do not remove on failure).
- Empty tab → friendly empty state, no error.

## Testing
- Migration up/down (column added/dropped cleanly).
- `storeFeedback`: valid label persists; invalid label → 422; unknown id → 422.
- `load`: New vs Old window boundary (a proposal exactly at the 7-day edge lands in
  the expected tab); cursor pagination returns distinct pages; `hasMore` correct;
  labeled proposals excluded.
- Isolation check: Relevance queue/counts unchanged after Review labeling (they read
  different columns).

## Revert Plan
1. Drop `proposals.review_label` (migration down).
2. Delete `ReviewController`, the three routes, `review.blade.php`,
   `review-card.blade.php`, and the sidebar item.
Relevance and all bid flows remain exactly as before.

## Future Phases (not built now)
- Capture filtered-out projects (persist rejects with a reason) to provide the
  negative-class examples the training set needs.
- Export labeled dataset; train Path-B embeddings + classifier; wire predictions
  back into the bid flow.
