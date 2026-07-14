# Relevance Review Tab — Design

Date: 2026-07-14

## Goal

Add a new sidebar tab "Relevance" where an admin reviews bids one card at a
time and labels each as **relevant**, **irrelevant**, or **scam**. Reviewed
bids disappear from the tab. Cards load on scroll (infinite scroll).

## Data

- New column on `bids`: `admin_feedback` VARCHAR(255) NULL, default NULL.
  - `NULL` = not yet reviewed.
  - Allowed values: `relevant`, `irrelevant`, `scam`.
- Added via Laravel migration.
- Same column added to `db.sql` `CREATE TABLE bids` so a fresh volume
  re-import stays consistent with the migrated schema.
- `Bid` model:
  - Add `admin_feedback` to `$fillable`.
  - Add scope `scopeNeedsFeedback($q)` → `$q->whereNull('admin_feedback')`.

## Routes (inside existing `auth` middleware group)

| Method | URI                  | Action                        | Purpose                              |
|--------|----------------------|-------------------------------|--------------------------------------|
| GET    | `/relevance`         | `BidController@relevance`     | Page shell + first batch of cards    |
| GET    | `/relevance/load`    | `BidController@loadRelevance` | Next page as JSON `{html, hasMore}`  |
| POST   | `/relevance/feedback`| `BidController@storeFeedback` | Save `{bid_id, feedback}`            |

## Query

```php
Bid::needsFeedback()->with('proposal')->latest()->paginate(20)
```

- Page size 20.
- `latest()` = newest first (matches Home).
- Eager-load `proposal` (card reads `proposal.title` + `proposal.description`).

## Controller methods (`BidController`)

- `relevance()` — paginate page 1, render `content.pages.relevance` with the
  paginator.
- `loadRelevance(Request $request)` — paginate requested page, render the card
  partial for each item to an HTML string, return
  `response()->json(['html' => $html, 'hasMore' => $paginator->hasMorePages()])`.
- `storeFeedback(Request $request)` — validate:
  - `bid_id` required, exists in `bids`.
  - `feedback` required, `in:relevant,irrelevant,scam`.
  - Find bid, set `admin_feedback`, save. Return `response()->json(['success' => true])`.
  - Invalid feedback → 422 JSON error.

## Views

- `resources/views/content/pages/relevance.blade.php`
  - Extends `layouts.layoutMaster`.
  - Scroll list of cards (`@each` over first-page items using the partial).
  - Sentinel `<div id="relevance-sentinel">` after the list.
  - Empty state `<div id="relevance-empty">All bids reviewed 🎉</div>` (hidden
    until list empties and no more pages).
  - Inline JS (see Infinite scroll).
- `resources/views/_partials/relevance-card.blade.php`
  - One card, `data-bid-id="{{ $bid->id }}"`.
  - **Title**: `$bid->proposal?->title`.
  - **Project Description**: `$bid->proposal?->description`.
  - Bottom-right button row:
    - Relevant — green (`btn-success`).
    - Irrelevant — yellow (`btn-warning`).
    - Scam — red (`btn-danger`).
  - Reused by both initial render and AJAX load.

## Infinite scroll (jQuery — Sneat theme already ships it)

- `IntersectionObserver` watches `#relevance-sentinel`.
- When visible and not already loading and `hasMore`:
  - `GET /relevance/load?page=N`.
  - Append `response.html` to the list.
  - Update `hasMore` from response; increment page.
- When `hasMore` false and list empty → show empty state, disconnect observer.

## Button click

- Delegated click handler on `.relevance-btn`.
- Reads `data-bid-id` from parent card and `data-feedback` from button.
- POST `/relevance/feedback` with CSRF token (`X-CSRF-TOKEN` meta or hidden
  input).
- On success: fade out + remove the card. If list now empty and `hasMore`
  false → show empty state.
- On error: re-enable buttons, keep card.

## Menu

Add to `resources/menu/verticalMenu.json` after Filters:

```json
{
  "url": "/relevance",
  "name": "Relevance",
  "icon": "menu-icon tf-icons bx bx-check-shield",
  "slug": "relevance"
}
```

Page sets `@section('title', 'Relevance')` and menu slug `relevance` for
active-state highlight.

## Edge cases

- Bid with missing/null proposal → null-safe (`?->`) in the card; render
  "(no project data)" placeholder rather than crash.
- Concurrent review of same bid → last write wins; already-labeled bid simply
  won't reappear on next load (query filters `NULL`).
- CSRF required on POST (standard Laravel `web` group).

## Out of scope (YAGNI)

- Editing/undoing a feedback label after submission.
- Filtering/searching within the Relevance tab.
- Showing already-reviewed bids or their labels anywhere (existing Home table
  unchanged).
