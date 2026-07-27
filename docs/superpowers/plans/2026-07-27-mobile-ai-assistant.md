# Mobile AI Assistant Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add four per-mobile-user API capabilities — FCM token refresh, enriched single-thread client info, an AI-assistant enable/disable toggle, and an auto-schedule window — plus the reactive auto-reply pipeline they gate, with a race-free toggle/schedule interaction.

**Architecture:** The AI on/off state is never stored as a fought-over boolean. `users` holds the schedule window and an optional manual override (value + UTC expiry); the effective state is computed on demand by `User::aiActiveNow()`. No cron writes a flag. When a client message is synced, `ThreadSyncer` checks the assigned user's `aiActiveNow()` and dispatches `GenerateAiReplyJob`, which generates a reply via OpenAI and sends it through a shared `SendThreadMessage` service (same path as manual sends), tagged `sent_by_ai=true`.

**Tech Stack:** Laravel 10, Sanctum, PHPUnit, Carbon, OpenAI (chat completions via `Http`), existing `FreelancerMessenger` (faked in tests via `Http::fake` / `FakeFreelancerMessenger`).

## Global Constraints

- PHP `^8.1`, Laravel `^10.0`. No new Composer dependencies.
- All mobile endpoints live under the existing `Route::prefix('v1')->prefix('mobile')` group in `routes/api.php`, behind `['auth:sanctum', 'mobile']`.
- Responses use the `RespondsMobile` trait envelope (`ok`/`fail`/`okPaginated`): `{ success, message, data, [errors|meta] }`.
- Tests: `Tests\TestCase` + `RefreshDatabase`; authenticate with `Laravel\Sanctum\Sanctum::actingAs($user)`; mobile users are `User::factory()->create(['role' => 'mobile', 'escalation_ladder' => N])` (ladder is unique-nullable — give distinct values when creating several).
- OpenAI calls follow the `ThreadMatcher` idiom: `config('variables.openAIKey')`, model `gpt-3.5-turbo`, bounded retries, never throw, return null on failure. Faked in tests with `Http::fake(['https://api.openai.com/*' => ...])`.
- Timestamps stored/compared in UTC; window times interpreted in the user's `ai_timezone`.
- Run the full suite with `./vendor/bin/phpunit`; a single test with `./vendor/bin/phpunit --filter test_name`.

---

## File Structure

- `database/migrations/2026_07_27_000000_add_ai_assistant_to_users_table.php` — new AI columns on `users`.
- `database/migrations/2026_07_27_000100_add_sent_by_ai_to_thread_messages.php` — `sent_by_ai` flag.
- `app/Models/User.php` — fillable/casts + `withinWindow`, `nextBoundaryAfter`, `aiActiveNow`.
- `app/Models/ThreadMessage.php` — fillable + cast for `sent_by_ai`.
- `app/Http/Controllers/Api/V1/Mobile/AuthController.php` — add `updateFcmToken`.
- `app/Http/Controllers/Api/V1/Mobile/AiAssistantController.php` — new: `show`, `toggle`, `schedule`.
- `app/Http/Resources/ThreadResource.php` — add `client` block.
- `app/Http/Resources/ThreadMessageResource.php` — expose `sent_by_ai`.
- `app/Http/Controllers/Api/V1/Mobile/ThreadController.php` — load BidInsight in `show`.
- `app/Services/SendThreadMessage.php` — new: shared send+persist path.
- `app/Http/Controllers/Api/V1/Mobile/MessageController.php` — refactor `store` onto the service.
- `app/Services/AiReplyGenerator.php` — new: OpenAI reply text.
- `app/Jobs/GenerateAiReplyJob.php` — new: reactive reply job.
- `app/Services/ThreadSyncer.php` — dispatch the job on inbound messages.
- `routes/api.php` — register the new routes.

---

## Task 1: Schema + model attributes

**Files:**
- Create: `database/migrations/2026_07_27_000000_add_ai_assistant_to_users_table.php`
- Create: `database/migrations/2026_07_27_000100_add_sent_by_ai_to_thread_messages.php`
- Modify: `app/Models/User.php`
- Modify: `app/Models/ThreadMessage.php`
- Test: `tests/Feature/Api/AiAssistantSchemaTest.php`

**Interfaces:**
- Produces: `users` columns `ai_schedule_enabled` (bool), `ai_window_start` (time,null), `ai_window_end` (time,null), `ai_timezone` (string,null), `ai_manual_state` (bool,null), `ai_manual_until` (datetime,null). `thread_messages.sent_by_ai` (bool, default false). `User` casts `ai_schedule_enabled`→bool, `ai_manual_state`→bool, `ai_manual_until`→datetime; all six in `$fillable`. `ThreadMessage` casts `sent_by_ai`→bool and adds it to `$fillable`.

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/Feature/Api/AiAssistantSchemaTest.php
namespace Tests\Feature\Api;

use App\Models\Thread;
use App\Models\ThreadMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AiAssistantSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_persists_ai_columns_with_casts(): void
    {
        $user = User::factory()->create([
            'role' => 'mobile',
            'ai_schedule_enabled' => true,
            'ai_window_start' => '00:00:00',
            'ai_window_end' => '17:00:00',
            'ai_timezone' => 'Asia/Karachi',
            'ai_manual_state' => false,
            'ai_manual_until' => now()->addHours(3),
        ])->fresh();

        $this->assertTrue($user->ai_schedule_enabled);
        $this->assertFalse($user->ai_manual_state);
        $this->assertInstanceOf(\Illuminate\Support\Carbon::class, $user->ai_manual_until);
        $this->assertSame('Asia/Karachi', $user->ai_timezone);
    }

    public function test_thread_message_has_sent_by_ai_flag(): void
    {
        $thread = Thread::factory()->create();
        $msg = ThreadMessage::factory()->create([
            'thread_id' => $thread->id,
            'sent_by_ai' => true,
        ])->fresh();

        $this->assertTrue($msg->sent_by_ai);
    }

    public function test_sent_by_ai_defaults_false(): void
    {
        $thread = Thread::factory()->create();
        $msg = ThreadMessage::factory()->create(['thread_id' => $thread->id])->fresh();

        $this->assertFalse($msg->sent_by_ai);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/phpunit --filter AiAssistantSchemaTest`
Expected: FAIL — unknown columns `ai_schedule_enabled` / `sent_by_ai`.

- [ ] **Step 3: Write the users migration**

```php
<?php
// database/migrations/2026_07_27_000000_add_ai_assistant_to_users_table.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('ai_schedule_enabled')->default(false)->after('fcm_token');
            $table->time('ai_window_start')->nullable()->after('ai_schedule_enabled');
            $table->time('ai_window_end')->nullable()->after('ai_window_start');
            $table->string('ai_timezone')->nullable()->after('ai_window_end');
            $table->boolean('ai_manual_state')->nullable()->after('ai_timezone');
            $table->timestamp('ai_manual_until')->nullable()->after('ai_manual_state');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'ai_schedule_enabled', 'ai_window_start', 'ai_window_end',
                'ai_timezone', 'ai_manual_state', 'ai_manual_until',
            ]);
        });
    }
};
```

- [ ] **Step 4: Write the thread_messages migration**

```php
<?php
// database/migrations/2026_07_27_000100_add_sent_by_ai_to_thread_messages.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('thread_messages', function (Blueprint $table) {
            $table->boolean('sent_by_ai')->default(false)->after('is_read');
        });
    }

    public function down(): void
    {
        Schema::table('thread_messages', function (Blueprint $table) {
            $table->dropColumn('sent_by_ai');
        });
    }
};
```

- [ ] **Step 5: Update `User` model**

Add the six columns to `$fillable` and extend `$casts`:

```php
// in $fillable, after 'fcm_token':
        'ai_schedule_enabled',
        'ai_window_start',
        'ai_window_end',
        'ai_timezone',
        'ai_manual_state',
        'ai_manual_until',
```

```php
    protected $casts = [
        'email_verified_at' => 'datetime',
        'ai_schedule_enabled' => 'boolean',
        'ai_manual_state' => 'boolean',
        'ai_manual_until' => 'datetime',
    ];
```

- [ ] **Step 6: Update `ThreadMessage` model**

Add `'sent_by_ai'` to `$fillable` and `'sent_by_ai' => 'boolean'` to `$casts`.

- [ ] **Step 7: Run test to verify it passes**

Run: `./vendor/bin/phpunit --filter AiAssistantSchemaTest`
Expected: PASS.

- [ ] **Step 8: Commit**

```bash
git add database/migrations/2026_07_27_000000_add_ai_assistant_to_users_table.php \
        database/migrations/2026_07_27_000100_add_sent_by_ai_to_thread_messages.php \
        app/Models/User.php app/Models/ThreadMessage.php \
        tests/Feature/Api/AiAssistantSchemaTest.php
git commit -m "feat: add AI assistant columns and sent_by_ai flag"
```

---

## Task 2: Computed AI state on `User`

**Files:**
- Modify: `app/Models/User.php`
- Test: `tests/Unit/UserAiStateTest.php`

**Interfaces:**
- Consumes: the `users` AI columns from Task 1.
- Produces:
  - `User::withinWindow(\Illuminate\Support\Carbon $now): bool` — is `$now` (converted to `ai_timezone`, default `config('app.timezone')`) inside `[ai_window_start, ai_window_end)`, handling overnight wrap.
  - `User::nextBoundaryAfter(\Illuminate\Support\Carbon $now): ?\Illuminate\Support\Carbon` — next window edge strictly after `$now`, returned in UTC; `null` when `ai_schedule_enabled` is false or window times are null.
  - `User::aiActiveNow(\Illuminate\Support\Carbon $now): bool` — override-wins-then-schedule computed state.

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/Unit/UserAiStateTest.php
namespace Tests\Unit;

use App\Models\User;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\TestCase;

class UserAiStateTest extends TestCase
{
    private function user(array $attrs): User
    {
        $u = new User();
        $u->forceFill(array_merge([
            'ai_schedule_enabled' => false,
            'ai_window_start' => null,
            'ai_window_end' => null,
            'ai_timezone' => 'UTC',
            'ai_manual_state' => null,
            'ai_manual_until' => null,
        ], $attrs));
        return $u;
    }

    private function at(string $utc): Carbon
    {
        return Carbon::parse($utc, 'UTC');
    }

    public function test_within_same_day_window(): void
    {
        $u = $this->user(['ai_schedule_enabled' => true, 'ai_window_start' => '09:00:00', 'ai_window_end' => '17:00:00']);
        $this->assertTrue($u->withinWindow($this->at('2026-07-27 10:00:00')));
        $this->assertFalse($u->withinWindow($this->at('2026-07-27 08:00:00')));
        $this->assertFalse($u->withinWindow($this->at('2026-07-27 17:00:00'))); // end exclusive
    }

    public function test_within_overnight_window(): void
    {
        $u = $this->user(['ai_schedule_enabled' => true, 'ai_window_start' => '22:00:00', 'ai_window_end' => '06:00:00']);
        $this->assertTrue($u->withinWindow($this->at('2026-07-27 23:00:00')));
        $this->assertTrue($u->withinWindow($this->at('2026-07-27 02:00:00')));
        $this->assertFalse($u->withinWindow($this->at('2026-07-27 12:00:00')));
    }

    public function test_within_window_respects_timezone(): void
    {
        // 00:00-17:00 Karachi (UTC+5). 20:00 UTC = 01:00 Karachi next day => inside.
        $u = $this->user(['ai_schedule_enabled' => true, 'ai_window_start' => '00:00:00', 'ai_window_end' => '17:00:00', 'ai_timezone' => 'Asia/Karachi']);
        $this->assertTrue($u->withinWindow($this->at('2026-07-27 20:00:00')));
        // 13:00 UTC = 18:00 Karachi => outside.
        $this->assertFalse($u->withinWindow($this->at('2026-07-27 13:00:00')));
    }

    public function test_active_now_schedule_only(): void
    {
        $u = $this->user(['ai_schedule_enabled' => true, 'ai_window_start' => '09:00:00', 'ai_window_end' => '17:00:00']);
        $this->assertTrue($u->aiActiveNow($this->at('2026-07-27 10:00:00')));
        $this->assertFalse($u->aiActiveNow($this->at('2026-07-27 20:00:00')));
    }

    public function test_live_override_beats_schedule(): void
    {
        $u = $this->user([
            'ai_schedule_enabled' => true, 'ai_window_start' => '00:00:00', 'ai_window_end' => '17:00:00',
            'ai_manual_state' => false, 'ai_manual_until' => $this->at('2026-07-27 17:00:00'),
        ]);
        // Inside window but override says off and not yet expired.
        $this->assertFalse($u->aiActiveNow($this->at('2026-07-27 02:00:00')));
    }

    public function test_expired_override_falls_back_to_schedule(): void
    {
        $u = $this->user([
            'ai_schedule_enabled' => true, 'ai_window_start' => '00:00:00', 'ai_window_end' => '17:00:00',
            'ai_manual_state' => false, 'ai_manual_until' => $this->at('2026-07-27 17:00:00'),
        ]);
        // At expiry the schedule is out-of-window anyway => false; before next start still schedule.
        $this->assertFalse($u->aiActiveNow($this->at('2026-07-27 18:00:00')));
        // Next day inside window, override expired => schedule true.
        $this->assertTrue($u->aiActiveNow($this->at('2026-07-28 02:00:00')));
    }

    public function test_sticky_override_without_schedule(): void
    {
        $u = $this->user(['ai_manual_state' => true, 'ai_manual_until' => null]);
        $this->assertTrue($u->aiActiveNow($this->at('2030-01-01 00:00:00')));
    }

    public function test_next_boundary_same_day(): void
    {
        $u = $this->user(['ai_schedule_enabled' => true, 'ai_window_start' => '09:00:00', 'ai_window_end' => '17:00:00']);
        $this->assertSame('2026-07-27T17:00:00+00:00', $u->nextBoundaryAfter($this->at('2026-07-27 10:00:00'))->toIso8601String());
        $this->assertSame('2026-07-27T09:00:00+00:00', $u->nextBoundaryAfter($this->at('2026-07-27 08:00:00'))->toIso8601String());
        $this->assertSame('2026-07-28T09:00:00+00:00', $u->nextBoundaryAfter($this->at('2026-07-27 18:00:00'))->toIso8601String());
    }

    public function test_next_boundary_null_when_no_schedule(): void
    {
        $u = $this->user(['ai_schedule_enabled' => false]);
        $this->assertNull($u->nextBoundaryAfter($this->at('2026-07-27 10:00:00')));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/phpunit --filter UserAiStateTest`
Expected: FAIL — `withinWindow` / `aiActiveNow` / `nextBoundaryAfter` undefined.

- [ ] **Step 3: Implement the three methods on `User`**

```php
    private function timezone(): string
    {
        return $this->ai_timezone ?: config('app.timezone', 'UTC');
    }

    public function withinWindow(\Illuminate\Support\Carbon $now): bool
    {
        if (! $this->ai_window_start || ! $this->ai_window_end) {
            return false;
        }
        $t = $now->copy()->setTimezone($this->timezone())->format('H:i:s');
        $start = (string) $this->ai_window_start;
        $end = (string) $this->ai_window_end;

        return $start <= $end
            ? ($t >= $start && $t < $end)   // same-day window
            : ($t >= $start || $t < $end);  // overnight wrap
    }

    public function nextBoundaryAfter(\Illuminate\Support\Carbon $now): ?\Illuminate\Support\Carbon
    {
        if (! $this->ai_schedule_enabled || ! $this->ai_window_start || ! $this->ai_window_end) {
            return null;
        }
        $tz = $this->timezone();
        $local = $now->copy()->setTimezone($tz);

        $edges = [];
        foreach ([-1, 0, 1] as $offset) {
            $day = $local->copy()->addDays($offset)->startOfDay();
            $edges[] = $day->copy()->setTimeFromTimeString((string) $this->ai_window_start);
            $edges[] = $day->copy()->setTimeFromTimeString((string) $this->ai_window_end);
        }
        $future = array_values(array_filter($edges, fn ($e) => $e->gt($local)));
        usort($future, fn ($a, $b) => $a->getTimestamp() <=> $b->getTimestamp());

        return $future === [] ? null : $future[0]->copy()->setTimezone('UTC');
    }

    public function aiActiveNow(\Illuminate\Support\Carbon $now): bool
    {
        if ($this->ai_manual_state !== null
            && ($this->ai_manual_until === null || $now->lt($this->ai_manual_until))) {
            return (bool) $this->ai_manual_state;
        }

        return $this->ai_schedule_enabled && $this->withinWindow($now);
    }
```

Note: `ai_window_start`/`ai_window_end` on an unsaved `forceFill`ed model are plain strings; on a hydrated model they are also strings (no `time` cast). Casting to `(string)` keeps both paths identical.

- [ ] **Step 4: Run test to verify it passes**

Run: `./vendor/bin/phpunit --filter UserAiStateTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Models/User.php tests/Unit/UserAiStateTest.php
git commit -m "feat: compute AI active state from window and override"
```

---

## Task 3: FCM refresh endpoint

**Files:**
- Modify: `app/Http/Controllers/Api/V1/Mobile/AuthController.php`
- Modify: `routes/api.php`
- Test: `tests/Feature/Api/MobileFcmTokenTest.php`

**Interfaces:**
- Produces: `POST /api/v1/mobile/fcm-token` → `AuthController@updateFcmToken`. Body `{ fcm_token: required|string|max:512 }`. Returns `ok(null, 'FCM token updated.')`.

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/Feature/Api/MobileFcmTokenTest.php
namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MobileFcmTokenTest extends TestCase
{
    use RefreshDatabase;

    public function test_updates_token_for_mobile_user(): void
    {
        $user = User::factory()->create(['role' => 'mobile', 'fcm_token' => 'old']);
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/mobile/fcm-token', ['fcm_token' => 'new-token-123'])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSame('new-token-123', $user->fresh()->fcm_token);
    }

    public function test_requires_token(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'mobile']));
        $this->postJson('/api/v1/mobile/fcm-token', [])->assertStatus(422);
    }

    public function test_forbidden_for_non_mobile(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $this->postJson('/api/v1/mobile/fcm-token', ['fcm_token' => 'x'])->assertForbidden();
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/phpunit --filter MobileFcmTokenTest`
Expected: FAIL — route not defined (404/405).

- [ ] **Step 3: Add the controller method**

In `AuthController`, add:

```php
    public function updateFcmToken(Request $request)
    {
        $validated = $request->validate([
            'fcm_token' => 'required|string|max:512',
        ]);

        $user = $request->user();
        $user->fcm_token = $validated['fcm_token'];
        $user->save();

        return $this->ok(null, 'FCM token updated.');
    }
```

- [ ] **Step 4: Register the route**

In `routes/api.php`, inside the `->middleware(['auth:sanctum', 'mobile'])->group(...)` block, add:

```php
            Route::post('fcm-token', [\App\Http\Controllers\Api\V1\Mobile\AuthController::class, 'updateFcmToken']);
```

- [ ] **Step 5: Run test to verify it passes**

Run: `./vendor/bin/phpunit --filter MobileFcmTokenTest`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/Api/V1/Mobile/AuthController.php routes/api.php \
        tests/Feature/Api/MobileFcmTokenTest.php
git commit -m "feat: add mobile FCM token refresh endpoint"
```

---

## Task 4: Thread details — client block

**Files:**
- Modify: `app/Http/Controllers/Api/V1/Mobile/ThreadController.php`
- Modify: `app/Http/Resources/ThreadResource.php`
- Test: `tests/Feature/Api/MobileThreadClientInfoTest.php`

**Interfaces:**
- Consumes: `BidInsight` model (`project_id` unique, fields `client_country`, `client_rating`, `client_reviews`, `client_engagement` JSON).
- Produces: `GET /api/v1/mobile/threads/{thread}` response `data.client` = `{ country, rating, reviews, engagement }` or `null`. `ThreadController@show` passes the matched `BidInsight` (or null) to the resource via `$thread->setAttribute('client_insight', $insight)` before serializing.

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/Feature/Api/MobileThreadClientInfoTest.php
namespace Tests\Feature\Api;

use App\Models\BidInsight;
use App\Models\Thread;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MobileThreadClientInfoTest extends TestCase
{
    use RefreshDatabase;

    private User $me;

    protected function setUp(): void
    {
        parent::setUp();
        $this->me = User::factory()->create(['role' => 'mobile', 'escalation_ladder' => 1]);
        Sanctum::actingAs($this->me);
    }

    public function test_show_includes_client_block_from_bid_insight(): void
    {
        $thread = Thread::factory()->create(['assigned_user_id' => $this->me->id, 'project_id' => 40597933]);
        BidInsight::create([
            'project_id' => 40597933,
            'client_country' => 'Nigeria',
            'client_rating' => 5.0,
            'client_reviews' => 1,
            'client_engagement' => ['contacted' => 0, 'invited' => 0, 'completed' => 1],
            'last_scraped_at' => now(),
        ]);

        $res = $this->getJson("/api/v1/mobile/threads/{$thread->id}")->assertOk();

        $res->assertJsonPath('data.client.country', 'Nigeria');
        $res->assertJsonPath('data.client.reviews', 1);
        $res->assertJsonPath('data.client.engagement.completed', 1);
        $this->assertSame('5.00', (string) $res->json('data.client.rating'));
    }

    public function test_client_is_null_when_no_insight(): void
    {
        $thread = Thread::factory()->create(['assigned_user_id' => $this->me->id, 'project_id' => 999]);
        $this->getJson("/api/v1/mobile/threads/{$thread->id}")
            ->assertOk()
            ->assertJsonPath('data.client', null);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/phpunit --filter MobileThreadClientInfoTest`
Expected: FAIL — `data.client` missing.

- [ ] **Step 3: Load the insight in `ThreadController@show`**

Replace the body of `show` with:

```php
    public function show(Request $request, Thread $thread)
    {
        $this->authorizeThread($request, $thread);

        $thread->load(['proposal.bid']);
        $thread->setAttribute(
            'client_insight',
            \App\Models\BidInsight::where('project_id', $thread->project_id)->first()
        );

        return $this->ok(new ThreadResource($thread), 'Thread fetched successfully.');
    }
```

- [ ] **Step 4: Add the `client` block to `ThreadResource`**

Inside the returned array, after the `proposal` key, add:

```php
            'client' => $this->resource->client_insight ? [
                'country' => $this->resource->client_insight->client_country,
                'rating' => $this->resource->client_insight->client_rating,
                'reviews' => $this->resource->client_insight->client_reviews,
                'engagement' => $this->resource->client_insight->client_engagement,
            ] : null,
```

Note: `client_insight` is only set on `show`; on `index` the attribute is absent, so `$this->resource->client_insight` is null and `client` is `null` there — index payload is unchanged in shape aside from a null `client`.

- [ ] **Step 5: Run test to verify it passes**

Run: `./vendor/bin/phpunit --filter MobileThreadClientInfoTest`
Expected: PASS.

- [ ] **Step 6: Run the existing threads test to confirm no regression**

Run: `./vendor/bin/phpunit --filter MobileThreadsApiTest`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/Api/V1/Mobile/ThreadController.php \
        app/Http/Resources/ThreadResource.php \
        tests/Feature/Api/MobileThreadClientInfoTest.php
git commit -m "feat: include client info in single-thread response"
```

---

## Task 5: AI assistant read / toggle / schedule endpoints

**Files:**
- Create: `app/Http/Controllers/Api/V1/Mobile/AiAssistantController.php`
- Modify: `routes/api.php`
- Test: `tests/Feature/Api/MobileAiAssistantApiTest.php`

**Interfaces:**
- Consumes: `User::aiActiveNow`, `User::nextBoundaryAfter` (Task 2).
- Produces:
  - `GET /api/v1/mobile/ai-assistant` → `AiAssistantController@show`.
  - `PUT /api/v1/mobile/ai-assistant` → `AiAssistantController@toggle`. Body `{ enabled: required|boolean }`.
  - `PUT /api/v1/mobile/ai-assistant/schedule` → `AiAssistantController@schedule`. Body `{ enabled: required|boolean, start: required_if:enabled,true|date_format:H:i, end: required_if:enabled,true|date_format:H:i|different:start, timezone: required_if:enabled,true|timezone }`.
  - All three return the same state block: `{ active_now, schedule_enabled, window: {start,end,timezone}|null, manual_override: {state, until}|null }`.

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/Feature/Api/MobileAiAssistantApiTest.php
namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MobileAiAssistantApiTest extends TestCase
{
    use RefreshDatabase;

    private User $me;

    protected function setUp(): void
    {
        parent::setUp();
        $this->me = User::factory()->create(['role' => 'mobile', 'escalation_ladder' => 1]);
        Sanctum::actingAs($this->me);
    }

    public function test_show_defaults_to_inactive(): void
    {
        $this->getJson('/api/v1/mobile/ai-assistant')
            ->assertOk()
            ->assertJsonPath('data.active_now', false)
            ->assertJsonPath('data.schedule_enabled', false)
            ->assertJsonPath('data.window', null)
            ->assertJsonPath('data.manual_override', null);
    }

    public function test_schedule_sets_window_and_activates(): void
    {
        Carbon::setTestNow('2026-07-27 10:00:00'); // UTC inside 09-17

        $this->putJson('/api/v1/mobile/ai-assistant/schedule', [
            'enabled' => true, 'start' => '09:00', 'end' => '17:00', 'timezone' => 'UTC',
        ])->assertOk()
          ->assertJsonPath('data.schedule_enabled', true)
          ->assertJsonPath('data.window.start', '09:00')
          ->assertJsonPath('data.active_now', true);

        Carbon::setTestNow();
    }

    public function test_toggle_off_during_window_writes_expiring_override(): void
    {
        Carbon::setTestNow('2026-07-27 10:00:00');

        $this->me->forceFill([
            'ai_schedule_enabled' => true, 'ai_window_start' => '09:00:00',
            'ai_window_end' => '17:00:00', 'ai_timezone' => 'UTC',
        ])->save();

        $this->putJson('/api/v1/mobile/ai-assistant', ['enabled' => false])
            ->assertOk()
            ->assertJsonPath('data.active_now', false)
            ->assertJsonPath('data.manual_override.state', false);

        $fresh = $this->me->fresh();
        $this->assertFalse($fresh->ai_manual_state);
        $this->assertSame('2026-07-27T17:00:00+00:00', $fresh->ai_manual_until->toIso8601String());

        Carbon::setTestNow();
    }

    public function test_toggle_on_without_schedule_is_sticky(): void
    {
        $this->putJson('/api/v1/mobile/ai-assistant', ['enabled' => true])
            ->assertOk()
            ->assertJsonPath('data.active_now', true);

        $this->assertNull($this->me->fresh()->ai_manual_until);
    }

    public function test_schedule_validates_times(): void
    {
        $this->putJson('/api/v1/mobile/ai-assistant/schedule', [
            'enabled' => true, 'start' => '09:00', 'end' => '09:00', 'timezone' => 'UTC',
        ])->assertStatus(422);
    }

    public function test_forbidden_for_non_mobile(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $this->getJson('/api/v1/mobile/ai-assistant')->assertForbidden();
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/phpunit --filter MobileAiAssistantApiTest`
Expected: FAIL — routes not defined.

- [ ] **Step 3: Create the controller**

```php
<?php
// app/Http/Controllers/Api/V1/Mobile/AiAssistantController.php
namespace App\Http\Controllers\Api\V1\Mobile;

use App\Http\Controllers\Api\V1\Mobile\Concerns\RespondsMobile;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class AiAssistantController extends Controller
{
    use RespondsMobile;

    public function show(Request $request)
    {
        return $this->ok($this->state($request->user()), 'AI assistant state.');
    }

    public function toggle(Request $request)
    {
        $validated = $request->validate(['enabled' => 'required|boolean']);

        $user = $request->user();
        $now = Carbon::now('UTC');
        $user->ai_manual_state = $validated['enabled'];
        $user->ai_manual_until = $user->ai_schedule_enabled
            ? $user->nextBoundaryAfter($now)
            : null;
        $user->save();

        return $this->ok($this->state($user), 'AI assistant updated.');
    }

    public function schedule(Request $request)
    {
        $validated = $request->validate([
            'enabled' => 'required|boolean',
            'start' => 'required_if:enabled,true|date_format:H:i',
            'end' => 'required_if:enabled,true|date_format:H:i|different:start',
            'timezone' => 'required_if:enabled,true|timezone',
        ]);

        $user = $request->user();
        $user->ai_schedule_enabled = $validated['enabled'];
        if ($validated['enabled']) {
            $user->ai_window_start = $validated['start'];
            $user->ai_window_end = $validated['end'];
            $user->ai_timezone = $validated['timezone'];
        }
        $user->save();

        return $this->ok($this->state($user), 'AI assistant schedule updated.');
    }

    private function state(User $user): array
    {
        $now = Carbon::now('UTC');
        $overrideLive = $user->ai_manual_state !== null
            && ($user->ai_manual_until === null || $now->lt($user->ai_manual_until));

        return [
            'active_now' => $user->aiActiveNow($now),
            'schedule_enabled' => (bool) $user->ai_schedule_enabled,
            'window' => $user->ai_schedule_enabled ? [
                'start' => $user->ai_window_start ? substr((string) $user->ai_window_start, 0, 5) : null,
                'end' => $user->ai_window_end ? substr((string) $user->ai_window_end, 0, 5) : null,
                'timezone' => $user->ai_timezone,
            ] : null,
            'manual_override' => $overrideLive ? [
                'state' => (bool) $user->ai_manual_state,
                'until' => $user->ai_manual_until?->toIso8601String(),
            ] : null,
        ];
    }
}
```

- [ ] **Step 4: Register the routes**

In `routes/api.php`, inside the mobile authed group:

```php
            Route::get('ai-assistant', [\App\Http\Controllers\Api\V1\Mobile\AiAssistantController::class, 'show']);
            Route::put('ai-assistant', [\App\Http\Controllers\Api\V1\Mobile\AiAssistantController::class, 'toggle']);
            Route::put('ai-assistant/schedule', [\App\Http\Controllers\Api\V1\Mobile\AiAssistantController::class, 'schedule']);
```

- [ ] **Step 5: Run test to verify it passes**

Run: `./vendor/bin/phpunit --filter MobileAiAssistantApiTest`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/Api/V1/Mobile/AiAssistantController.php routes/api.php \
        tests/Feature/Api/MobileAiAssistantApiTest.php
git commit -m "feat: add AI assistant toggle and schedule endpoints"
```

---

## Task 6: Extract shared send service + expose AI flag

**Files:**
- Create: `app/Services/SendThreadMessage.php`
- Modify: `app/Http/Controllers/Api/V1/Mobile/MessageController.php`
- Modify: `app/Http/Resources/ThreadMessageResource.php`
- Test: `tests/Feature/SendThreadMessageTest.php`

**Interfaces:**
- Consumes: `FreelancerMessenger::sendMessage(int $flThreadId, ?string $text, array $attachments = []): ?array`; `App\Events\ThreadMessageCreated`.
- Produces: `SendThreadMessage::send(Thread $thread, ?string $text, array $files = [], ?int $senderUserId = null, bool $sentByAi = false): ?ThreadMessage` — sends via messenger, persists the `ThreadMessage` (`direction=sent`, `sender_user_id`, `sent_by_ai`), stores attachments, fires `ThreadMessageCreated`, flips `fresh→answered`; returns `null` when the messenger rejects. `ThreadMessageResource` gains `'sent_by_ai' => (bool) $this->sent_by_ai`.

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/Feature/SendThreadMessageTest.php
namespace Tests\Feature;

use App\Events\ThreadMessageCreated;
use App\Models\Thread;
use App\Models\User;
use App\Services\SendThreadMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SendThreadMessageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['variables.flBase' => 'https://www.freelancer.com', 'variables.flKey' => 'k']);
    }

    private function fakeSendOk(int $id = 777): void
    {
        Http::fake([
            'https://www.freelancer.com/api/messages/0.1/threads/*/messages/*' => Http::response(
                ['result' => ['id' => $id]], 200
            ),
        ]);
    }

    public function test_ai_message_stored_with_flag_and_status_flips(): void
    {
        Event::fake([ThreadMessageCreated::class]);
        $this->fakeSendOk();
        $thread = Thread::factory()->create(['freelancer_thread_id' => 9001, 'status' => 'fresh']);

        $msg = app(SendThreadMessage::class)->send($thread, 'hi from AI', [], null, true);

        $this->assertNotNull($msg);
        $this->assertTrue($msg->sent_by_ai);
        $this->assertSame('sent', $msg->direction);
        $this->assertSame('answered', $thread->fresh()->status);
        Event::assertDispatched(ThreadMessageCreated::class);
    }

    public function test_human_message_defaults_flag_false(): void
    {
        $this->fakeSendOk();
        $user = User::factory()->create(['role' => 'mobile']);
        $thread = Thread::factory()->create(['freelancer_thread_id' => 9002]);

        $msg = app(SendThreadMessage::class)->send($thread, 'manual', [], $user->id, false);

        $this->assertFalse($msg->sent_by_ai);
        $this->assertSame($user->id, (int) $msg->sender_user_id);
    }

    public function test_returns_null_when_messenger_rejects(): void
    {
        Http::fake([
            'https://www.freelancer.com/api/messages/0.1/threads/*/messages/*' => Http::response([], 500),
        ]);
        $thread = Thread::factory()->create(['freelancer_thread_id' => 9003]);

        $this->assertNull(app(SendThreadMessage::class)->send($thread, 'x', [], null, true));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/phpunit --filter SendThreadMessageTest`
Expected: FAIL — `SendThreadMessage` does not exist.

- [ ] **Step 3: Create the service**

```php
<?php
// app/Services/SendThreadMessage.php
namespace App\Services;

use App\Events\ThreadMessageCreated;
use App\Models\Thread;
use App\Models\ThreadMessage;

class SendThreadMessage
{
    public function __construct(private FreelancerMessenger $messenger) {}

    /**
     * @param  array<int, \Illuminate\Http\UploadedFile>  $files
     */
    public function send(
        Thread $thread,
        ?string $text,
        array $files = [],
        ?int $senderUserId = null,
        bool $sentByAi = false
    ): ?ThreadMessage {
        $result = $this->messenger->sendMessage((int) $thread->freelancer_thread_id, $text, $files);

        if ($result === null) {
            return null;
        }

        $stored = $thread->messages()->create([
            'freelancer_message_id' => $result['id'] ?? null,
            'direction' => 'sent',
            'sender_user_id' => $senderUserId,
            'message' => $text,
            'message_time' => now(),
            'sent_by_ai' => $sentByAi,
        ]);

        foreach ($files as $file) {
            $stored->attachments()->create([
                'filename' => $file->getClientOriginalName(),
                'url' => '',
                'mime_type' => $file->getClientMimeType(),
                'size' => $file->getSize(),
            ]);
        }

        event(new ThreadMessageCreated($stored));

        if ($thread->status === 'fresh') {
            $thread->status = 'answered';
            $thread->save();
        }

        return $stored;
    }
}
```

Note: the existing `MessageController` reads the new message id as `$result['id']`. The fake in the test returns `['result' => ['id' => ...]]`; confirm what `FreelancerMessenger::sendMessage` returns by reading `app/Services/FreelancerMessenger.php:79+` and match the key it hands back (it returns the decoded `result` payload). Keep `$result['id'] ?? null` consistent with the current controller behavior.

- [ ] **Step 4: Refactor `MessageController@store` onto the service**

Replace the send/persist body (keeping validation and the 502 branch) with:

```php
    public function store(Request $request, Thread $thread, SendThreadMessage $sender)
    {
        $this->authorizeThread($request, $thread);

        $validated = $request->validate([
            'message' => 'nullable|string|required_without:attachments',
            'attachments' => 'nullable|array|max:5',
            'attachments.*' => 'file|max:20480',
        ]);

        $stored = $sender->send(
            $thread,
            $validated['message'] ?? null,
            $request->file('attachments', []),
            $request->user()->id,
            false
        );

        if ($stored === null) {
            return $this->fail('Freelancer rejected the message.', 502);
        }

        return $this->ok(
            new ThreadMessageResource($stored->load('attachments')),
            'Message sent successfully.',
            201
        );
    }
```

Update the imports: replace `use App\Services\FreelancerMessenger;` with `use App\Services\SendThreadMessage;`.

- [ ] **Step 5: Expose the flag in `ThreadMessageResource`**

Add to the returned array (e.g. after `is_read`):

```php
            'sent_by_ai' => (bool) $this->sent_by_ai,
```

- [ ] **Step 6: Run tests to verify they pass**

Run: `./vendor/bin/phpunit --filter SendThreadMessageTest`
Then regression: `./vendor/bin/phpunit --filter MobileMessagesApiTest`
Expected: both PASS.

- [ ] **Step 7: Commit**

```bash
git add app/Services/SendThreadMessage.php \
        app/Http/Controllers/Api/V1/Mobile/MessageController.php \
        app/Http/Resources/ThreadMessageResource.php \
        tests/Feature/SendThreadMessageTest.php
git commit -m "refactor: extract SendThreadMessage service and expose sent_by_ai"
```

---

## Task 7: Reactive auto-reply — generator + job + trigger

**Files:**
- Create: `app/Services/AiReplyGenerator.php`
- Create: `app/Jobs/GenerateAiReplyJob.php`
- Modify: `app/Services/ThreadSyncer.php`
- Test: `tests/Feature/GenerateAiReplyJobTest.php`
- Test: `tests/Feature/ThreadSyncerAiTriggerTest.php`

**Interfaces:**
- Consumes: `User::aiActiveNow` (Task 2); `SendThreadMessage::send` (Task 6); `ThreadMessage` (`direction`, `message_time`, `sender`); OpenAI via `Http` (`ThreadMatcher` idiom).
- Produces:
  - `AiReplyGenerator::generate(Thread $thread, ThreadMessage $clientMessage): ?string` — reply text or `null` on failure/empty.
  - `GenerateAiReplyJob(int $threadId, int $clientMessageId)` implementing `ShouldQueue`, with a `WithoutOverlapping($threadId)` middleware; `handle()` re-validates and sends.
  - `ThreadSyncer` dispatches `GenerateAiReplyJob` for each newly stored inbound message when the assigned user is active and the thread is unblocked.

- [ ] **Step 1: Write the failing test for the generator + job**

```php
<?php
// tests/Feature/GenerateAiReplyJobTest.php
namespace Tests\Feature;

use App\Jobs\GenerateAiReplyJob;
use App\Models\Thread;
use App\Models\ThreadMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GenerateAiReplyJobTest extends TestCase
{
    use RefreshDatabase;

    private User $me;
    private Thread $thread;
    private ThreadMessage $client;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'variables.flBase' => 'https://www.freelancer.com',
            'variables.flKey' => 'k',
            'variables.openAIKey' => 'sk-test',
        ]);
        Carbon::setTestNow('2026-07-27 10:00:00');
        $this->me = User::factory()->create([
            'role' => 'mobile', 'escalation_ladder' => 1, 'profile_prompt' => 'You are a helpful Laravel dev.',
            'ai_schedule_enabled' => true, 'ai_window_start' => '09:00:00',
            'ai_window_end' => '17:00:00', 'ai_timezone' => 'UTC',
        ]);
        $this->thread = Thread::factory()->create([
            'assigned_user_id' => $this->me->id, 'freelancer_thread_id' => 9001, 'status' => 'fresh',
        ]);
        $this->client = ThreadMessage::factory()->create([
            'thread_id' => $this->thread->id, 'direction' => 'received',
            'message' => 'Can you start today?', 'message_time' => now()->subMinute(),
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function fakeOpenAi(string $content): void
    {
        Http::fake([
            'https://api.openai.com/*' => Http::response(
                ['choices' => [['message' => ['content' => $content]]]], 200
            ),
            'https://www.freelancer.com/api/messages/0.1/threads/*/messages/*' => Http::response(
                ['result' => ['id' => 555]], 200
            ),
        ]);
    }

    public function test_generates_and_sends_ai_reply(): void
    {
        $this->fakeOpenAi('Yes, I can start today.');

        (new GenerateAiReplyJob($this->thread->id, $this->client->id))->handle(app(\App\Services\SendThreadMessage::class), app(\App\Services\AiReplyGenerator::class));

        $sent = ThreadMessage::where('thread_id', $this->thread->id)->where('direction', 'sent')->first();
        $this->assertNotNull($sent);
        $this->assertTrue($sent->sent_by_ai);
        $this->assertSame('Yes, I can start today.', $sent->message);
    }

    public function test_skips_when_ai_inactive(): void
    {
        $this->fakeOpenAi('should not send');
        $this->me->forceFill(['ai_schedule_enabled' => false])->save();

        (new GenerateAiReplyJob($this->thread->id, $this->client->id))->handle(app(\App\Services\SendThreadMessage::class), app(\App\Services\AiReplyGenerator::class));

        $this->assertSame(0, ThreadMessage::where('direction', 'sent')->count());
    }

    public function test_skips_when_human_already_replied(): void
    {
        $this->fakeOpenAi('late reply');
        ThreadMessage::factory()->create([
            'thread_id' => $this->thread->id, 'direction' => 'sent',
            'sender_user_id' => $this->me->id, 'message_time' => now(), // after client message
        ]);

        (new GenerateAiReplyJob($this->thread->id, $this->client->id))->handle(app(\App\Services\SendThreadMessage::class), app(\App\Services\AiReplyGenerator::class));

        $this->assertSame(0, ThreadMessage::where('direction', 'sent')->where('sent_by_ai', true)->count());
    }

    public function test_skips_when_blocked(): void
    {
        $this->fakeOpenAi('blocked reply');
        $this->thread->forceFill(['blocked' => true])->save();

        (new GenerateAiReplyJob($this->thread->id, $this->client->id))->handle(app(\App\Services\SendThreadMessage::class), app(\App\Services\AiReplyGenerator::class));

        $this->assertSame(0, ThreadMessage::where('sent_by_ai', true)->count());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/phpunit --filter GenerateAiReplyJobTest`
Expected: FAIL — `AiReplyGenerator` / `GenerateAiReplyJob` do not exist.

- [ ] **Step 3: Create `AiReplyGenerator`**

```php
<?php
// app/Services/AiReplyGenerator.php
namespace App\Services;

use App\Models\Thread;
use App\Models\ThreadMessage;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Drafts an AI reply to a client message using the assigned user's profile
 * prompt and recent thread history. Same fail-safe idiom as ThreadMatcher:
 * bounded retries, never throws, null on failure.
 */
class AiReplyGenerator
{
    private const MODEL = 'gpt-3.5-turbo';
    private const MAX_ATTEMPTS = 2;
    private const HISTORY = 10;

    public function generate(Thread $thread, ThreadMessage $clientMessage): ?string
    {
        $profile = trim((string) ($thread->assignedUser?->profile_prompt ?? ''));
        $system = "You are replying to a client on a freelancing marketplace on behalf of a freelancer. "
            . "Write a concise, professional reply as the freelancer. Do not include a signature.\n\n"
            . "Freelancer profile:\n" . ($profile !== '' ? $profile : 'Experienced freelancer.');

        $history = $thread->messages()
            ->orderByDesc('message_time')
            ->limit(self::HISTORY)
            ->get()
            ->reverse()
            ->map(fn ($m) => ($m->direction === 'received' ? 'Client' : 'Freelancer') . ': ' . (string) $m->message)
            ->implode("\n");

        $payload = [
            'model' => self::MODEL,
            'temperature' => 0.4,
            'messages' => [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user', 'content' => "Conversation so far:\n{$history}\n\nWrite the freelancer's next reply."],
            ],
        ];

        $bearer = 'Bearer ' . config('variables.openAIKey');
        $url = 'https://api.openai.com/v1/chat/completions';

        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            try {
                $response = Http::timeout(60)->withHeaders(['Authorization' => $bearer])->post($url, $payload);
                if ($response->successful()) {
                    $text = trim((string) $response->json('choices.0.message.content'));
                    if ($text !== '') {
                        return $text;
                    }
                    Log::warning("AiReplyGenerator: empty reply (attempt {$attempt})");
                } else {
                    Log::warning('AiReplyGenerator: HTTP ' . $response->status() . " (attempt {$attempt})");
                }
            } catch (\Throwable $e) {
                Log::warning('AiReplyGenerator: exception ' . $e->getMessage() . " (attempt {$attempt})");
            }
        }

        return null;
    }
}
```

- [ ] **Step 4: Create `GenerateAiReplyJob`**

```php
<?php
// app/Jobs/GenerateAiReplyJob.php
namespace App\Jobs;

use App\Models\Thread;
use App\Models\ThreadMessage;
use App\Services\AiReplyGenerator;
use App\Services\SendThreadMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;

class GenerateAiReplyJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public int $threadId, public int $clientMessageId) {}

    public function middleware(): array
    {
        return [(new WithoutOverlapping($this->threadId))->releaseAfter(30)->expireAfter(120)];
    }

    public function handle(SendThreadMessage $sender, AiReplyGenerator $generator): void
    {
        $thread = Thread::with('assignedUser')->find($this->threadId);
        $client = ThreadMessage::find($this->clientMessageId);
        if (! $thread || ! $client || ! $thread->assignedUser) {
            return;
        }

        if ($thread->blocked || ! $thread->assignedUser->aiActiveNow(Carbon::now('UTC'))) {
            return;
        }

        // A human (or an earlier AI pass) already answered after this client message.
        $answered = $thread->messages()
            ->where('direction', 'sent')
            ->where('message_time', '>=', $client->message_time)
            ->exists();
        if ($answered) {
            return;
        }

        $text = $generator->generate($thread, $client);
        if ($text === null) {
            return;
        }

        $sender->send($thread, $text, [], null, true);
    }
}
```

- [ ] **Step 5: Run test to verify it passes**

Run: `./vendor/bin/phpunit --filter GenerateAiReplyJobTest`
Expected: PASS.

- [ ] **Step 6: Write the failing trigger test**

```php
<?php
// tests/Feature/ThreadSyncerAiTriggerTest.php
namespace Tests\Feature;

use App\Jobs\GenerateAiReplyJob;
use App\Models\Thread;
use App\Models\ThreadMessage;
use App\Models\User;
use App\Services\ThreadSyncer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ThreadSyncerAiTriggerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-07-27 10:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function activeUser(): User
    {
        return User::factory()->create([
            'role' => 'mobile', 'escalation_ladder' => 1,
            'ai_schedule_enabled' => true, 'ai_window_start' => '09:00:00',
            'ai_window_end' => '17:00:00', 'ai_timezone' => 'UTC',
        ]);
    }

    public function test_inbound_message_dispatches_reply_when_active(): void
    {
        Queue::fake();
        $user = $this->activeUser();
        $thread = Thread::factory()->create(['assigned_user_id' => $user->id]);
        $msg = ThreadMessage::factory()->create([
            'thread_id' => $thread->id, 'direction' => 'received', 'message_time' => now(),
        ]);

        app(ThreadSyncer::class)->maybeQueueAiReply($thread->fresh(), $msg);

        Queue::assertPushed(GenerateAiReplyJob::class, fn ($j) => $j->threadId === $thread->id && $j->clientMessageId === $msg->id);
    }

    public function test_no_dispatch_when_inactive_or_blocked_or_sent(): void
    {
        Queue::fake();
        $user = $this->activeUser();

        $blocked = Thread::factory()->create(['assigned_user_id' => $user->id, 'blocked' => true]);
        $bMsg = ThreadMessage::factory()->create(['thread_id' => $blocked->id, 'direction' => 'received']);
        app(ThreadSyncer::class)->maybeQueueAiReply($blocked->fresh(), $bMsg);

        $sent = Thread::factory()->create(['assigned_user_id' => $user->id]);
        $sMsg = ThreadMessage::factory()->create(['thread_id' => $sent->id, 'direction' => 'sent']);
        app(ThreadSyncer::class)->maybeQueueAiReply($sent->fresh(), $sMsg);

        $unassigned = Thread::factory()->create(['assigned_user_id' => null]);
        $uMsg = ThreadMessage::factory()->create(['thread_id' => $unassigned->id, 'direction' => 'received']);
        app(ThreadSyncer::class)->maybeQueueAiReply($unassigned->fresh(), $uMsg);

        Queue::assertNothingPushed();
    }
}
```

- [ ] **Step 7: Run test to verify it fails**

Run: `./vendor/bin/phpunit --filter ThreadSyncerAiTriggerTest`
Expected: FAIL — `maybeQueueAiReply` undefined.

- [ ] **Step 8: Add the trigger to `ThreadSyncer`**

Add this public method (extracted so it is unit-testable without a full sync pass):

```php
    public function maybeQueueAiReply(\App\Models\Thread $thread, \App\Models\ThreadMessage $message): void
    {
        if ($message->direction !== 'received' || $thread->blocked || $thread->assigned_user_id === null) {
            return;
        }
        $thread->loadMissing('assignedUser');
        if ($thread->assignedUser && $thread->assignedUser->aiActiveNow(\Illuminate\Support\Carbon::now('UTC'))) {
            \App\Jobs\GenerateAiReplyJob::dispatch($thread->id, $message->id);
        }
    }
```

Then, in the message-storing loop, right after the existing `event(new \App\Events\ThreadMessageCreated($stored));` (around line 138) for a newly stored message, call it:

```php
            $this->maybeQueueAiReply($thread, $stored);
```

(`$stored` is only created in the `!$existing` branch, so this fires once per new inbound message. Existing messages hitting the `continue` above never reach it.)

- [ ] **Step 9: Run tests to verify they pass**

Run: `./vendor/bin/phpunit --filter ThreadSyncerAiTriggerTest`
Then regression: `./vendor/bin/phpunit --filter ThreadSyncerTest`
Expected: both PASS.

- [ ] **Step 10: Commit**

```bash
git add app/Services/AiReplyGenerator.php app/Jobs/GenerateAiReplyJob.php \
        app/Services/ThreadSyncer.php \
        tests/Feature/GenerateAiReplyJobTest.php tests/Feature/ThreadSyncerAiTriggerTest.php
git commit -m "feat: auto-reply to client messages when AI assistant active"
```

---

## Task 8: Full-suite verification

**Files:** none (verification only).

- [ ] **Step 1: Run the whole suite**

Run: `./vendor/bin/phpunit`
Expected: PASS (no regressions in existing mobile/thread/OpenAI tests).

- [ ] **Step 2: Run Pint on touched files**

Run: `./vendor/bin/pint app/ tests/`
Expected: clean / auto-fixed.

- [ ] **Step 3: Commit any Pint fixes**

```bash
git add -A && git commit -m "style: pint" || echo "nothing to format"
```

---

## Self-Review Notes

- **Spec coverage:** FCM refresh → Task 3. Thread client block → Task 4. AI toggle (manual override) → Task 5. AI schedule window + race-free computed state → Tasks 2 & 5. Reactive auto-reply via shared send path with `sent_by_ai` flag → Tasks 6 & 7. Schema for all of it → Task 1. Timezone per-user → Tasks 1, 2, 5. Overnight windows → Task 2. "Member since"/verification badges correctly excluded (not crawled).
- **Race fix:** verified by `MobileAiAssistantApiTest::test_toggle_off_during_window_writes_expiring_override` (override persists, no writer stomps it) and `UserAiStateTest` override cases — no stored effective flag, no cron.
- **Type consistency:** `aiActiveNow`, `nextBoundaryAfter`, `withinWindow` signatures identical across Tasks 2/5/7. `SendThreadMessage::send(...)` signature identical in Tasks 6/7. `GenerateAiReplyJob(int $threadId, int $clientMessageId)` and `handle(SendThreadMessage, AiReplyGenerator)` consistent between the job and its test.
- **Freelancer result key:** Task 6 Step 3 flags a read of `FreelancerMessenger::sendMessage` return shape; keep `$result['id'] ?? null` matching the current controller.
