# Insights Ingest APIs — Design

**Date:** 2026-07-20
**Status:** Approved (pending spec review)

## Goal

Store Freelancer insights data pushed by the external crawler service, following the existing gamification ingest pattern. Two data sources:

1. **User statistics** (`https://www.freelancer.com/insights/`) — dashboard metrics, crawled hourly (bid summary) / daily (everything else). Sample payload: extracted `serverData` blob (see `tests/fixtures/user-stats-serverdata.json`).
2. **Bid insights** (`https://www.freelancer.com/insights/bids`) — per-project bid data for the past ~1000 projects, one-time fields on first crawl plus hourly recurring fields, with a field-level audit log of changes.

The crawler owns scheduling (1h / 24h frequencies are its concern). Our API ingests whatever arrives, whenever it arrives.

## Existing pattern followed

`GamificationController@ingest` (`app/Http/Controllers/GamificationController.php`): token middleware, inline validation, `updateOrCreate` keyed on `scraped_at`, canonical columns + `raw` JSON, `{"success": true, "id": N}` response. Read side returns latest snapshot + 90-day history.

## Endpoints

| Method | Path | Auth | Purpose |
|---|---|---|---|
| POST | `/api/insights/ingest` | `gamification.token` | Ingest raw serverData blob → one `insight_snapshots` row |
| GET | `/api/insights` | none (matches gamification read side) | Latest snapshot + 90-day history |
| POST | `/api/insights/bids/ingest` | `gamification.token` | Ingest bid list → upsert `bid_insights`, write `bid_insight_changes` |
| GET | `/api/insights/bids` | none | Paginated latest bid states |
| GET | `/api/insights/bids/{id}/changes` | none | Audit trail for one bid |

Auth reuses the existing `EnsureGamificationToken` middleware and secret, unchanged.

### POST /api/insights/ingest

Body: the raw extracted serverData object, optionally wrapped with `scraped_at`:

```json
{
  "scraped_at": "2026-07-20T10:00:00Z",
  "userStats": { "jobProficiency": [...], "ratingPerSkill": [...], "bidConversion": {...}, "bidsPerMilestone": null, "bidSummary": [...], "earningsOverTime": {...}, "earningsPerClient": [...], "earningsPerSkill": [...], "totalEarnings": [...] },
  "marketplaceStats": { "jobProficiencyMarketplace": [...], "bidsPerMilestoneMarketplace": [...], "highDemandSkills": [...], "trendingSkills": [...], "overallRanking": [...], "rankingPerSkill": [...], "profileViewCountPastWeek": {...}, "profileViewCountPastYear": {...} }
}
```

Validation: at least one of `userStats` / `marketplaceStats` required, else 422. `scraped_at` optional, defaults to `now()`. Partial blobs allowed (1h cycle may send only bid summary sections; 24h cycle sends everything) — missing sections leave columns null.

Response: `{"success": true, "id": <snapshot_id>}`.

### POST /api/insights/bids/ingest

```json
{
  "scraped_at": "2026-07-20T10:00:00Z",
  "crawl_type": "initial",
  "bids": [
    {
      "project_id": 39812345,
      "project_url": "https://www.freelancer.com/projects/...",
      "time_to_bid_seconds": 94,
      "bid_amount": 250,
      "bid_currency": "USD",
      "client_country": "US",
      "client_rating": 4.8,
      "client_reviews": 132,
      "bid_rank": 3,
      "winning_bid_amount": 220,
      "winning_bid_sealed": false,
      "winning_bid_text": "...",
      "actions_taken": ["viewed_by_client", "shortlisted"],
      "client_engagement": {"viewed": true, "replied": false}
    }
  ]
}
```

Validation: `bids` array required; each item requires integer `project_id`; `crawl_type` in `initial|recurring`, default `recurring`. Invalid items skipped, not fatal.

Field contract is provisional — no real `/insights/bids` capture exists yet (saved HTML was an empty Angular shell). Unknown extra fields are tolerated and preserved in `raw`, so the crawler can send more than we parse; columns adjust when a real capture arrives.

Response: `{"success": true, "created": N, "updated": N, "changes": N, "skipped": N}`.

## Database schema

### `insight_snapshots`

| Column | Type | Source |
|---|---|---|
| id | bigint pk | |
| scraped_at | datetime, unique | payload or now() |
| earnings_total | decimal(14,2) nullable | `userStats.totalEarnings[0].value` ("$363,600.05" → 363600.05) |
| earnings_30d | decimal(14,2) nullable | `userStats.totalEarnings[1].value` |
| bids_remaining | int nullable | `userStats.bidSummary` label "Bids Remaining" |
| unearned_bids | int nullable | `userStats.bidSummary` label "Unearned Bids" |
| overall_ranking | string nullable | `marketplaceStats.overallRanking[0].value` (e.g. "25%") |
| job_proficiency | json nullable | `userStats.jobProficiency` |
| rating_per_skill | json nullable | `userStats.ratingPerSkill` |
| ranking_per_skill | json nullable | `marketplaceStats.rankingPerSkill` |
| high_demand_skills | json nullable | `marketplaceStats.highDemandSkills` |
| trending_skills | json nullable | `marketplaceStats.trendingSkills` |
| bids_per_milestone | json nullable | `userStats.bidsPerMilestone` + `marketplaceStats.bidsPerMilestoneMarketplace` |
| profile_views_week | json nullable | `marketplaceStats.profileViewCountPastWeek` |
| profile_views_year | json nullable | `marketplaceStats.profileViewCountPastYear` |
| earnings_over_time | json nullable | `userStats.earningsOverTime` |
| bid_conversion | json nullable | `userStats.bidConversion` |
| raw | longText | full payload |
| timestamps | | |

Not stored as columns (kept only inside `raw`): `earningsPerClient`, `earningsPerSkill`, `jobProficiencyMarketplace` — not in requirements; available later without schema change.

### `bid_insights`

| Column | Type | Notes |
|---|---|---|
| id | bigint pk | |
| project_id | bigint, unique | upsert key |
| project_url | string nullable | |
| time_to_bid_seconds | int nullable | one-time |
| bid_amount | decimal(12,2) nullable | one-time |
| bid_currency | string(8) nullable | one-time |
| client_country | string(64) nullable | one-time |
| client_rating | decimal(3,2) nullable | one-time |
| client_reviews | int nullable | one-time |
| bid_rank | int nullable | recurring |
| winning_bid_amount | decimal(12,2) nullable | recurring |
| winning_bid_sealed | boolean nullable | recurring |
| winning_bid_text | longText nullable | recurring |
| actions_taken | json nullable | recurring |
| client_engagement | json nullable | recurring |
| last_scraped_at | datetime | |
| raw | json | latest raw item |
| timestamps | | |

One-time fields: written when the row is created, or when currently null (fill-in), never overwritten with different values afterward. Recurring fields: updated every ingest.

### `bid_insight_changes`

| Column | Type |
|---|---|
| id | bigint pk |
| bid_insight_id | FK → bid_insights, cascade delete |
| field | string(64) |
| old_value | text nullable |
| new_value | text nullable |
| observed_at | datetime (payload `scraped_at`) |
| timestamps | |

Index on (`bid_insight_id`, `observed_at`).

## Ingest logic

### Insights snapshot

`InsightsController@ingest` (inline parsing, gamification-style):
1. Validate presence of `userStats`/`marketplaceStats`.
2. Parse canonical scalars — money strings stripped of `$`/commas; bidSummary matched by label; missing/malformed sections → null + `Log::warning`, never fatal.
3. `updateOrCreate(['scraped_at' => ...], [...])`, `raw` = full JSON.

### Bid insights

`BidInsightsController@ingest`, per bid item:
1. Find `bid_insights` by `project_id`. Missing → create with all provided fields (`created++`), no change rows on creation.
2. Exists → for each **recurring** field present in payload: compare with stored value (JSON fields compared canonically-serialized); differ → write `bid_insight_changes` row (`observed_at` = payload `scraped_at`) and update column. One-time fields only filled if currently null.
3. Update `last_scraped_at`, `raw`. Counters: `updated` = existing rows processed (regardless of whether any field changed), `changes` = total change rows written.
4. Item without valid `project_id` → `skipped++`.

Whole request wrapped in a DB transaction.

## Read side

- `GET /api/insights`: `{"latest": <parsed snapshot>, "history": [<90 days of scalar columns: scraped_at, earnings_total, earnings_30d, bids_remaining, unearned_bids, overall_ranking>]}` — mirrors `GamificationController@index`.
- `GET /api/insights/bids`: paginated (50/page) rows ordered by `last_scraped_at` desc, without `raw`.
- `GET /api/insights/bids/{id}/changes`: change rows ordered by `observed_at` desc, paginated.

## Error handling

- 401 — token middleware (existing behavior).
- 422 — top-level validation failures (Laravel validator JSON response).
- Section-level parse errors: log + null column; ingest still succeeds (raw preserved).
- Bid item errors: skip + count; request still 200.

## Testing

Feature tests with real fixture `tests/fixtures/user-stats-serverdata.json` (copied from `/Users/irfanali/Downloads/user-stats-extracted.json`):

1. Full blob ingest → canonical columns parsed correctly (earnings_total = 363600.05, bids_remaining = 203, unearned_bids = 1297, overall_ranking = "25%"), JSON sections stored.
2. Partial blob (userStats only) → succeeds, marketplace columns null.
3. Missing both sections → 422.
4. No token → 401.
5. Bids initial ingest → rows created, no change rows.
6. Recurring ingest with changed `bid_rank`/`winning_bid_amount` → columns updated + change rows with correct old/new/observed_at.
7. Recurring ingest with identical values → zero change rows.
8. Item missing `project_id` → skipped, others processed.
9. One-time field not overwritten on recurring crawl with different value.
10. GET endpoints: latest+history shape; bids pagination; changes trail.

## Out of scope

- Crawler-side scheduling (1h/24h) — external service's responsibility.
- UI pages — read APIs only.
- Bids payload finalization — provisional contract until a real `/insights/bids` capture is provided.
- Gamification changes — existing feature untouched.
