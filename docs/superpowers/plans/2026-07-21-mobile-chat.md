# Mobile Chat Feature — Freelancer Thread Sync, AI Assignment, Escalation

## Context

Clients who receive our bids message us on Freelancer.com. Nobody sees those messages until someone checks the site. This feature gives mobile team members a chat app: threads sync from Freelancer every 10 seconds, an OpenAI matcher assigns each new thread to the best-fit mobile user, FCM pushes notify them, and an escalation ladder reassigns ignored threads up the chain. Admin manages mobile users from a new dashboard tab.

**Decisions locked with user:**
- Sync only threads whose project matches a `proposals.project_id` (we bid on it)
- Firebase service-account credentials available (FCM HTTP v1)
- Attachments: store Freelancer URL + metadata only, no downloads
- Switch `QUEUE_CONNECTION` sync → database, add queue-worker service
- `escalation_ladder` unique across mobile users (form offers unused numbers only)
- Escalation timer resets on each escalation (`last_escalated_at`)
- Users tab: admin only
- Block = no FCM + no escalation; sync continues storing messages
- Thread status: `fresh` → `answered` on first user reply, **stays answered forever** (no reopen on new client message; escalation only for never-answered threads)

**Freelancer endpoints** (confirmed from official SDK `freelancer/freelancer-sdk-python`):
- `GET /api/messages/0.1/threads/` — list (header `Freelancer-OAuth-V1`)
- `GET /api/messages/0.1/messages/` — messages (thread filter, limit/offset)
- `POST /api/messages/0.1/threads/{id}/messages/` — send; form-encoded `{message}` or multipart `attachments[]` + files

## 0. Docs first + dev/prod Freelancer strategy

**Before coding:** write spec to `docs/superpowers/specs/2026-07-21-mobile-chat-design.md` and implementation plan to `docs/superpowers/plans/2026-07-21-mobile-chat.md` (repo convention, see `2026-07-15-award-tracking*`), commit them.

**Dev environment must exercise real Freelancer-shaped data so prod works unchanged:**
- All Freelancer messaging URLs built from `config('variables.flBase')` ← env `FL_BASE_URL`, default `https://www.freelancer.com`. Zero code change between environments — only env.
- Development options (both supported by the same env switch):
  1. **Freelancer official sandbox** — `FL_BASE_URL=https://www.freelancer-sandbox.com` + sandbox OAuth key (`FL_ACCESS`). Real API behavior, safe test account; threads/messages can be created there from a second sandbox account to simulate clients.
  2. **Live account read-only dev** — point at production URL with real key; sync only reads threads/messages (safe); skip send tests.
- Feature tests use `Http::fake` fixtures whose JSON is copied verbatim from real API responses (`result.threads[]`, `result.messages[]` with `attachments[]`) — captured once via curl during implementation and stored under `tests/fixtures/freelancer/`. Guarantees parser matches production shape.

## 1. Migrations (all additive, `database/migrations/2026_07_21_*`)

1. `users` add: `profile_prompt` TEXT nullable, `escalation_ladder` unsignedTinyInt nullable UNIQUE (NULLs allowed for admin/team), `fcm_token` string(512) nullable
2. `filters` add: `escalation_minutes` unsignedInt default 30
3. `threads`: `freelancer_thread_id` UNIQUE, `project_id` (FL id, join key), `proposal_id` FK, `assigned_user_id` FK nullable, `status` string default 'fresh' (`fresh`|`answered`), `blocked` bool default false, `last_client_message_at` ts nullable, `last_escalated_at` ts nullable, `freelancer_time_updated` bigint default 0, index(`status`,`blocked`)
4. `thread_messages`: `thread_id` FK, `freelancer_message_id` nullable UNIQUE (dedupe), `direction` (`received`|`sent`), `from_freelancer_user_id` nullable, `sender_user_id` FK nullable, `message` longText nullable, `message_time` ts, index(`thread_id`,`message_time`)
5. `thread_attachments`: `thread_message_id` FK, `freelancer_attachment_id`, `filename`, `url` text (FL URL), `mime_type`, `size`
6. `activity_logs` (model `ActivityLog` — NOT `Log`, avoids facade collision): `thread_id` FK, `from_user_id`, `to_user_id`, `type` (`escalation`|`manual_assign`), `message` string
7. `mobile_notifications` (leaves `notifications` free for Laravel channel): `user_id` FK, `thread_id` FK nullable, `title`, `body`, `read_at` nullable
8. `jobs` table (`php artisan queue:table`) — needed for database queue

New models + factories: `Thread`, `ThreadMessage`, `ThreadAttachment`, `ActivityLog`, `MobileNotification`. `User`: add fillables, hide `fcm_token`, `isMobile()`, `scopeMobile()`.

## 2. Services / Jobs / Commands

**Services (`app/Services/`):**
- `FreelancerMessenger` — HTTP client: `fetchThreads()`, `fetchMessages()`, `sendMessage(flThreadId, ?text, attachments=[])`. Style of `BidAwardChecker.php` (timeout, Log::warning, never throw).
- `ThreadSyncer` — one 10s pass: fetch threads → keep only `context.type=project` with project_id in proposals → new thread: create `fresh`, import received-only messages, dispatch `AssignThreadJob`; known thread: if `time_updated` newer, import new messages where `from_user != flUserId` (dedupe by `freelancer_message_id` unique), store attachment metadata, bump `last_client_message_at`. Blocked threads still sync. `Cache::lock('threads:sync', 15)` per pass.
- `ThreadMatcher` — clone of `ProposalQualifier.php` (gpt-3.5-turbo, MAX_ATTEMPTS 2, temp 0, fence/prose-tolerant JSON parse, fail-closed null). `match(title, description, [user_id => profile_prompt]): ?int`. System prompt: profiles with IDs, reply ONLY `{"user_id": <id>}`; returned id must exist in map else retry/null.
- `ThreadAssigner` — single path for ALL assignment (AI, escalation, manual): set `assigned_user_id`, (escalation) `last_escalated_at=now`, `ActivityLog` row ("thread {project_id} escalated from user(A) to user(B)"), `MobileNotification` rows, dispatch `SendFcmPushJob`(s). Manual assign gets identical log+notification treatment.
- `ThreadEscalator` — 2-min pass: `status=fresh AND blocked=false AND assigned_user_id NOT NULL`; ref time = max(`last_client_message_at`,`last_escalated_at`,`created_at`); overdue vs `filters.escalation_minutes` → find user with `escalation_ladder = current+1`; none → skip (top of ladder); else assign via `ThreadAssigner` (notifies BOTH users).
- `FcmPusher` — kreait/firebase-php ^7 (handles OAuth2/JWT for HTTP v1; do NOT hand-roll). `sendToUser(User, title, body, data)`; empty token → no-op; UNREGISTERED response → null out stale token. Config `services.firebase.credentials` ← env `FIREBASE_CREDENTIALS`. Container-injected so tests mock it.

**Jobs (queued, database driver):** `AssignThreadJob(threadId)` — matcher + assign; null match → fallback assign ladder-1 user; no mobile users → leave unassigned + warn. `SendFcmPushJob(userId, title, body, data)` — tries 3.

**Commands:** `SyncThreads` (`threads:sync {--once}`) — own 10s loop (`usleep` remainder), NOT Laravel scheduler (minute-granular in L10); runs as dedicated compose service. `EscalateThreads` (`threads:escalate`) — Kernel: `everyTwoMinutes()->runInBackground()->withoutOverlapping(5)`.

**docker-compose:** two new services cloned from `scheduler` block: `queue-worker` (`queue:work --tries=3 --backoff=10 --sleep=3 --max-time=3600`) and `thread-sync` (`threads:sync`). `.env`: `QUEUE_CONNECTION=database`, `FIREBASE_CREDENTIALS=/var/www/html/storage/app/firebase/service-account.json` (dir gitignored).

## 3. Mobile API (`routes/api.php`, prefix `v1/mobile`)

New middleware `EnsureMobile` (clone `EnsureAdmin`), alias `mobile`. Controllers under `app/Http/Controllers/Api/V1/Mobile/`. Existing `/api/v1/login` untouched.

| Method | Path | Notes |
|---|---|---|
| POST | `/mobile/login` | email+password+fcm_token(required)+device_name; non-mobile role → 403 after password check; updates fcm_token; Sanctum token |
| GET | `/mobile/threads` | mine, unblocked; `?blocked=1` → blocked list; eager `proposal.bid`; order `last_client_message_at` desc |
| GET | `/mobile/threads/{thread}` | own-thread 403 guard; includes proposal + bid cover_letter |
| GET/POST | `/mobile/threads/{thread}/messages` | history; send → `FreelancerMessenger::sendMessage` inline, on success store `sent` row + status `fresh`→`answered`, on FL failure 502 store nothing; attachments multipart |
| POST | `/mobile/threads/{thread}/block`, `/unblock` | flags only |
| POST | `/mobile/threads/{thread}/assign` | body `{user_id}` (mobile, not self) → `ThreadAssigner` `manual_assign` |
| GET | `/mobile/logs` | ActivityLog where from_user_id=me OR to_user_id=me, paginated |
| GET | `/mobile/notifications` + POST `/{id}/read` | mine only |
| GET | `/mobile/users` | mobile users excluding me (assign dropdown) |

Resources: `ThreadResource`, `ThreadMessageResource` (embeds attachments), `MobileNotificationResource`, `ActivityLogResource`.

## 4. Web UI

**Users tab (admin):** menu entry in `resources/menu/verticalMenu.json` (`access: admin` — hiding already handled by `verticalMenu.blade.php`). Routes in `web.php` admin group: `GET/POST /users` → `UserManagementController`. View `resources/views/content/pages/users.blade.php`: table (name, email, role badge, ladder, FCM?, created) + Bootstrap modal form (FullName, Email, Password, Profile prompt, Ladder select of UNUSED numbers only), role forced `mobile`, validation `escalation_ladder unique:users`, errors re-open modal. Toast pattern from `filters.blade.php`.

**Filters:** escalation dropdown in right column of `filters.blade.php`: 30 min/2 h/8 h/1 day → `FilterController::update` whitelists [30,120,480,1440] else 30.

## 5. Tests (patterns: `ProposalQualifierTest`, `OpenAIJobGateTest` — Http::fake, Queue::fake, mock FcmPusher)

- `UsersPageTest` — admin CRUD, ladder uniqueness, 403s
- `MobileLoginTest` — token + fcm_token upsert; non-mobile 403
- `ThreadSyncerTest` — proposal-match filter, skip known, received-only, dedupe, attachments, AssignThreadJob dispatched for new only, blocked still syncs
- `ThreadMatcherTest` — mirror qualifier test (fences, prose, bad id, 500, retry count)
- `AssignThreadJobTest` — match/fallback-ladder-1/no-users
- `ThreadEscalationTest` — Carbon::setTestNow: overdue→ladder+1, timer reset, blocked/answered skipped, top-of-ladder skip, log text, 2 notifications+2 pushes
- `Api/Mobile*ApiTest` — scoping, send success/failure, block/assign, logs/notifications/users filters
- `FilterEscalationMinutesSaveTest` — whitelist fallback

## 6. Deployment

1. `composer require kreait/firebase-php:^7`
2. Firebase service-account JSON → `storage/app/firebase/service-account.json` (gitignored)
3. `.env`: `QUEUE_CONNECTION=database`, `FIREBASE_CREDENTIALS=...`
4. `php artisan migrate` (8 additive migrations)
5. `docker compose up -d --build` (new `queue-worker` + `thread-sync` services; `scheduler` picks up `threads:escalate`)
6. Create mobile users, ladder 1 first (matcher fallback target)
7. Smoke: `threads:sync --once`; verify threads/messages rows, jobs drain, real FCM push

## Verification

- `php artisan test` — full suite
- `threads:sync --once` against live FL account with a seeded proposal → thread row + assignment + push
- Mobile API walkthrough via curl: login → threads → messages → send → block → assign
- `php artisan schedule:list` shows `threads:escalate` every 2 min
- Time-travel escalation test covers ladder mechanics

## Risks / flagged for implementation

1. **Attachment URLs may need OAuth header** — verify; fallback: streaming proxy endpoint `GET /mobile/attachments/{id}` (schema already supports, no migration needed)
2. FL rate limits at 10s cadence — use `from_updated_time` param + per-thread `time_updated` gating; backoff on 429
3. Ladder gaps (deleted user) strand escalation at that level — per spec ("none → skip"); future: jump to next-higher ladder
4. Threads synced before any mobile user exists stay unassigned (warned in logs)
5. Single fcm_token per user — last login wins (accepted)
