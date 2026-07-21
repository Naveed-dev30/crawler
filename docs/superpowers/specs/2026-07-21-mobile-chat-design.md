# Mobile Chat — Design Spec

Date: 2026-07-21
Status: approved

## Problem

Clients who receive our bids message us on Freelancer.com. Nobody sees those messages until someone opens the site. Response latency loses awards.

## Solution overview

A mobile chat pipeline on top of the existing crawler:

1. **Sync** — a dedicated worker polls the Freelancer Messages API every 10 seconds and stores threads/messages for projects we bid on.
2. **Assign** — each new thread is matched to the best-fit mobile user by OpenAI (profiles vs project) and that user gets an FCM push.
3. **Escalate** — threads nobody answers climb an escalation ladder (per-user rank 1–10) on a timer configured in Filters.
4. **Chat** — mobile apps use a Sanctum-authenticated API to read threads/messages, reply (relayed to Freelancer), block, and reassign.
5. **Manage** — admins create mobile users from a new Users tab.

## Decisions (locked)

| Topic | Decision |
|---|---|
| Thread scope | Only threads whose `context.id` matches `proposals.project_id` |
| Roles | `admin`, `team`, `mobile`; Users tab admin-only; mobile login API rejects non-mobile |
| Escalation ladder | Integer 1–10, unique per mobile user; form offers unused numbers only |
| Escalation timer | Resets on each escalation (`last_escalated_at`); window from Filters dropdown: 30 min / 2 h / 8 h / 1 day |
| Escalation stop | No user at `ladder+1` → skip (stay with current assignee) |
| Thread status | `fresh` → `answered` on first mobile reply; **never reopens** — new client messages do not flip it back; only never-answered threads escalate |
| Block | Suppresses FCM + escalation; sync keeps storing messages; sending still allowed; `?blocked=1` lists blocked threads |
| Attachments | Store Freelancer URL + metadata only; no downloads (verify URL auth at implementation; proxy endpoint fallback) |
| Messages stored | Received: only `from_user != flUserId`, imported by sync. Sent: stored at send time from our API, never re-imported |
| FCM | HTTP v1 via kreait/firebase-php with service-account JSON (available) |
| Queue | Switch `sync` → `database` driver + dedicated queue-worker container |
| Dev/prod parity | All FL URLs from `FL_BASE_URL` env (default `https://www.freelancer.com`); dev can point at Freelancer sandbox; test fixtures captured verbatim from real API |

## Architecture

```
thread-sync container (10s loop)          scheduler container (existing)
  threads:sync                              threads:escalate (every 2 min)
    └─ ThreadSyncer                           └─ ThreadEscalator
         ├─ FreelancerMessenger (HTTP)             └─ ThreadAssigner ──┐
         └─ AssignThreadJob ──queue──┐                                 │
                                     ▼                                 ▼
queue-worker container          ThreadMatcher (OpenAI)          ActivityLog +
  queue:work database           ThreadAssigner                  MobileNotification +
                                                                SendFcmPushJob → FcmPusher
mobile app ── Sanctum API (/api/v1/mobile/*) ── send message → FreelancerMessenger
```

`ThreadAssigner` is the single write-path for every assignment (AI, escalation, manual) — one place for log rows, notification rows, and pushes.

## Data model

- `users` + `profile_prompt`, `escalation_ladder` (unique, nullable), `fcm_token`
- `filters` + `escalation_minutes` (default 30)
- `threads`: FL thread id (unique), FL project id, proposal FK, assigned user FK, status, blocked, `last_client_message_at`, `last_escalated_at`, `freelancer_time_updated`
- `thread_messages`: thread FK, FL message id (unique, dedupe), direction sent/received, FL sender id, mobile sender FK, text, `message_time`
- `thread_attachments`: message FK, FL attachment id, filename, URL, mime, size
- `activity_logs`: thread FK, from/to user FKs, type (`escalation`|`manual_assign`), message
- `mobile_notifications`: user FK, thread FK, title, body, `read_at`

Named `ActivityLog`/`MobileNotification` to avoid the `Log` facade and Laravel `notifications` table collisions.

## Freelancer API (from official SDK)

- `GET  {base}/api/messages/0.1/threads/` — header `Freelancer-OAuth-V1`
- `GET  {base}/api/messages/0.1/messages/` — thread filter, limit/offset
- `POST {base}/api/messages/0.1/threads/{id}/messages/` — form `{message}` or multipart `attachments[]` + files

## Out of scope

- Web dashboard for threads (mobile-only consumer)
- Multiple FCM tokens per user (last login wins)
- Reopening answered threads
- Attachment storage/downloading
