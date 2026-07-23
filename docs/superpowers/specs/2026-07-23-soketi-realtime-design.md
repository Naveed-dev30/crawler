# Realtime Events via Soketi

Date: 2026-07-23
Status: approved

## Goal

Mobile users (and later the admin dashboard) receive realtime events —
new thread messages, assignments, read-state changes — over WebSockets.
Soketi (self-hosted, Pusher protocol) is the socket server; Laravel's
native broadcasting does everything else. FCM remains the channel for
background/killed-app notifications.

## Architecture

- New `soketi` service in docker-compose (`quay.io/soketi/soketi`),
  port 6001, same network. Static app id/key/secret via env.
- Laravel broadcasts with the `pusher` driver (`pusher/pusher-php-server`)
  pointed at the Soketi host. All events implement `ShouldBroadcast` and go
  through the existing queue worker.
- Mobile connects with `pusher_channels_flutter`, auth endpoint
  `POST /api/broadcasting/auth` guarded by Sanctum.

## Channels (`routes/channels.php`)

- `private-user.{id}` — that user, or any admin.
- `private-thread.{threadId}` — the thread's assigned user, or any admin.

Web dashboard uses the default session-authenticated `/broadcasting/auth`;
mobile uses the Sanctum-guarded API route.

## Events

| Event | Channels | Fired from |
|---|---|---|
| `ThreadMessageCreated` | `thread.{id}` + `user.{assigned}` | `ThreadSyncer` import, mobile `MessageController::store` |
| `ThreadAssigned` | `user.{to}` + `user.{from}` | `ThreadAssigner::assign` (all types) |
| `ThreadReadStateChanged` | `thread.{id}` | `MarkThreadReadJob` after success |

Payloads reuse `ThreadMessageResource`-style arrays (sender_name, is_read,
etc.) so mobile renders socket messages identically to fetched ones.

## Env

```
BROADCAST_DRIVER=pusher
PUSHER_APP_ID=crawler
PUSHER_APP_KEY=crawler-key
PUSHER_APP_SECRET=<random>
PUSHER_HOST=soketi          # public host for clients: server IP / domain
PUSHER_PORT=6001
PUSHER_SCHEME=http
```

## Tests

- Channel auth: user can join own `user.{id}`, not others'; assigned user
  and admin can join `thread.{id}`, others 403; guest 401.
- Events dispatched with correct channels/payload from syncer, mobile send,
  assigner, and mark-read job (`Event::fake`).

## Out of scope

- Presence channels / typing indicators.
- Dashboard Echo integration (later; channels already authorize admins).
- Client-side (Flutter) implementation — connection notes provided.
