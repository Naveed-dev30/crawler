# Insights Dashboard Pages — Design

**Date:** 2026-07-20
**Status:** Approved (pending spec review)
**Depends on:** `2026-07-20-insights-ingest-design.md` (tables + APIs already implemented on branch `insights`)

## Goal

Two authenticated web pages surfacing the ingested insights data in the app sidebar, mirroring the existing Leaderboard page pattern (`GamificationController@index` → `content/pages/leaderboard.blade.php` → verticalMenu.json entry).

1. **/insights** — dashboard covering every requirement metric: bid summary, total earnings, job proficiency, overall ranking, ranking per skill, high demand skills, trending skills, bids per milestone, profile view counts.
2. **/insights/bids** — bid insights table with per-bid audit-log modal (change history with timestamps).

## Routes

In `routes/web.php`, inside the existing auth group, after the leaderboard route:

```php
Route::get('/insights', [\App\Http\Controllers\InsightsController::class, 'page'])->name('insights');
Route::get('/insights/bids', [\App\Http\Controllers\BidInsightsController::class, 'page'])->name('insights.bids');
```

Web `/insights/bids` does not conflict with API `/api/insights/bids` (different route files/prefixes).

## Controllers

- `InsightsController@page`: `$latest = InsightSnapshot::orderByDesc('scraped_at')->first();` `$history` = most recent 90 snapshots, chronological, mapped to `date`, `earnings_total`, `bids_remaining` (same query shape as the existing API `index`). Returns `view('content.pages.insights', compact('latest', 'history'))`.
- `BidInsightsController@page`: `$bids = BidInsight::orderByDesc('last_scraped_at')->paginate(50);` Returns `view('content.pages.insights-bids', compact('bids'))`.

No new services; controllers stay thin like `GamificationController@index`.

## View: `resources/views/content/pages/insights.blade.php`

Extends `layouts/layoutMaster`; loads apexcharts vendor script (same as leaderboard).

- **Empty state:** when `! $latest`, one card: "No insights data yet".
- **Row 1 — stat cards (5):** Total Earnings (`earnings_total`, `$` + number_format 2dp), Last 30 Days (`earnings_30d`), Bids Remaining (`bids_remaining`), Unearned Bids (`unearned_bids`), Overall Ranking (`overall_ranking` string, e.g. "25%" shown as "Top 25%"). Null → "—".
- **Row 2:** Job Proficiency card — one Bootstrap progress bar per `job_proficiency` item (`label`, `bars[0].fillPercentage`, `bars[0].rightLabel`). Bids per Milestone card — user value (`bids_per_milestone['user']`, usually null → "—") and marketplace benchmark (`bids_per_milestone['marketplace'][0]['value']` + label).
- **Row 3 — charts (ApexCharts, each skipped when its JSON column is null):**
  - Earnings Over Time: line, from `earnings_over_time.labels` / `.datasets[0].data`.
  - Bid Conversion: stacked column, all `bid_conversion.datasets` series over `.labels`.
  - Profile Views (past week): bar, `profile_views_week`.
  - Profile Views (past year): line, `profile_views_year`.
  - Earnings history across snapshots: line from `$history` (`date` × `earnings_total`).
- **Row 4 — skill tables (4), top 20 rows each, footer "Showing 20 of {total}":**
  - Rating per Skill: `rating_per_skill` → label, value (1dp).
  - Ranking per Skill: `ranking_per_skill` → label, displayValue.
  - High Demand Skills: `high_demand_skills` → label, displayValue.
  - Trending Skills: `trending_skills` → label, direction.

All JSON access defensive (`?? []` / `?? '—'`) — partial snapshots render whatever exists.

## View: `resources/views/content/pages/insights-bids.blade.php`

- **Empty state** card when `$bids->isEmpty()`.
- **Table columns:** Project (id, linked to `project_url` when present, new tab), Time to Bid (seconds humanized: `<60s` as "Ns", else "Nm Ns"), Bid Amount (`bid_amount` + `bid_currency`), Client (country, rating ★, reviews count), Bid Rank (`#N`), Winning Bid (`winning_bid_amount`, or "Sealed" when `winning_bid_sealed` true, else "—"), Actions (count of `actions_taken`), Last Update (`last_scraped_at` formatted `Y-m-d H:i`).
- **Pagination:** `{{ $bids->links() }}`.
- **Audit modal:** per-row "Changes" button with `data-bid-id`. One shared Bootstrap modal; on open, JS `fetch('/api/insights/bids/{id}/changes')` (existing unauthenticated read API), renders table rows: Field, Old, New, Observed At. States: loading spinner, "No changes recorded" when `data` empty, error message on fetch failure. No new backend endpoints.

## Menu

`resources/menu/verticalMenu.json` — two entries after Leaderboard:

```json
{ "url": "/insights", "name": "Insights", "icon": "menu-icon tf-icons bx bx-line-chart", "slug": "insights" },
{ "url": "/insights/bids", "name": "Bid Insights", "icon": "menu-icon tf-icons bx bx-target-lock", "slug": "insights-bids" }
```

No `access` key — visible to all authenticated users (same as Leaderboard).

## Error handling

- Pages are behind the auth group → guests redirected to login.
- Null/partial snapshot sections render "—" or skip their chart/table; page never 500s on missing JSON keys.
- Modal fetch failure shows inline error text, not a broken modal.

## Testing

Feature tests (pattern of `LeaderboardPageTest`):

1. `/insights` guest → redirect (302).
2. `/insights` authed, no data → 200, "No insights data yet".
3. `/insights` authed with seeded snapshot → 200; page contains earnings figure, "Bids Remaining", a skill label from seeded JSON.
4. `/insights/bids` authed, empty → 200, empty-state text.
5. `/insights/bids` authed with seeded rows → 200, shows project id, bid rank; >50 rows paginate (page 2 exists).

## Out of scope

- No changes to ingest/read APIs or tables.
- No admin gating, no realtime refresh, no filtering/search on tables.
- Skill tables capped at top 20 — full lists remain available via API.
