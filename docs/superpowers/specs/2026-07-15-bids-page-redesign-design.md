# Bids Page Redesign — Design Spec

**Date:** 2026-07-15
**Branch:** `statistics`
**Status:** Approved, ready for implementation planning

## Goal

Redesign the `/bids` page into a filterable, auto-refreshing dashboard with
summary cards, Placed/Failed table tabs, and a left slide-over detail panel that
replaces the current full-page bid detail.

## Current State

- Route `GET /bids` → `BidController@index` → `view('content.pages.home', ['bids' => ...])`
  (`Bid::latest()->paginate(100)`). Named route `bids`.
- View `resources/views/content/pages/home.blade.php`: static table (ID, Title,
  Price+country, Status, Type, Time, Review). Review column = eye link (→
  `bids.show`), check-status icon, external Freelancer link, copy-id icon.
  "Expire Pending" button posts to `expire_bids`.
- `bids.show` → `BidController@show` sets `is_seen = true`, renders
  `content/pages/filter_edit.blade.php` (full-page detail + Correct/Incorrect
  buttons posting to `updateBidCheck`, which redirects to `/bids`).
- `Route::resource('bids', BidController::class)->except(['index'])` provides
  `bids.show`, `bids.store`, etc.
- Stack: Laravel 10, Blade, Bootstrap 5.2.3 (offcanvas available), MySQL,
  Eloquent. jQuery is available (used elsewhere).

## Data Facts (locked)

- `Bid.bid_status`: `pending` | `completed` | `failed` | `expired`.
- **Placed** = status in `{pending, completed}`. **Failed** = status in
  `{failed, expired}`.
- `Bid.price` (double) — the amount bid. **Amount filter targets `bids.price`.**
- `Bid.created_at` — **date filter targets this.**
- `Bid.check`: `Unreviewed` | `Correct` | `Incorrect` (review workflow — KEPT).
- `Bid.is_seen` — marked true when detail is viewed.
- `Proposal.type`: `fixed` | `hourly`. `Proposal.title`, `Proposal.project_id`,
  `Proposal.country`, budgets, description.
- **Text search** matches `proposals.title` (contains) OR `proposals.project_id`
  (exact/contains on the numeric id).

## Page Layout (top → bottom)

### 1. Filter bar
- **Date From** / **Date To** (date inputs → `bids.created_at` range).
- **Min amount** / **Max amount** (number → `bids.price` range).
- **Type** select: All / Hourly / Fixed (→ `proposals.type`).
- **Search** text (→ title contains OR project_id).
- Any change re-fetches data via AJAX (search debounced ~400ms).

### 2. Three summary cards
Computed over the full filtered set, **ignoring the active tab**:
- **Total** — count of all filtered bids.
- **Placed** — count where status ∈ {pending, completed}.
- **Failed** — count where status ∈ {failed, expired}.

### 3. Two table tabs
Bootstrap nav-tabs: **Placed Bids** / **Failed Bids**. Active tab filters the
table to that status set (cards stay full-set). Columns: ID (project_id), Title
(truncated 30), Price (`{price}$ - {country}`), Status (badge), Type, Time
(`h:i a` + diffForHumans), **Review**. Review column = a single **View** button
(eye icon) plus a small colored check-status dot. Server-side paginated (100).

Removed from the row: external Freelancer link and copy-id icon (Freelancer link
now lives in the slide-over).

## Left Slide-Over (offcanvas-start)

A **single** reused offcanvas element anchored left (`offcanvas-start`).
- Clicking a row's **View** fetches `GET /bids/{bid}/detail` and fills the panel,
  then opens it. **If already open, swap content in place** (do not close then
  reopen).
- Panel content: check-status badge, Last Updated, Min/Max budget, Quoted
  (`price`), Bid Status (+ `error_message` when failed), Type, Title,
  Description, Coverletter.
- Buttons: **View on Freelancer** (`https://www.freelancer.com/projects/{project_id}`,
  new tab) and **Correct** / **Incorrect**.
- Correct/Incorrect POST to `updateBidCheck` via AJAX; on success update the
  panel badge and the row's check-status dot — no page reload.
- Fetching the detail marks the bid `is_seen = true`.

## Auto-Refresh

Poll every **15s**: re-fetch `/bids/data` with the current tab + filters, replace
the cards and table body. **Skip** the tick when the search input is focused or
the current page > 1 (avoid disrupting typing / paging).

## Backend (extend `BidController`)

| Route | Method | Returns |
|---|---|---|
| `/bids` | `index` | Page shell + initial `bids/data` payload for the default tab (`placed`) |
| `/bids/data` | `data` | JSON `{ cards: {total, placed, failed}, rowsHtml, paginationHtml }` |
| `/bids/{bid}/detail` | `detail` | Offcanvas panel HTML; sets `is_seen = true` |
| `/updateBidCheck` | `updateBidCheck` | JSON `{ success, check }` (was redirect) |

**`data` query params:** `tab` (placed|failed, default placed), `from`, `to`,
`min`, `max`, `type` (fixed|hourly|all), `q`, `page`.

**Query building (shared scope):** base query joins `proposals`; applies date
range on `bids.created_at`, price range on `bids.price`, `proposals.type`,
and search (`proposals.title LIKE %q%` OR `proposals.project_id LIKE %q%`).
Cards are computed from this filtered base (three counts). The table then adds
the tab's status filter and paginates. Rows rendered via a
`_partials.bid-row` blade; pagination via the existing bootstrap-5 paginator.

**Routes:** add `GET /bids/data` (name `bids.data`) and
`GET /bids/{bid}/detail` (name `bids.detail`) inside the `auth` group, before
the `Route::resource('bids', ...)->except(['index'])` line.

## New / Changed Files

- `resources/views/content/pages/home.blade.php` — rewritten into the new Bids
  dashboard (filter bar, cards, tabs, table container, offcanvas, page-script JS).
- `resources/views/_partials/bid-row.blade.php` — one table row (new).
- `resources/views/_partials/bid-detail.blade.php` — offcanvas panel body (new).
- `app/Http/Controllers/BidController.php` — add `data()`, `detail()`; rework
  `index()` to render the new shell; change `updateBidCheck()` to return JSON.
- `routes/web.php` — add `bids.data`, `bids.detail` routes.
- `filter_edit.blade.php` and `show()` remain (no longer linked from the row);
  not removed to avoid scope creep.

## Error Handling

- Invalid/absent filter params: treated as no-filter (dates parsed defensively;
  non-numeric min/max ignored). `type` validated against allow-list.
- Empty result set: cards show 0, table shows an empty-state row.
- Detail for a missing bid id: 404.
- Auto-refresh fetch failure: keep the last-rendered data, retry next tick.

## Testing (feature tests, SQLite, RefreshDatabase)

- `/bids/data`: date/price/type/search filters each narrow results; placed vs
  failed tab returns the correct status set; card counts (total/placed/failed)
  are correct and independent of the active tab; pagination works.
- `/bids/{bid}/detail`: returns the bid's content and sets `is_seen = true`;
  404 for unknown id.
- `updateBidCheck`: returns JSON `{success:true, check:...}` and persists the
  `check` value.
- Auth: all three endpoints require auth (JSON → 401).

## Out of Scope

- No change to the crawler, statistics dashboard, or bid-status semantics.
- The `check` field and `filter_edit`/`show` remain in the codebase (unused by
  the new row) — not deleted.
- No websockets; auto-refresh is interval polling.
