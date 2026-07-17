# Mobile API (v1) — Design

**Date:** 2026-07-17
**Status:** Approved design, pending spec review

## Purpose

The project now has a companion mobile app. This spec defines a dedicated,
versioned JSON API (`/api/v1/*`) the mobile client uses to authenticate and to
work with the app's core data. The existing web controllers return HTML
fragments and redirects, so they cannot be reused by a native client; the
mobile API is a separate, isolated surface that leaves the web UI untouched.

This is the first slice: **authentication + current user**, then the **Bids**
and **Review** feature areas. Filters, statistics, and gamification are
explicitly out of scope for now.

## Scope

### In scope
- Sanctum Bearer-token auth: login, current user, logout.
- Bids: paginated list (same filters as web), single detail, status change,
  check-flag update, bulk expire.
- Review: load proposals needing review (new/old tabs, cursor pagination),
  post a review label.
- API Resource classes for consistent JSON shaping.
- Feature tests for every endpoint.

### Out of scope
- Filters, statistics, gamification endpoints.
- Bid create/edit/delete (full CRUD) from mobile.
- Push notifications, offline sync, refresh-token rotation.
- Any change to existing web routes/controllers/views.

## Architecture (Approach A)

- New controllers under `app/Http/Controllers/Api/V1/`: `AuthController`,
  `BidController`, `ReviewController`. These return JSON only.
- New API Resources under `app/Http/Resources/`: `UserResource`,
  `BidResource`, `ProposalResource`.
- All routes live in `routes/api.php` under a `prefix('v1')` group. Every
  route except `login` is wrapped in `auth:sanctum`.
- Existing `/api` routes (`getProposals`, `getBid`, `changeBidStatus`,
  `gamification/ingest`, the old `/user`) are left as-is. The mobile surface is
  additive and namespaced under `v1` to avoid collisions.
- The `User` model already has `HasApiTokens`; no model change needed.

## Authentication

Sanctum personal access tokens, machine-to-user.

- **Multiple devices allowed.** Login issues a new token and does NOT revoke
  existing tokens, so a user can be logged in on more than one device.
- Token name comes from an optional `device_name` field, defaulting to
  `"mobile"`.
- Logout revokes only the **current** token
  (`$request->user()->currentAccessToken()->delete()`), leaving other devices
  signed in.

### Endpoints

| Method | Path | Auth | Body / Query | Success |
|---|---|---|---|---|
| POST | `/api/v1/login` | none | `email`, `password`, `device_name?` | `200 { token, user }` |
| GET | `/api/v1/user` | Bearer | — | `200 { user }` |
| POST | `/api/v1/logout` | Bearer | — | `204` (no body) |

**Login** (`AuthController@login`):
1. Validate: `email` required|email, `password` required|string,
   `device_name` nullable|string. Fail → `422 { message, errors }`.
2. Look up user by email; verify password with `Hash::check`. On failure →
   `401 { "message": "Invalid credentials" }` (does not reveal which field was
   wrong).
3. `$token = $user->createToken($deviceName ?: 'mobile')->plainTextToken;`
4. Return `200 { "token": <plainTextToken>, "user": UserResource }`.

**Current user** (`AuthController@me`): return
`200 { "user": UserResource($request->user()) }`.

**Logout** (`AuthController@logout`): delete the current access token, return
`204` with no body.

`UserResource`: `{ id, name, email, role }`. Never exposes `password` or
`remember_token`.

## Bids

### Endpoints

| Method | Path | Body / Query | Success |
|---|---|---|---|
| GET | `/api/v1/bids` | query: `tab`, `q`, `type`, `from`, `to`, `min`, `max`, `page` | `200 { data:[BidResource], cards, meta }` |
| GET | `/api/v1/bids/{bid}` | — | `200 { data: BidResource(full) }` |
| POST | `/api/v1/bids/{bid}/status` | `status` | `200 { success:true, data: BidResource }` |
| POST | `/api/v1/bids/{bid}/check` | `check` | `200 { success:true, check }` |
| POST | `/api/v1/bids/expire` | — | `200 { success:true, expired_count }` |

**List** (`BidController@index`): reuse the web filter logic verbatim (a private
`filteredBidQuery` mirroring `BidController::filteredBidQuery`):
- `tab` ∈ {`placed`,`completed`,`failed`}, default `placed`.
  `placed` = `bid_status` in (`pending`,`completed`);
  `failed` = in (`failed`,`expired`); `completed` = (`completed`).
- `q` matches `proposals.title` or `proposals.project_id` (LIKE).
- `type` ∈ {`fixed`,`hourly`} filters `proposals.type`.
- `from`/`to` filter `bids.created_at` (start/end of day);
  `min`/`max` filter `bids.price`.
- `cards` = `{ total, placed, failed }` counts over the filtered base
  (same as web).
- Paginate 100/page (`->paginate(100)->withQueryString()`), ordered
  `latest('bids.created_at')`, eager-load `proposal`.
- Response:
  ```json
  {
    "data": [ BidResource(list) ... ],
    "cards": { "total": N, "placed": N, "failed": N },
    "meta": { "current_page": N, "last_page": N, "per_page": 100, "total": N }
  }
  ```

**Detail** (`BidController@show`): route-model binding `{bid}` → missing yields
`404 { "message": "..." }` (JSON, not the web HTML). Marks `is_seen = true`
(same side effect as web `detail`), eager-loads `proposal`, returns the **full**
`BidResource` (includes `cover_letter` + proposal `description`).

**Status** (`BidController@updateStatus`): validate `status` required|string.
Set `bid_status = status`, save, return the updated `BidResource`. Unknown
`{bid}` → 404 (route-model binding).

**Check** (`BidController@updateCheck`): validate `check` required|string. Set
`check`, save, return `{ success:true, check }`. (Mirrors web `updateBidCheck`;
404 on missing.)

**Expire** (`BidController@expire`): set every bid whose `bid_status` is not
`completed` to `expired` (same rule as web `expireBids`). Return
`{ success:true, expired_count: N }` instead of redirecting.

### BidResource

Shape depends on a `full` flag set by the controller (via a resource
property/constructor or `additional`), so the list stays light and detail is
complete.

**List shape:**
```json
{
  "id": 12,
  "status": "pending",
  "price": 150.0,
  "currency": "$",
  "awarded": false,
  "awarded_price": null,
  "check": "Unreviewed",
  "is_seen": true,
  "created_at": "2026-07-17T10:00:00Z",
  "proposal": {
    "id": 5, "title": "...", "project_id": 999, "type": "fixed",
    "country": "US", "min_budget": 100.0, "max_budget": 250.0,
    "seo_url": "...", "skills": ["php","laravel"]
  }
}
```
- `status` ← `bid_status`; `currency` ← `proposal.currency_symbol`.

**Detail shape:** all of the above plus top-level `cover_letter` and
`proposal.description`.

## Review

### Endpoints

| Method | Path | Query / Body | Success |
|---|---|---|---|
| GET | `/api/v1/review` | query: `tab` (`new`\|`old`), `after_id?` | `200 { data:[ProposalResource], hasMore, newCount, oldCount }` |
| POST | `/api/v1/review/feedback` | `proposal_id`, `label` | `200 { success:true }` |

**Load** (`ReviewController@index`): mirror the web `ReviewController` logic:
- 7-day window splits `new` (`created_at >= now-7d`) vs `old` (`< now-7d`) over
  the `Proposal::needsReview()` scope (`review_label` null).
- `tab` defaults to `new`. Order `id` desc. `after_id` → `where id < after_id`
  (cursor). Fetch `PER_PAGE + 1` (21) to compute `hasMore`, return 20.
- Response:
  ```json
  {
    "data": [ ProposalResource ... ],
    "hasMore": true,
    "newCount": N,
    "oldCount": N
  }
  ```

**Feedback** (`ReviewController@storeFeedback`): validate
`proposal_id` required|exists:proposals,id and
`label` required|in:relevant,not_relevant_skill,scam → 422 on bad input. Set
`review_label = label`, save, return `{ success:true }`.

### ProposalResource
```json
{
  "id": 5, "title": "...", "description": "...", "type": "fixed",
  "country": "US", "min_budget": 100.0, "max_budget": 250.0,
  "currency_symbol": "$", "skills": ["php","laravel"],
  "seo_url": "...", "created_at": "2026-07-17T10:00:00Z"
}
```

## Error Handling

- Unauthenticated (missing/invalid Bearer) → `401 { "message": "Unauthenticated." }`.
  The mobile client always sends `Accept: application/json`, so Laravel's
  exception handler renders JSON, never an HTML login redirect. If any code path
  could still redirect, harden `App\Exceptions\Handler` to force JSON for
  `expectsJson()`/`is('api/*')` requests.
- Validation failure → `422 { message, errors }` (Laravel default).
- Missing model (route-model binding) → `404 { message }` JSON.
- Bad credentials on login → `401 { message: "Invalid credentials" }`.
- Never `500` on malformed input — validate before touching the DB.

## Testing

Feature tests (`RefreshDatabase`, Sanctum acting-as via `Sanctum::actingAs` or
real token flow). Each asserts both HTTP status and JSON structure.

**Auth:**
- Valid login → 200, response has `token` (non-empty string) and
  `user.email`; a `personal_access_tokens` row exists.
- Wrong password → 401, no token issued.
- Missing email/password → 422.
- `GET /user` without token → 401; with token → 200 and correct user; response
  never contains `password`.
- Logout with token → 204; the used token row is deleted; a second device's
  token still works.

**Bids:**
- List with a seeded set → 200; `data` is an array of the resource shape;
  `cards.total` matches; `meta.current_page` present; `cover_letter` absent from
  list items.
- `tab=failed` returns only failed/expired; `q`/`type`/date/price filters narrow
  results.
- Detail for existing bid → 200, includes `cover_letter`; sets `is_seen`.
- Detail for missing id → 404 JSON.
- Status change → 200, `data.status` updated; missing `status` → 422;
  missing bid → 404.
- Check update → 200, `check` echoed; missing bid → 404.
- Expire → 200, `expired_count` equals the number of non-completed bids;
  those rows now `expired`.

**Review:**
- Load `new` tab → 200; only `needsReview` proposals within 7 days;
  `hasMore`/`newCount`/`oldCount` present. `after_id` pages correctly.
- `old` tab returns only proposals older than 7 days.
- Feedback with valid label → 200, `review_label` persisted.
- Feedback with invalid label or non-existent `proposal_id` → 422.

All auth-guarded endpoints: without a token → 401.

## File Structure

- Create: `app/Http/Controllers/Api/V1/AuthController.php`
- Create: `app/Http/Controllers/Api/V1/BidController.php`
- Create: `app/Http/Controllers/Api/V1/ReviewController.php`
- Create: `app/Http/Resources/UserResource.php`
- Create: `app/Http/Resources/BidResource.php`
- Create: `app/Http/Resources/ProposalResource.php`
- Modify: `routes/api.php` (add the `v1` group)
- Possibly modify: `app/Exceptions/Handler.php` (force JSON for api requests)
- Create tests: `tests/Feature/Api/AuthApiTest.php`,
  `BidApiTest.php`, `ReviewApiTest.php`

## Revert Plan

Delete the three `Api/V1` controllers, the three Resources, the test files, and
the `v1` route group from `routes/api.php`. Revert the `Handler.php` change if
made. Nothing else is touched — no migrations, no web routes, no models.

## Future (not built now)

- Mobile endpoints for filters and statistics.
- Bid create/edit from mobile.
- Token refresh/expiry policy, device management screen.
- Rate limiting tuned per mobile client.
```