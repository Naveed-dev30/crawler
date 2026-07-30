# Mobile AI Assistant — Design

Date: 2026-07-27
Status: Approved for planning

## Overview

Four related additions to the mobile chat API, all scoped per mobile user:

1. **FCM refresh** — an authenticated endpoint to update a device's FCM push token without re-logging-in.
2. **Thread details** — enrich the single-thread response with client info (country, rating, review count, engagement) sourced from `BidInsight`.
3. **AI assistant toggle** — per-user enable/disable of the AI auto-reply assistant.
4. **AI assistant schedule** — a per-user time window during which the assistant is automatically active, with a race-free interaction between the manual toggle and the schedule.

Plus the reactive **auto-reply pipeline** the toggle/schedule control: when a client message arrives and the assistant is active for the thread's assigned user, an AI reply is generated (OpenAI) and sent through the existing message-send path, stored and flagged as AI-origin.

## Guiding decision: computed state, not a fought-over flag

The naive design for the schedule — a per-minute cron that force-writes an `ai_enabled` boolean while a user is inside their window — has an unwinnable race: the user toggles the flag off, the next tick writes it back on, forever. Root cause: one mutable boolean stores two independent facts ("the schedule says on" and "the user says off"), so the last writer wins.

We eliminate the race by never storing the effective flag. We store the *inputs* (the window, and an optional manual override with an expiry) and compute the effective state on demand via a pure function. There is no second writer, so there is nothing to fight. This also removes all per-minute write load and scales to any number of users for free, because the state is only evaluated at the moment it is needed (per incoming message).

## Data model

One migration adding columns to `users`:

| Column | Type | Default | Meaning |
|---|---|---|---|
| `ai_schedule_enabled` | boolean | false | Whether the automatic window is active |
| `ai_window_start` | time | null | Window start, local to `ai_timezone` (e.g. `00:00`) |
| `ai_window_end` | time | null | Window end (e.g. `17:00`) |
| `ai_timezone` | string | null | IANA tz (e.g. `Asia/Karachi`); window is evaluated against this |
| `ai_manual_state` | boolean | null | The override: `null` = no override, `true`/`false` = forced value |
| `ai_manual_until` | datetime (UTC) | null | Override expiry; `null` = never expires |

One migration adding a column to `thread_messages`:

| Column | Type | Default | Meaning |
|---|---|---|---|
| `sent_by_ai` | boolean | false | True for messages generated and sent by the assistant |

`users.fcm_token` already exists (added in the mobile-fields migration) — no schema change for feature 1.

New model additions:

- `User::aiActiveNow(Carbon $now): bool` — the computed state (below).
- `User::nextBoundaryAfter(Carbon $now): ?Carbon` — the next window edge strictly after `now`, in UTC; `null` when `ai_schedule_enabled` is false.
- `User::withinWindow(Carbon $now): bool` — whether `now` (converted to `ai_timezone`) falls in `[start, end)`, handling overnight windows where `end < start` (wraps midnight).
- `ThreadMessage`: add `sent_by_ai` to `$fillable` and cast to boolean.

## The computed AI state

```php
public function aiActiveNow(Carbon $now): bool
{
    // A live override outranks the schedule.
    if ($this->ai_manual_state !== null
        && ($this->ai_manual_until === null || $now->lt($this->ai_manual_until))) {
        return (bool) $this->ai_manual_state;
    }
    // Otherwise the schedule decides.
    return $this->ai_schedule_enabled && $this->withinWindow($now);
}
```

Toggle semantics (feature 3): writing the toggle sets an override that expires at the next window boundary, so the schedule cleanly resumes afterward:

```php
$user->ai_manual_state = $enabled;
$user->ai_manual_until = $user->ai_schedule_enabled
    ? $user->nextBoundaryAfter($now)   // resume schedule at the next edge
    : null;                            // no schedule => sticky on/off
```

Walkthrough (window 00:00–17:00, schedule on):

1. 00:00 — `withinWindow` true, no override → `aiActiveNow` = **true**. Nothing written.
2. 02:00 — user toggles OFF → override `{state:false, until:17:00}` → **false**.
3. 02:01…16:59 — no cron, no writer → still **false**. The loop is gone.
4. 17:00 — override expires; schedule now out-of-window → **false** anyway. Seamless handoff.

Standalone toggle (no schedule set): `ai_manual_until = null`, so the toggle is a plain sticky on/off — feature 3 works without feature 4.

Multi-user: every row is independent and `aiActiveNow` is called per-user at message time. No background job iterates users; there is no shared state to contend on.

## Endpoints

All under the existing `v1/mobile` group, `auth:sanctum` + `mobile` middleware, using the `RespondsMobile` response envelope.

### 1. FCM refresh — `POST v1/mobile/fcm-token`
- Body: `{ fcm_token: required|string|max:512 }`
- Sets `$request->user()->fcm_token`, saves.
- Returns `ok(null, 'FCM token updated.')`.

### 2. Thread details — extend `GET v1/mobile/threads/{thread}` (no new route)
- `ThreadController@show` looks up the `BidInsight` row by `thread.project_id` (no FK; sparse data allowed).
- `ThreadResource` gains a `client` block:
  ```json
  "client": {
    "country": "Nigeria",
    "rating": 5.0,
    "reviews": 1,
    "engagement": { "contacted": 0, "invited": 0, "completed": 1 }
  }
  ```
- `client` is `null` when no `BidInsight` exists for the project. Individual fields serialize as `null` when unpopulated. `engagement` passes through the stored `client_engagement` JSON.
- Out of scope (not crawled anywhere today): "member since" date and the verification badges (identity/payment/email/phone). Noted for a future crawler extension.

### 3. AI toggle — `PUT v1/mobile/ai-assistant`
- Body: `{ enabled: required|boolean }`
- Writes the manual override per the semantics above.
- Returns the computed state block (same shape as the read below).

### 4. AI schedule — `PUT v1/mobile/ai-assistant/schedule`
- Body: `{ enabled: required|boolean, start: required_if enabled|date_format:H:i, end: required_if enabled|date_format:H:i, timezone: required_if enabled|timezone }`
- Sets `ai_schedule_enabled`, `ai_window_start`, `ai_window_end`, `ai_timezone`.
- Returns the computed state block.

### Read — `GET v1/mobile/ai-assistant`
- Returns:
  ```json
  {
    "active_now": true,
    "schedule_enabled": true,
    "window": { "start": "00:00", "end": "17:00", "timezone": "Asia/Karachi" },
    "manual_override": { "state": false, "until": "2026-07-27T17:00:00+00:00" }
  }
  ```
- `manual_override` is `null` when no override is live.

## Reactive auto-reply pipeline

### Shared send service
Extract the send logic currently inline in `MessageController@store` into `App\Services\SendThreadMessage`:
- Input: `Thread`, `?string $text`, `array $files = []`, `?int $senderUserId`, `bool $sentByAi = false`.
- Sends via `FreelancerMessenger::sendMessage`; on `null` result returns failure (controller maps to 502).
- Persists the `ThreadMessage` (`direction=sent`, `sender_user_id`, `sent_by_ai`), stores attachments, fires `ThreadMessageCreated`, and flips `thread.status` `fresh → answered`.
- `MessageController@store` is refactored to call this service with `sentByAi=false`; behavior unchanged.

### Trigger
In `ThreadSyncer`, at the point an inbound `received` message is stored (currently ~line 118, where the "queue AI" comment already sits), dispatch when all hold:
- `message.direction === 'received'`
- `thread.assigned_user` exists and `assigned_user->aiActiveNow(now())` is true
- `!thread.blocked`

→ `GenerateAiReplyJob::dispatch($thread->id, $message->id)`.

### GenerateAiReplyJob
- `ShouldQueue`, `withoutOverlapping` keyed by thread id (prevents double-sends).
- Re-checks inside `handle()` (state may have changed while queued):
  - assigned user still `aiActiveNow()`,
  - thread not blocked,
  - no human/AI `sent` message exists with `message_time` after the triggering client message (a human already replied → skip).
- Builds reply text via OpenAI, using the same call pattern as `ThreadMatcher`/`ProposalQualifier` (bounded retries, `config('variables.openAIKey')`, `gpt-3.5-turbo`): system prompt from the assigned user's `profile_prompt`, context from recent thread history.
- On a usable completion, calls `SendThreadMessage` with `sentByAi=true`. On OpenAI failure after retries, logs and gives up (no partial send).

### App surface
`ThreadMessageResource` exposes `sent_by_ai` so the app can badge AI-authored messages.

## Error handling

- All endpoints validate via `$request->validate`; failures return the standard mobile validation envelope (422).
- Thread endpoints keep the existing `authorizeThread` 403 guard (assigned user only).
- `BidInsight` absence is not an error — `client` is `null`.
- Send failures from Freelancer return 502 (unchanged for the human path; logged and abandoned for the AI job).
- Timezone/time validation rejects malformed schedule input before any write.

## Testing

- **`aiActiveNow` unit tests** (frozen clock, no DB needed beyond a User): inside/outside window; overnight wrap; override active vs expired; override with no schedule (sticky); the exact toggle-during-window scenario from the design.
- **`nextBoundaryAfter`**: same-day and overnight windows, now-before-start vs now-inside.
- **FCM refresh**: updates token; rejects oversized/missing token; requires auth+mobile.
- **Thread details**: `client` populated when BidInsight exists; `null` when absent; sparse fields → null.
- **Toggle / schedule endpoints**: persist correct columns; response block matches computed state; auth+mobile enforced.
- **Auto-reply pipeline**: trigger fires only when active+unblocked+assigned; job skips when a human replied first, when window closed while queued, when blocked; `SendThreadMessage` stores `sent_by_ai=true`; uses fake messenger + fake OpenAI (existing `Services\Fake` pattern).
- **`SendThreadMessage` refactor**: `MessageController@store` behavior unchanged (regression).

## Out of scope

- Crawling "member since" and client verification badges.
- Any direct Freelancer API call from the AI path (it reuses `FreelancerMessenger` via `SendThreadMessage`).
- Push-notifying the mobile app about AI replies beyond the existing `ThreadMessageCreated` broadcast.
