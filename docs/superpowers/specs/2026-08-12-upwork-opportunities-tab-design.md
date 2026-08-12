# Upwork Opportunities Tab — Design

**Date:** 2026-08-12
**Status:** Approved for planning

## Summary

The Opportunities page (`/bids`, rendered by `BidController@index` → `resources/views/content/pages/home.blade.php`) currently shows Freelancer.com bids/proposals only. This adds a top-level marketplace switcher with two tabs:

- **Freelancer** — the existing view, unchanged (gets a Freelancer brand icon).
- **Upwork** — new. Lists Upwork marketplace jobs crawled from the Upwork GraphQL API, stored locally, and rendered as a table.

Scope of this iteration: **crawl → store → list**. No AI proposals, no bidding, no per-job detail panel, no filters/search on the Upwork tab. List only. This mirrors the existing Freelancer `proposals:fetch` pipeline (`FetchProposals` command → `ProposalController@getProposals` → `Http::withHeaders()` → `updateOrCreate` on `project_id`).

## Non-Goals

- Generating or submitting proposals/bids on Upwork jobs.
- Per-job detail slide-over.
- Filters, search, or date-range controls on the Upwork tab (Freelancer tab keeps its own).
- Completing the Upwork OAuth2 authorization-code handshake in-app. The user supplies a working access token + refresh token; the app only *uses* and *refreshes* them.

## Upwork API Reference

- Endpoint: `https://api.upwork.com/graphql` (POST).
- Auth: OAuth 2.0 authorization-code flow. Requests send `Authorization: Bearer <access_token>`. Marketplace queries are org/tenant-scoped and require a tenant id header (`X-Upwork-API-TenantId`).
- Query: `marketplaceJobPostingsSearch` — returns paginated job posting edges. No search filter applied this iteration (list recent postings). The exact selection set (field names for amount/budget, hourly range, client stats, skills, posted timestamp, ciphertext/id, url) is finalized against the official docs during implementation: https://www.upwork.com/developer/documentation/graphql/api/docs/index.html
- Token refresh: on `401`/expired-token, exchange the refresh token at Upwork's OAuth token endpoint for a new access token, persist it, retry the request once.

## Configuration

New `.env` keys (values provided by user later; blank in `.env.example`):

```
UPWORK_BASE_URL=https://api.upwork.com/graphql
UPWORK_OAUTH_TOKEN_URL=https://www.upwork.com/api/v3/oauth2/token
UPWORK_CLIENT_ID=
UPWORK_CLIENT_SECRET=
UPWORK_ACCESS_TOKEN=
UPWORK_REFRESH_TOKEN=
UPWORK_TENANT_ID=
```

Mapped in `config/variables.php` (same style as `flKey`/`flBase`):

```php
"upworkBase"        => env("UPWORK_BASE_URL", "https://api.upwork.com/graphql"),
"upworkTokenUrl"    => env("UPWORK_OAUTH_TOKEN_URL", "https://www.upwork.com/api/v3/oauth2/token"),
"upworkClientId"    => env("UPWORK_CLIENT_ID"),
"upworkClientSecret"=> env("UPWORK_CLIENT_SECRET"),
"upworkAccessToken" => env("UPWORK_ACCESS_TOKEN"),
"upworkRefreshToken"=> env("UPWORK_REFRESH_TOKEN"),
"upworkTenantId"    => env("UPWORK_TENANT_ID"),
```

Both `.env` and `.env.example` get the keys (example blank).

## Data Model

Migration: `create_upwork_jobs_table`. Model: `App\Models\UpworkJob`.

| Column | Type | Notes |
|---|---|---|
| `id` | bigint pk | |
| `job_id` | string, unique | Upwork job id / ciphertext — dedup key for `updateOrCreate` |
| `title` | string, nullable | |
| `description` | longText, nullable | |
| `url` | string, nullable | link to the Upwork posting |
| `job_type` | string, nullable | `hourly` \| `fixed` |
| `budget_amount` | double, nullable | fixed-price budget |
| `hourly_min` | double, nullable | |
| `hourly_max` | double, nullable | |
| `currency` | string, nullable | |
| `posted_at` | timestamp, nullable | job posted time |
| `skills` | json, nullable | array of skill names |
| `client_country` | string, nullable | |
| `client_total_spent` | double, nullable | |
| `client_payment_verified` | boolean, nullable | |
| `timestamps` | | |

`UpworkJob` casts: `skills => array`, `posted_at => datetime`, `client_payment_verified => boolean`.

## Components

### `App\Services\UpworkClient`
One clear job: talk to the Upwork GraphQL API and return normalized job rows.

- `fetchRecentJobs(int $limit = 50): array` — POSTs the `marketplaceJobPostingsSearch` query, returns an array of normalized associative arrays keyed to the `upwork_jobs` columns.
- Private helpers: `post(array $graphql)` (adds Bearer + tenant headers via `Http::withHeaders()->post()`), `refreshToken()` (exchanges refresh token, persists new access token), `normalizeEdge(array $node)` (maps a GraphQL node to a table row).
- On `401`: call `refreshToken()`, retry `post()` once. If still failing, log and return `[]` (crawl is best-effort, like the Freelancer fetch).
- Depends on: `config('variables.upwork*')`, Laravel `Http`, `Log`.
- Token persistence: write refreshed access token back so subsequent runs use it. Simplest durable store = a small `settings`/cache row. **Decision:** persist via Laravel `Cache` (`cache()->forever('upwork_access_token', $token)`), falling back to the `.env` value when cache is empty. Avoids a new table and rewriting `.env` at runtime.

### `App\Console\Commands\FetchUpworkJobs`
- Signature `upwork:fetch`, description "Fetch recent Upwork marketplace jobs".
- `handle()`: `UpworkClient::fetchRecentJobs()`, then `UpworkJob::updateOrCreate(['job_id' => $row['job_id']], $row)` per row. Logs count.
- Registered in `app/Console/Kernel.php` on a cadence comparable to `proposals:fetch` (e.g. `->everyThirtyMinutes()->withoutOverlapping()`; exact cadence set during implementation).

### `App\Http\Controllers\UpworkController`
- `data(Request $request)`: paginates `UpworkJob::latest('posted_at')`, returns JSON `{ rowsHtml, paginationHtml }` — same contract the Upwork tab's JS expects, mirroring `BidController@data`.
- Rows rendered from a partial `resources/views/content/pages/_partials/upwork-rows.blade.php`.

### Routes (`routes/web.php`, same auth group as `/bids`)
```php
Route::get('/bids/upwork/data', [UpworkController::class, 'data'])->name('bids.upwork.data');
```

## UI

In `home.blade.php`, wrap the existing content in a marketplace switcher:

- A tab strip above the current filter bar with two buttons:
  - **Freelancer** (brand icon) — `active` by default. Its pane contains ALL current markup (filter bar, summary cards, inner status tabs, table, offcanvas), unchanged.
  - **Upwork** (brand icon) — a new pane: a card with a table (columns: **Title** (links to job url), **Budget / Rate**, **Posted**, **Skills**, **Client**) + pagination container.
- Brand icons: inline SVG logos for Freelancer and Upwork (Boxicons `bx`/`bxl` set has no reliable Freelancer/Upwork brand glyphs). SVGs live inline in the blade.
- JS: switching to the Upwork tab lazy-loads `GET /bids/upwork/data` (first activation only, then on pagination click). Freelancer tab keeps its existing auto-refresh/interval logic. The Upwork pane must NOT trigger the Freelancer `/bids/data` polling, and vice versa.
- Marketplace selection reflected in the URL (`?market=upwork`) so a refresh keeps the pane, consistent with the existing `?tab=` URL-sync behavior.

## Error Handling

- `UpworkClient` network/GraphQL errors: logged, return `[]`; the command reports `0 fetched` rather than throwing (matches Freelancer fetch tolerance).
- Missing credentials (blank token): `UpworkClient` short-circuits, logs a warning, returns `[]`. The Upwork tab then shows an empty-state row ("No Upwork jobs yet").
- `/bids/upwork/data` on empty table: returns an empty-state row, not an error.

## Testing

- **Unit** (`UpworkClientTest`): `Http::fake()` a `marketplaceJobPostingsSearch` response → assert `fetchRecentJobs()` normalizes edges to the expected row shape (budget vs hourly, skills array, client fields, posted_at). Second test: `Http::fake()` a 401 then a success → assert refresh path runs and retries once.
- **Feature** (`UpworkTabTest`): seed `UpworkJob` rows → `GET /bids/upwork/data` → assert 200 JSON with `rowsHtml` containing a job title and the pagination key. Empty-table case returns the empty-state markup.
- Follows the repo's existing PHPUnit + `Http::fake` conventions.

## Rollout / Assumptions

- User supplies a valid access token + refresh token (and client id/secret, tenant id) in `.env`. Until then the tab renders its empty state and the crawl no-ops with a logged warning — no errors surfaced to users.
- The exact GraphQL selection set and OAuth token-endpoint payload are confirmed against the official Upwork docs during implementation; column list above is the target normalized shape regardless of upstream field naming.
