# Crawler — Agent Brief

> Read by the BA, PM, developer and QA agents before any work. Keep it accurate. Update the Backlog and Decisions sections as work happens.

## What it is
Crawler is FlutterArc's internal lead-hunting and bid-management system for freelance marketplaces (Freelancer.com first, Upwork second). It polls new projects every minute, filters them by keyword/negative-keyword/country rules, qualifies them with OpenAI, places or proposes bids, tracks awards and bid-market data, and syncs Freelancer chat threads so agents can reply from a web dashboard or a Flutter mobile app ("Alladin", with FCM push and optional AI auto-replies). A companion Chrome extension scrapes Freelancer insight/gamification pages and posts them to ingest endpoints for statistics, leaderboard and insights pages. Users are admins (full dashboard) and mobile agents (chats only).

## Stack
- PHP ^8.1 (composer platform 8.2, Sail image `docker/8.2`), Laravel ^10, Sanctum ^3.2, Pusher PHP server ^7.2 (via Soketi websockets), kreait/firebase-php ^7 (FCM), doctrine/dbal, Guzzle. OpenAI is called directly over HTTP (`api.openai.com/v1/chat/completions`), no SDK.
- MySQL 8.0, Redis (cache/session), `database` queue driver with a dedicated worker, Laravel scheduler, Soketi (Pusher protocol) for realtime, Mailpit in dev.
- Frontend: Blade + Frest admin template (Bootstrap 5, Laravel Mix 6, jQuery/DataTables). `package.json` name `frest`.
- Chrome extension (`extension/`, Manifest V3, ES modules, tests on `node:test`).
- Package managers: Composer, npm. Single Laravel repo `Naveed-dev30/crawler`, branch `main`.

## Layout
```
crawler/
  docker-compose.yml          # laravel.test (app :APP_PORT->80), scheduler, queue-worker, thread-sync, mysql, redis, soketi, mailpit
  docker/8.2/                 # Sail-style PHP image used by all app services
  app/Console/Commands/       # proposals:fetch, bids:check-awards, insights:enrich-bids, threads:sync, threads:escalate,
                              # profiles:sync, upwork:fetch, upwork:auth, ai:probe, ai:reply-now, seed:test-thread, backfills
  app/Console/Kernel.php      # schedule (fetch every minute, awards every 30m, enrich hourly, escalate 2m, upwork 30m, ai probe 5m)
  app/Jobs/                   # OpenAIJob, GenerateAiReplyJob, BidNowJob, FineTuneBidJob, SummarizeReasonJob, SendFcmPushJob, ...
  app/Services/               # ProposalQualifier, AiGate, AiReplyGenerator, ThreadSyncer/Allocator/Assigner/Escalator,
                              # FreelancerMessenger/ProfileClient/UserClient, UpworkClient, FcmPusher, BidAwardChecker ...
  app/Services/Fake/          # FL_FAKE=true stand-ins (no network)
  app/Http/Controllers/       # web dashboard: Statistics, Bid, Proposal, Review, Filter, Chat, Insights, BidInsights,
                              # Gamification, Upwork, UserManagement, Attachment
  app/Http/Controllers/Api/V1/Mobile/  # mobile JSON API + Concerns/RespondsMobile envelope
  app/Http/Middleware/        # EnsureAdmin, EnsureMobile, EnsureChatAccess, EnsureIngestToken, RecordMobileApiActivity, RestrictMobileToChats
  app/Http/Requests, Resources, Policies, Events (ThreadMessageCreated etc.), Support/
  app/Models/                 # Bid, Proposal, Filter, Keyword, NegativeKeyword, Thread, ThreadMessage, ThreadAttachment,
                              # BidInsight(+Change), InsightSnapshot, GamificationSnapshot, FreelancerProfile, UpworkJob, User, DeviceToken ...
  routes/web.php, api.php, channels.php
  database/migrations/ (67)   # up to 2026_08_17; seeders for bids/filters/keywords/countries/currencies/upwork jobs
  resources/views/content/pages/  # stats, bids, upwork, review, filters, chats, insights, leaderboard, users
  tests/Feature (100+), tests/Unit, tests/Fixtures
  extension/                  # background.js, interceptor.js, lib/, tests/, options page
  docs/superpowers/plans/     # dated implementation plans (gitignored, local only)
  db.sql/                     # empty dir now; compose expects a ./db.sql dump file for first-boot import
```

## How to run
- Copy `.env.example` to `.env`. Key names beyond stock Laravel: `GAMIFICATION_INGEST_TOKEN`, `INGEST_TOKEN`, `OPENAI_API_KEY`, `FL_BASE_URL`, `FL_ACCESS`, `FL_USER_ID`, `FL_FAKE`, `AI_AUTO_REPLY_ENABLED`, `FIREBASE_CREDENTIALS` (path to service-account JSON), `UPWORK_*` (BASE_URL, OAUTH_TOKEN_URL, CLIENT_ID, CLIENT_SECRET, ACCESS_TOKEN, REFRESH_TOKEN, TENANT_ID, REDIRECT_URI), `PUSHER_*` (Soketi), `SLACK_*`, `APP_PORT`, `FORWARD_*_PORT`, `WWWUSER`/`WWWGROUP`.
- Full stack: `docker compose up -d` — app on `http://localhost:${APP_PORT:-8000}`, MySQL on 127.0.0.1:3306, Redis 6379, Soketi 6001, Mailpit UI 8025. The app container runs `composer install` then `php artisan serve`; scheduler, queue-worker and thread-sync containers wait for vendor then start `schedule:work`, `queue:work`, `threads:sync`.
- First boot with an empty volume imports `./db.sql` if it is a file; otherwise run `docker compose exec laravel.test php artisan migrate --seed`.
- Sail shortcuts also work: `./vendor/bin/sail up -d`, `./vendor/bin/sail artisan ...`.
- Dev without live APIs: set `FL_FAKE=true` (fake Freelancer + AI services bound in `AppServiceProvider`).
- Upwork OAuth bootstrap: visit `/uw/oauth` callback, then `php artisan upwork:auth --code=...`.
- Assets (only when touching Blade/JS): `npm install && npm run dev`.

## How to verify
- Install: `composer install` (or via compose above)
- Lint/format: `./vendor/bin/pint` (Laravel preset; `.styleci.yml` also = laravel preset). `.editorconfig` = 2 spaces, LF.
- Typecheck: none configured (no PHPStan/Larastan).
- PHP tests: `./vendor/bin/phpunit` or `./vendor/bin/sail test` (sqlite in-memory, `FL_FAKE=false`, queue sync, broadcast log — all set in `phpunit.xml`). Run a single file: `./vendor/bin/phpunit tests/Feature/ThreadSyncerTest.php`.
- Extension tests: `cd extension && node --test tests/`
- Build: `npm run prod` for assets. No CI workflow in the repo (`.github/` absent) — run tests locally before merging.
- Manual smoke: log in at `/login`, confirm `/stats` renders, `POST /api/v1/mobile/login` returns `{success:true,data:{token,...}}`, `php artisan proposals:fetch` completes with `FL_FAKE=true`.

## Conventions
- Work is planned in `docs/superpowers/plans/*.md` (TDD, task-by-task, checkbox steps) and executed with feature tests first; keep that habit — every change ships with a `tests/Feature/*Test.php`.
- Mobile API (`/api/v1/mobile/*`): JSON only, Sanctum bearer tokens, `RespondsMobile` envelope `{success, message, data, [meta|errors]}`; `okPaginated()` for lists; 401 `{"message":"Unauthenticated."}`, 422 `{message, errors}`; never redirect or 500 on bad input. Multi-device tokens: login does not revoke others, logout revokes only the current one. Resources never expose `password`/`remember_token`.
- Web dashboard routes are session-auth (`auth` + `mobile.chats-only`); settings under `admin` middleware; chats under `chats.access` (admins see all, agents only assigned threads). Do not move chat routes into the admin group.
- Ingest endpoints (`/api/gamification/ingest`, `/api/insights/ingest`, `/api/insights/bids/ingest`) are guarded by `gamification.token` middleware (`GAMIFICATION_INGEST_TOKEN`).
- Long-running/AI work goes through Jobs on the database queue; scheduled commands use `runInBackground()->withoutOverlapping()`. AI calls are gated by `AiGate` (auto-disable on rate limit, `ai:probe` re-enables).
- Realtime: events under `app/Events` broadcast via `Support/SafeBroadcast`; private channels in `routes/channels.php`; mobile socket auth at `POST /api/broadcasting/auth`.
- Push: `config/push.php` channel ids and sound keys must stay in lock-step with the Flutter app's NotificationConstants; placeholder FCM tokens are never stored.
- Attachments are served by signed URLs (`attachments.show`, `signed` middleware) with no session auth by design.
- Migrations: additive timestamped files; `doctrine/dbal` is present for column changes; index-adding migrations are separate files (see `add_lookup_indexes_for_mobile_queries`).
- Freelancer config lives in `config/variables.php` (`flKey`, `flUserId`, `flBase`, `flFake`); read through `config()`, never `env()` outside config files.
- Legacy routes `/notify`, `/secret-endpoint-verify`, `/pro` in `web.php` are unauthenticated debugging leftovers — do not build on them.

## Integrations & environments
- Freelancer.com API (projects, bids, messages, profiles; sandbox via `FL_BASE_URL`), Upwork API (OAuth2, `upwork:fetch` jobs), OpenAI chat completions (qualification, reason summaries, reply generation, thread allocation), Firebase Cloud Messaging (mobile push), Soketi/Pusher (web + mobile realtime), Slack tokens present in `.env` (usage not confirmed), Mailpit (dev mail).
- Chrome extension posts captured pages to the ingest endpoints; its target URL/token are set on its options page.
- Consumers: the web dashboard (Blade) and the Flutter mobile app (separate repo, not in this workspace).
- Deployment: Docker Compose on a server (compose comments note MySQL is loopback-only); no CI/CD or staging described in the repo.

## Known issues / tech debt
- No CI; 100+ feature tests exist but only run locally. `.phpunit.result.cache` is committed in the working tree (gitignored pattern exists).
- `README.md` is the stock Laravel readme; the real docs are the gitignored `docs/superpowers/plans/`.
- Unauthenticated debug routes in `web.php` (`/notify`, `/secret-endpoint-verify`, `/pro`) and a commented `// return $request;` in the auth route.
- `db.sql` is a directory, not the dump file compose expects, so first-boot import is a no-op.
- Frest template baggage (large npm dependency list, `Helpers.php` layout code) in an app that is mostly server-rendered dashboards.
- Real-valued `.env` and `storage/app/firebase/service-account.json` live locally — never read or print their values.

## Backlog
- (none yet)

## Decisions log
- (none yet)
