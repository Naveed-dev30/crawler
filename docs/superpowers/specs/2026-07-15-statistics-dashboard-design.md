# Statistics Dashboard Redesign — Design Spec

**Date:** 2026-07-15
**Branch:** `statistics`
**Status:** Approved, ready for implementation planning

## Goal

Replace the current single-line Stats chart (bids/day, past year) with an
interactive Statistics dashboard containing six analytics sections covering bid
outcomes, project value, 24-hour snapshots, and geographic distribution.

## Current State

- Route: `GET /stats` → `BidController::stats()` (`routes/web.php:45`).
- View: `resources/views/content/pages/stats.blade.php` — one ApexCharts line
  chart, data injected server-side via `$stats` array.
- Data: bids grouped by `DATE(created_at)`, filtered `is_seen = 1`, past year;
  empty days zero-filled via a calendar loop.
- Stack: Laravel 10, Blade, ApexCharts 3.28.5, MySQL, Eloquent.

## Data Model Facts

- `Bid.bid_status`: `pending` | `completed` | `failed` | `expired`.
- `Bid.proposal_id` → `Proposal` (belongsTo). `Proposal` hasOne `Bid`.
- `Proposal.type`: `fixed` | `hourly` (populated at crawl,
  `ProposalController.php:234`).
- `Proposal.min_budget`: fixed = min price; hourly = hourly rate.
- `Proposal.currency_name` / `currency_symbol`: native currency (NOT USD).
- `Proposal.country`: populated at crawl from API `currency.country`.
- No skills stored. No exchange rate stored. No award field.

## Decisions (locked during brainstorming)

1. **Bid category mapping** (bid_status only, no bid-table change):
   - qualified = `pending`
   - successful = `completed`
   - failed = `failed` + `expired`
2. **Awarded** = bid `completed`. **Skills** captured from API `jobs[]` into a
   new `proposals.skills` column, going forward only (no backfill).
3. **USD conversion**: capture API `currency.exchange_rate` into a new
   `proposals.exchange_rate` column at crawl time. USD value = budget × rate.
   Rows without a rate fall back to rate `1` (treated as already-USD).
4. **Hourly project value** = `min_budget × 10` (hourly rate × 10).

## Schema Changes

One migration adding two nullable columns to `proposals`:

- `skills` — `json`, nullable. Array of skill names from API `jobs[]`.
- `exchange_rate` — `double`, nullable, default `1`. Native→USD multiplier.

No changes to the `bids` table.

## Crawler Changes (`ProposalController::getProposals`)

Where the proposal is built (~line 213–269), additionally set:

- `$proposal->exchange_rate = $project['currency']['exchange_rate'] ?? 1;`
- `$proposal->skills = collect($project['jobs'] ?? [])->pluck('name')->values();`
  (store as JSON array; `Proposal` casts `skills` → `array`).

Add `protected $casts = ['skills' => 'array'];` to the `Proposal` model.

## Sections / Features

### 1. Fixed Bids Outcome Chart
Multi-series chart (qualified / successful / failed) over time, filtered
`Proposal.type = 'fixed'`. Granularity toggle: **hourly / daily / weekly /
monthly**. Optional date range.

### 2. Hourly Bids Outcome Chart
Identical to §1, filtered `Proposal.type = 'hourly'`.

### 3. All Bids Outcome Chart
Identical to §1, no type filter (fixed + hourly combined).

### 4. Project Value Chart
Two series over date (with date-range filter + granularity): **placed value**
vs **failed value**, in USD.
- placed = bids where status ∈ {pending, completed} (qualified + successful).
- failed = bids where status ∈ {failed, expired}.
- per-project value = `min_budget × exchange_rate`, then `× 10` if
  `type = 'hourly'`. ("minimum value" per requirement.)

### 5. Last-24h Snapshot Cards
Three stat cards for proposals with `created_at >= now()-24h`:
- **Value posted (USD)** — Σ project USD value of all such proposals.
- **Value awarded (USD)** — Σ USD value where the proposal's bid is `completed`.
- **Skills awarded** — skill frequency across completed proposals (bar/list from
  `skills`). New-data-only; empty until crawler populates skills.

### 6. Top 10 Countries
Horizontal bar / list: project count grouped by `Proposal.country`, ordered
desc, limit 10. Date-range filter.

## Backend — `StatisticsController`

New controller. JSON endpoints registered in `routes/web.php` (share existing
web/session/role auth), under a `/stats/...` prefix. `GET /stats` continues to
render the dashboard view.

| Endpoint | Params | Returns |
|---|---|---|
| `GET /stats/bids` | `type` (fixed\|hourly\|all), `granularity`, `from`, `to` | `[{ bucket, qualified, successful, failed }]` |
| `GET /stats/value` | `granularity`, `from`, `to` | `[{ bucket, placed_usd, failed_usd }]` |
| `GET /stats/last24h` | — | `{ value_posted_usd, value_awarded_usd, skills: [{ name, count }] }` |
| `GET /stats/countries` | `from`, `to` | `[{ country, count }]` (≤10) |

**Bucketing:** MySQL `DATE_FORMAT(created_at, fmt)` where fmt per granularity:
- hourly `%Y-%m-%d %H:00`
- daily `%Y-%m-%d`
- weekly `%x-W%v` (ISO year-week)
- monthly `%Y-%m`

Zero-fill missing buckets across the requested range (reuse the calendar-fill
pattern from the existing `stats()`), generalized per granularity.

**Joins:** bid endpoints join `proposals` for `type`; value endpoint joins for
`min_budget`, `type`, `exchange_rate`.

**Defaults:** granularity `daily`; date range last 30 days when `from`/`to`
omitted. Validate `type` and `granularity` against allow-lists.

## Frontend

Rework `resources/views/content/pages/stats.blade.php`:
- Bootstrap cards: 4 chart cards (§1–4), one skills/snapshot card (§5), one
  countries card (§6).
- Controls: granularity button group + date pickers per applicable chart.
- New `resources/assets/js/statistics.js`: fetch endpoints, render ApexCharts,
  re-fetch + re-render on control change. Registered via Laravel Mix
  (`webpack.mix.js`) following existing `bids_stats.js` pattern.

## Error Handling

- Empty ranges → zero-filled buckets (charts render empty, not broken).
- Missing `exchange_rate` → treated as `1`.
- Missing/empty `skills` → excluded from §5 breakdown.
- Invalid `type`/`granularity` params → `422` with validation message.

## Testing

Feature tests (one per endpoint) using `Bid`/`Proposal` factories seeded across
type, status, currency, and date:
- `/stats/bids`: correct per-bucket qualified/successful/failed counts; type
  filter isolates fixed vs hourly vs all.
- `/stats/value`: USD sums correct incl. `× exchange_rate` and hourly `× 10`;
  placed vs failed split.
- `/stats/last24h`: only last-24h proposals counted; awarded = completed only;
  skills aggregated.
- `/stats/countries`: top-10 ordering + date filter.

Create `ProposalFactory` and `BidFactory` if absent.

## Out of Scope / Known Limits

- No backfill: skills and true USD only accurate for projects crawled after the
  migration deploys.
- No live Freelancer API award lookup (award = local `completed` status).
- No currency rate refresh (rate frozen at crawl time — acceptable for value
  snapshots).
