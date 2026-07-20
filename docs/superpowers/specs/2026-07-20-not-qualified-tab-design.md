# Not Qualified as Bids Tab — Design

**Date:** 2026-07-20
**Status:** Approved (pending spec review)

## Goal

Move the Not Qualified listing from a standalone sidebar page into the Bids page as a fourth tab (Placed Bids / Completed Bids / Failed Bids / **Not Qualified**). Standalone page removed entirely (user decision).

## Approach

Extend the existing AJAX endpoint `GET /bids/data` (which already returns `{rowsHtml, paginationHtml}` per tab) with `tab=not-qualified`. That branch serves **Proposal** rows (`qualified = false`) instead of Bid rows.

## Changes

### Front end — `resources/views/content/pages/home.blade.php`

- Tab nav (`#bids-tabs`): add after Failed Bids:
  `<li class="nav-item"><button class="nav-link" data-tab="not-qualified" type="button">Not Qualified</button></li>`
- JS already sends `currentTab` as `tab` param and reloads — no change to the fetch flow.
- Table header swap: when `currentTab === 'not-qualified'`, thead shows `Project | Title | Reason | Summary | When | Link`; otherwise the existing bid thead. Implement by toggling between two `<tr>` variants in the thead (hidden via JS or re-rendered on tab switch).
- Filters: on the not-qualified tab, hide bid-specific filter controls (type/price/date/status) — search input stays and filters proposal title. Simplest: wrap bid-only filters in a container that JS hides when the tab is active. Params sent anyway are ignored server-side for this tab.

### Back end — `app/Http/Controllers/BidController.php` (`data` method)

- Accept `not-qualified` in the tab whitelist.
- Branch before the bid query: `Proposal::notQualified()->orderByDesc('created_at')`, optional `search` on title (`like %term%`), `paginate(50)`.
- Render rows via new partial `resources/views/_partials/not-qualified-row.blade.php`; return the same JSON shape `{rowsHtml, paginationHtml}` (+ whatever keys the bid branch already returns — keep `cards` counts unchanged/omitted for this tab as the current front end tolerates).

### New partial — `_partials/not-qualified-row.blade.php`

Columns per row (same data as the old page):
- `project_id`
- title (`Str::limit(..., 40)`)
- `qualify_reason` (bold; "—" when empty)
- `qualify_summary` (italic; "No summary available" when empty)
- `created_at->diffForHumans()`
- external link to the Freelancer project (`seo_url`-based, `target="_blank" rel="noopener"`), matching the old page's link

### Removals

- Route `GET /not-qualified` (routes/web.php)
- `app/Http/Controllers/NotQualifiedController.php`
- `resources/views/content/pages/not-qualified.blade.php`
- Sidebar entry `Not Qualified` in `resources/menu/verticalMenu.json`

## Error handling

Not-qualified branch mirrors bid branch behavior: empty result → empty rowsHtml + pagination of empty paginator (front end already renders an empty state or empty tbody as today).

## Testing

1. `GET /bids/data?tab=not-qualified` returns only `qualified = false` proposals (qualified true/null excluded), newest first; rowsHtml contains title, reason, summary.
2. Search param filters by title on the tab.
3. Missing summary renders "No summary available".
4. Existing bid tabs unaffected (`BidsDataTest` stays green).
5. `/not-qualified` now 404s.
6. Bids page HTML contains the fourth tab button (`data-tab="not-qualified"`).
7. Old `NotQualifiedPageTest` deleted (superseded by the above).

## Out of scope

- Qualification logic, summary job, proposal model — untouched.
- Mobile API — untouched.
