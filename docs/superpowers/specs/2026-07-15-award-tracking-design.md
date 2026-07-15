# Award Tracking — Design Spec

**Date:** 2026-07-15
**Branch:** `statistics`
**Status:** Approved, ready for implementation planning

## Goal

Detect which of our placed bids the client actually **awarded** on Freelancer,
store the awarded price, and drive the blue "Awarded" chart series and the
"Value Awarded (24h)" card from real award data instead of the current
`pending`/`completed` proxy.

## Current State

- `BidNowJob` sets `bid_status = 'completed'` when the bid POST returns 200 — so
  **"completed" means "bid successfully placed," not "won/awarded."** The
  returned Freelancer bid id is not saved.
- No award concept exists. `bids` has: `bid_status`, `price`, `cover_letter`,
  `check`, `is_seen`, `error_message`, `admin_feedback`.
- `StatisticsController::bids()` maps: qualified=`pending`, successful=`completed`,
  failed=`failed`+`expired`. `last24h()` treats a proposal's bid `completed` as
  "awarded."
- Cron already runs: `App\Console\Kernel` schedules
  `ProposalController::getProposals()` every minute.
- Auth for Freelancer API: `config('variables.flKey')` (OAuth), `config('variables.flUserId')`.
- Stats page JS (`stats.blade.php`) renders three outcome series (Qualified /
  Successful / Failed) into `#chart-fixed`, `#chart-hourly`, `#chart-all`.

## Decisions (locked)

1. **Poll scope:** all bids with `bid_status = 'completed'` AND `awarded = false`,
   regardless of age; cron **every 30 minutes**; batched by project.
2. **Chart series:** disjoint **Awarded / Placed / Failed**:
   - `awarded == true` → Awarded
   - `bid_status == 'completed'` AND `awarded == false` → Placed
   - `bid_status` in {`failed`, `expired`} → Failed
   - `pending` → not shown
3. **Awarded money:** store `awarded_price` in native currency; USD =
   `awarded_price × exchange_rate` (no hourly ×10). If the API returns no amount,
   fall back to the posted bid price (`bids.price`).
4. **One-way:** once `awarded = true`, stop polling that bid (revocation not tracked).

## Schema Changes

One migration adding to `bids`:
- `awarded` — boolean, not null, default `false`.
- `awarded_price` — double, nullable.

No changes to other tables.

## Award-Check Component

`app/Services/BidAwardChecker.php` — one public method `run(): void`. Also an
artisan command `bids:check-awards` that calls it (for manual runs and testing).

Algorithm:
1. Query `Bid::where('bid_status', 'completed')->where('awarded', false)->with('proposal')`.
2. Collect the proposals' `project_id`s; chunk into batches of 100.
3. For each batch, call the Freelancer bids API:
   `GET https://www.freelancer.com/api/projects/0.1/bids/?compact=true&bidders[]={flUserId}` plus one `&projects[]={project_id}` per project in the batch, with header `Freelancer-OAuth-V1: {flKey}`.
4. From `result.bids` (array), index returned bids by `project_id`.
5. For each of our completed bids, look up the returned bid by its proposal's
   `project_id`. If found and `award_status === 'awarded'`:
   - `bid->awarded = true`
   - `bid->awarded_price = (returned amount) ?? bid->price`
   - save.
6. Non-awarded / missing → leave untouched (re-checked next run).

Error handling: a non-successful HTTP response or exception for a batch is logged
and skipped; other batches still process; the run does not throw.

Scheduling: `Kernel::schedule()` adds `->call(fn () => (new BidAwardChecker)->run())->everyThirtyMinutes();`

## Statistics Changes (`StatisticsController`)

### `bids()` endpoint
Replace the qualified/successful/failed counts with **awarded / placed / failed**.
Each in-range bid is categorized once:
- `awarded == 1` → `awarded`
- `bid_status == 'completed'` (and not awarded) → `placed`
- `bid_status` in {`failed`, `expired`} → `failed`
- otherwise (`pending`) → skipped
Response shape per bucket: `{ bucket, awarded, placed, failed }`.

The join/query must select `bids.awarded` alongside the existing fields.

### `last24h()` endpoint
- `value_awarded_usd` = Σ over proposals created in the last 24h whose bid is
  `awarded == true` of `(awarded_price ?? bid.price) × (exchange_rate ?? 1)`
  (no ×10).
- `skills` frequency computed over **awarded** proposals (bid `awarded == true`),
  replacing the previous `completed`-based logic.
- `value_posted_usd` unchanged.

### Project Value chart (`value()`)
Unchanged (still placed = pending+completed, failed = failed+expired). Out of
scope for this change.

## Frontend (`stats.blade.php`)

- Outcome series renamed and re-keyed: `Qualified→Awarded` (key `awarded`),
  `Successful→Placed` (key `placed`), `Failed` (key `failed`). Blue = Awarded,
  green = Placed, orange = Failed.
- No structural layout change (All Bids on top, Fixed/Hourly row below stays).

## Testing

Feature tests (SQLite, RefreshDatabase, Http::fake / Queue where needed):

- **BidAwardChecker:**
  - Awarded project → sets `awarded = true` and `awarded_price` from API amount.
  - Awarded project with no amount in response → `awarded_price` falls back to
    the posted bid price.
  - `award_status` pending/absent → bid stays `awarded = false`, price null.
  - Only `completed` + `awarded = false` bids are polled (a `failed` bid and an
    already-`awarded` bid are not changed).
- **`bids()` endpoint:** an awarded bid counts under `awarded` (not `placed`); a
  completed-not-awarded bid counts under `placed`; failed/expired under `failed`.
- **`last24h()`:** `value_awarded_usd` uses `awarded_price × exchange_rate` and
  falls back to bid price; skills come from awarded proposals only.

## Out of Scope / Notes

- No award revocation tracking (one-way).
- The Freelancer bid `award_status` / `amount` field names are isolated in
  `BidAwardChecker`; if the live API differs, only that class changes.
- Awarded series/card remain ~0 until the cron runs against the live API (not
  running in the dev env). Optionally, a few seeded bids can be marked `awarded`
  for demo — deferred unless requested.
- `bids.awarded_price` is native currency; USD conversion happens at read time
  via `proposals.exchange_rate`, consistent with the rest of the dashboard.
