# Gamification Leaderboard Ingest + Page — Design

**Date:** 2026-07-17
**Status:** Approved design, pending spec review

## Purpose

Track the operator's Freelancer gamification standing over time. An **external
scraper service** (out of scope here) reads `https://www.freelancer.com/users/game/`
every 24 hours and POSTs the full page JSON to an API endpoint this app exposes.
The app authenticates the request with a shared secret, stores each payload as a
timestamped snapshot (structured fields + the raw JSON), and displays the current
standing plus a rank/score trend on a new Leaderboard page.

We are the **receiver**, not the crawler. The 24h cadence is the scraper's
responsibility; we accept whatever arrives, whenever it arrives.

## Data Source (the incoming JSON)

The scraper posts the shape in `/Users/irfanali/Downloads/gamification-data.txt`.
The fields we care about:

- `source.scraped_at` — ISO-8601 timestamp of the scrape (snapshot key).
- `leaderboard.top[]` — array (typically 5) of `{rank, user_id, username, public_name, level, score, is_current_user}`.
- `leaderboard.nearby[]` — array including the current user; the entry with
  `is_current_user === true` gives our `rank`, `score`, `level`, `username`,
  `public_name`.
- `level.xp_total` — our score fallback if no `is_current_user` entry is present.

Everything else in the payload (badges, credits, inventory, shop, achievements)
is retained in the raw blob but not extracted.

## Scope

### In scope
- Token-guarded `POST /api/gamification/ingest` endpoint.
- `gamification_snapshots` table + `GamificationSnapshot` model.
- Extraction of self rank/score/level + top-5, plus raw JSON retention.
- Idempotent ingest keyed on `scraped_at`.
- Leaderboard web page (auth): current rank/score/level, top-5 table, rank+score
  trend chart, sidebar entry.

### Out of scope
- The external scraper itself (built separately).
- Any on-app crawling of Freelancer for this data.
- Modeling badges/credits/inventory/shop into structured tables (kept only as raw).
- Notifications/alerts on rank change.

## Authentication

Shared secret token, machine-to-machine.

- New env var `GAMIFICATION_INGEST_TOKEN` (long random string).
- Exposed via `config/variables.php` as `gamificationIngestToken` =>
  `env('GAMIFICATION_INGEST_TOKEN')` (matches the existing pattern for `flKey`
  etc.).
- New route middleware alias `gamification.token` → `EnsureGamificationToken`,
  registered in `app/Http/Kernel.php` `$routeMiddleware` alongside the existing
  `admin` alias.
- The middleware reads the token from the `Authorization: Bearer <token>` header
  (also accept `X-Ingest-Token: <token>` as a fallback) and compares it to the
  config value using `hash_equals()` (timing-safe). Missing/blank config or
  mismatch → `401 {"message":"Unauthorized"}`. It never reveals the expected
  value.

## Data Model

Migration — create `gamification_snapshots`:

- `id`
- `scraped_at` — `timestamp`, **unique**, indexed (snapshot key).
- `self_rank` — `unsignedInteger`, nullable.
- `self_score` — `unsignedBigInteger`, nullable.
- `self_level` — `unsignedInteger`, nullable.
- `self_username` — `string`, nullable.
- `self_public_name` — `string`, nullable.
- `top5` — `json` (array of `{rank, user_id, username, public_name, level, score}`).
- `raw` — `longText` (the full posted payload, JSON-encoded).
- `timestamps`.

`GamificationSnapshot` model: `$fillable` for the above (except id/timestamps);
`$casts` = `['scraped_at' => 'datetime', 'top5' => 'array']`. (`raw` stays a
string; it is decoded only if needed.)

Revert = drop the table.

## Backend

### Middleware — `app/Http/Middleware/EnsureGamificationToken.php`
```php
public function handle(Request $request, Closure $next): Response
{
    $expected = (string) config('variables.gamificationIngestToken');
    $provided = (string) ($request->bearerToken() ?? $request->header('X-Ingest-Token', ''));

    if ($expected === '' || ! hash_equals($expected, $provided)) {
        return response()->json(['message' => 'Unauthorized'], 401);
    }

    return $next($request);
}
```

### Route — `routes/api.php`
```php
Route::post('gamification/ingest', [GamificationController::class, 'ingest'])
    ->middleware('gamification.token');
```
(Under the default `/api` prefix → `POST /api/gamification/ingest`.)

### Controller — `app/Http/Controllers/GamificationController.php`

`ingest(Request $request)`:
1. Validate the body is JSON with a `leaderboard` object and a `leaderboard.top`
   array. If not → `422 {"message":"Invalid payload"}`. (Lenient about the rest —
   external data varies.)
2. Extract:
   - `$top = collect($payload['leaderboard']['top'] ?? [])->map(fn ($e) => [
       'rank' => $e['rank'] ?? null, 'user_id' => $e['user_id'] ?? null,
       'username' => $e['username'] ?? null, 'public_name' => $e['public_name'] ?? null,
       'level' => $e['level'] ?? null, 'score' => $e['score'] ?? null,
       'is_current_user' => (bool) ($e['is_current_user'] ?? false),
     ])->values()->all();`
     (The `is_current_user` flag lets the top-5 table highlight our own row.)
   - `$self` = first entry in `leaderboard.nearby[]` with `is_current_user === true`
     (may be null).
   - `self_rank = $self['rank'] ?? null`, `self_username`, `self_public_name`,
     `self_level = $self['level'] ?? ($payload['level']['level'] ?? null)`,
     `self_score = $self['score'] ?? ($payload['level']['xp_total'] ?? null)`.
   - `scraped_at = $payload['source']['scraped_at'] ?? now()` (parse to Carbon).
3. `GamificationSnapshot::updateOrCreate(['scraped_at' => $scrapedAt], [ ...extracted..., 'top5' => $top, 'raw' => json_encode($payload) ])`.
4. Return `200 {"success": true, "id": $snapshot->id}`.

`index()` (web): pass to the Leaderboard view:
- `latest` = most recent snapshot by `scraped_at` (nullable).
- `history` = snapshots ordered by `scraped_at` asc, projected to
  `{scraped_at, self_rank, self_score}` for the trend chart (limit a sensible
  window, e.g. last 90 snapshots).

### Config — `config/variables.php`
Add: `'gamificationIngestToken' => env('GAMIFICATION_INGEST_TOKEN'),`

### Kernel — `app/Http/Kernel.php`
Register alias in `$routeMiddleware`:
`'gamification.token' => \App\Http\Middleware\EnsureGamificationToken::class,`

## Frontend

### Route — `routes/web.php` (inside the authenticated group)
```php
Route::get('/leaderboard', [GamificationController::class, 'index'])->name('leaderboard');
```

### Sidebar — `resources/menu/verticalMenu.json`
Add a "Leaderboard" entry (e.g. `"url": "/leaderboard", "icon": "menu-icon tf-icons bx bx-trophy", "slug": "leaderboard"`).

### View — `resources/views/content/pages/leaderboard.blade.php`
- **Header cards**: Rank (`#{{ latest.self_rank }}`), Score (`latest.self_score`),
  Level (`latest.self_level`). Show a muted "no data yet" empty state when
  `latest` is null.
- **Top-5 table**: columns Rank / Name (`public_name`) / Score, rows from
  `latest.top5`; highlight the row where `is_current_user` is true, if present.
- **Trend chart** (`#chart-leaderboard`, ApexCharts — already loaded on stats):
  two series over `history` buckets (`scraped_at` formatted date) — **Rank**
  (note: lower is better; render on a reversed y-axis or a second axis) and
  **Score**. Fed by inline JSON printed from `history` (no extra endpoint needed;
  same pattern as the stats page bootstraps its first data).
- Uses the existing Bootstrap theme + `@section('vendor-script')` apex include
  like `stats.blade.php`.

## Data Flow

1. External scraper (24h) → `POST /api/gamification/ingest` with header
   `Authorization: Bearer <GAMIFICATION_INGEST_TOKEN>` and the JSON body.
2. `gamification.token` middleware verifies the token (timing-safe) → 401 if bad.
3. `ingest()` validates, extracts self + top-5, `updateOrCreate` by `scraped_at`,
   stores raw → 200.
4. Operator opens `/leaderboard` → sees latest rank/score/level, top-5 table, and
   the rank/score trend from all snapshots.

## Error Handling

- Missing/blank/mismatched token → `401`, no detail leaked.
- Body not JSON, or no `leaderboard.top` → `422 {"message":"Invalid payload"}`.
  Never `500` on malformed input.
- Missing `is_current_user` entry → `self_*` stored as null; snapshot still saved.
- Re-posted `scraped_at` → updates the existing row (idempotent), no duplicate.
- Leaderboard page with zero snapshots → friendly empty state, no error.

## Testing

**Ingest (feature, `RefreshDatabase`):**
- Valid token + the sample payload → `200`; one `gamification_snapshots` row;
  `self_rank = 268`, `self_score = 309961`, `self_level = 20`,
  `self_public_name = 'Raja Ahmad Ayaz N.'`; `top5` has 5 entries with
  `public_name`/`rank`/`score`; `raw` decodes back to the payload.
- Missing token → `401`; wrong token → `401`; no snapshot created.
- Body without `leaderboard.top` → `422`; no snapshot created.
- Posting the same `scraped_at` twice → still exactly one row (idempotent);
  second post updates fields.
- (Load the sample from a fixture copy under `tests/` so the test is
  self-contained.)

**Leaderboard page (feature):**
- `/leaderboard` requires auth (redirect when guest).
- With a seeded snapshot → page shows the rank, score, and a top-5 name.
- With no snapshots → shows the empty state, `200`.

## Revert Plan

1. `php artisan migrate:rollback` (drops `gamification_snapshots`).
2. Delete `GamificationController`, `EnsureGamificationToken`, the model, the
   migration, `leaderboard.blade.php`, the api route line, the web route line, the
   Kernel alias, the config line, and the sidebar entry.
Nothing else is touched.

## Future (not built now)
- Rank-change notifications (Slack, already configured) when we move up/down.
- Retain badges/credits trends by extracting more from the stored raw blobs.
- Multiple tracked users / competitor watch.
