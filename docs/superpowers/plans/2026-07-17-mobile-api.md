# Mobile API v1 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build a versioned `/api/v1` JSON API (Sanctum Bearer auth) for the mobile app covering authentication, bids, and review — leaving the existing web UI untouched.

**Architecture:** New JSON-only controllers under `app/Http/Controllers/Api/V1/` (`AuthController`, `BidController`, `ReviewController`), API Resource classes under `app/Http/Resources/` for consistent shaping, all wired into a `prefix('v1')` group in `routes/api.php` guarded by `auth:sanctum` (except login). The `User` model already has `HasApiTokens`; no model or migration changes.

**Tech Stack:** Laravel 10, Laravel Sanctum ^3.2 (personal access tokens), PHPUnit 10 feature tests with `RefreshDatabase`, run via `./vendor/bin/sail test`.

## Global Constraints

- All routes live under the `/api/v1` prefix. Every route **except** `login` is wrapped in `auth:sanctum`.
- JSON only. Never render HTML or redirect from these endpoints. Never return `500` on malformed input — validate first.
- Error contract: unauthenticated → `401 {"message":"Unauthenticated."}`; bad login credentials → `401 {"message":"Invalid credentials"}`; validation → `422 {message, errors}`; missing model → `404` JSON.
- **Multi-device tokens:** login issues a new token and does NOT revoke existing tokens. Logout revokes only the current token (`currentAccessToken()->delete()`).
- Review label enum is exactly `relevant,not_relevant_skill,scam`.
- Do NOT modify existing web controllers, models, migrations, views, or existing `/api` routes. The mobile surface is purely additive.
- `UserResource` never exposes `password` or `remember_token`.
- Filters, statistics, gamification, and bid create/edit/delete are OUT of scope.

---

### Task 1: Auth endpoints + UserResource + v1 route group

**Files:**
- Create: `app/Http/Resources/UserResource.php`
- Create: `app/Http/Controllers/Api/V1/AuthController.php`
- Modify: `routes/api.php` (add `v1` group)
- Modify: `app/Exceptions/Handler.php` (force JSON 401 for `api/*`)
- Test: `tests/Feature/Api/AuthApiTest.php`

**Interfaces:**
- Produces: `UserResource` (shape `{id,name,email,role}`); routes `POST /api/v1/login`, `GET /api/v1/user`, `POST /api/v1/logout`; the `v1` route group that Tasks 2–4 add routes into.
- Consumes: `App\Models\User` (`HasApiTokens`, `createToken()`, `currentAccessToken()`).

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Api/AuthApiTest.php`:

```php
<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AuthApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_with_valid_credentials_returns_token(): void
    {
        $user = User::factory()->create(['password' => bcrypt('secret123')]);

        $res = $this->postJson('/api/v1/login', [
            'email' => $user->email,
            'password' => 'secret123',
        ]);

        $res->assertOk()
            ->assertJsonStructure(['token', 'user' => ['id', 'name', 'email', 'role']]);
        $this->assertNotEmpty($res->json('token'));
        $this->assertDatabaseCount('personal_access_tokens', 1);
    }

    public function test_login_with_wrong_password_returns_401(): void
    {
        $user = User::factory()->create(['password' => bcrypt('secret123')]);

        $this->postJson('/api/v1/login', [
            'email' => $user->email,
            'password' => 'wrong',
        ])->assertStatus(401)->assertJson(['message' => 'Invalid credentials']);

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_login_validation_error_returns_422(): void
    {
        $this->postJson('/api/v1/login', ['email' => 'not-an-email'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['email', 'password']);
    }

    public function test_user_endpoint_requires_token(): void
    {
        $this->getJson('/api/v1/user')->assertStatus(401);
    }

    public function test_user_endpoint_returns_current_user_without_password(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $res = $this->getJson('/api/v1/user')->assertOk();
        $res->assertJsonPath('user.email', $user->email);
        $this->assertArrayNotHasKey('password', $res->json('user'));
    }

    public function test_logout_revokes_current_token_only(): void
    {
        $user = User::factory()->create();
        $tokenA = $user->createToken('deviceA')->plainTextToken;
        $tokenB = $user->createToken('deviceB')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer ' . $tokenA)
            ->postJson('/api/v1/logout')->assertNoContent();

        $this->assertDatabaseCount('personal_access_tokens', 1);

        $this->withHeader('Authorization', 'Bearer ' . $tokenB)
            ->getJson('/api/v1/user')->assertOk();
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `./vendor/bin/sail test --filter=AuthApiTest`
Expected: FAIL (routes `/api/v1/login` etc. return 404).

- [ ] **Step 3: Create `UserResource`**

`app/Http/Resources/UserResource.php`:

```php
<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'role' => $this->role,
        ];
    }
}
```

- [ ] **Step 4: Create `AuthController`**

`app/Http/Controllers/Api/V1/AuthController.php`:

```php
<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        $validated = $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
            'device_name' => 'nullable|string',
        ]);

        $user = User::where('email', $validated['email'])->first();

        if (! $user || ! Hash::check($validated['password'], $user->password)) {
            return response()->json(['message' => 'Invalid credentials'], 401);
        }

        $deviceName = $validated['device_name'] ?? 'mobile';
        $token = $user->createToken($deviceName)->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => new UserResource($user),
        ]);
    }

    public function me(Request $request)
    {
        return response()->json(['user' => new UserResource($request->user())]);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->noContent();
    }
}
```

- [ ] **Step 5: Add the `v1` route group**

In `routes/api.php`, add these imports near the other `use` statements at the top:

```php
use App\Http\Controllers\Api\V1\AuthController;
```

Then append at the end of the file:

```php
Route::prefix('v1')->group(function () {
    Route::post('login', [AuthController::class, 'login']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('user', [AuthController::class, 'me']);
        Route::post('logout', [AuthController::class, 'logout']);
    });
});
```

- [ ] **Step 6: Force JSON 401 for `api/*` in the exception handler**

In `app/Exceptions/Handler.php`, replace the body of `register()` with:

```php
public function register(): void
{
    $this->reportable(function (Throwable $e) {
        //
    });

    $this->renderable(function (\Illuminate\Auth\AuthenticationException $e, $request) {
        if ($request->is('api/*')) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }
    });
}
```

- [ ] **Step 7: Run the test to verify it passes**

Run: `./vendor/bin/sail test --filter=AuthApiTest`
Expected: PASS (6 passed).

- [ ] **Step 8: Commit**

```bash
git add app/Http/Resources/UserResource.php app/Http/Controllers/Api/V1/AuthController.php routes/api.php app/Exceptions/Handler.php tests/Feature/Api/AuthApiTest.php
git commit -m "feat: mobile api v1 auth (login, user, logout)"
```

---

### Task 2: Bids read (list + detail) + BidResource

**Files:**
- Create: `app/Http/Resources/BidResource.php`
- Create: `app/Http/Controllers/Api/V1/BidController.php`
- Modify: `routes/api.php` (add bids read routes)
- Test: `tests/Feature/Api/BidApiTest.php`

**Interfaces:**
- Consumes: the `v1` route group and `auth:sanctum` middleware from Task 1.
- Produces: `BidResource` with a `withFull()` method (returns `$this`) that adds `cover_letter` + `proposal.description`; `BidController@index`, `BidController@show`; a private `filteredBidQuery(Request)` reused by Task 3. Routes `GET /api/v1/bids`, `GET /api/v1/bids/{bid}`.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Api/BidApiTest.php`:

```php
<?php

namespace Tests\Feature\Api;

use App\Models\Bid;
use App\Models\Proposal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BidApiTest extends TestCase
{
    use RefreshDatabase;

    private function auth(): void
    {
        Sanctum::actingAs(User::factory()->create());
    }

    public function test_index_returns_bids_cards_and_meta(): void
    {
        $this->auth();
        $p = Proposal::factory()->create(['currency_symbol' => '$']);
        Bid::factory()->create(['proposal_id' => $p->id, 'bid_status' => 'pending']);

        $res = $this->getJson('/api/v1/bids')->assertOk();
        $res->assertJsonStructure([
            'data' => [[
                'id', 'status', 'price', 'currency', 'awarded', 'awarded_price',
                'check', 'is_seen', 'created_at',
                'proposal' => ['id', 'title', 'project_id', 'type', 'country', 'min_budget', 'max_budget', 'seo_url', 'skills'],
            ]],
            'cards' => ['total', 'placed', 'failed'],
            'meta' => ['current_page', 'last_page', 'per_page', 'total'],
        ]);
        $this->assertSame(1, $res->json('cards.total'));
        $this->assertSame('$', $res->json('data.0.currency'));
        $this->assertArrayNotHasKey('cover_letter', $res->json('data.0'));
    }

    public function test_index_failed_tab_filters_status(): void
    {
        $this->auth();
        Bid::factory()->create(['bid_status' => 'pending']);
        Bid::factory()->create(['bid_status' => 'failed']);

        $res = $this->getJson('/api/v1/bids?tab=failed')->assertOk();
        $statuses = array_values(array_unique(array_column($res->json('data'), 'status')));
        $this->assertEquals(['failed'], $statuses);
    }

    public function test_show_returns_full_bid_and_marks_seen(): void
    {
        $this->auth();
        $bid = Bid::factory()->create(['is_seen' => false]);

        $res = $this->getJson("/api/v1/bids/{$bid->id}")->assertOk();
        $res->assertJsonPath('data.id', $bid->id);
        $this->assertArrayHasKey('cover_letter', $res->json('data'));
        $this->assertTrue((bool) $bid->fresh()->is_seen);
    }

    public function test_show_missing_bid_returns_404(): void
    {
        $this->auth();
        $this->getJson('/api/v1/bids/999999')->assertStatus(404);
    }

    public function test_requires_auth(): void
    {
        $this->getJson('/api/v1/bids')->assertStatus(401);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `./vendor/bin/sail test --filter=BidApiTest`
Expected: FAIL (routes return 404 for authed requests).

- [ ] **Step 3: Create `BidResource`**

`app/Http/Resources/BidResource.php`:

```php
<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class BidResource extends JsonResource
{
    protected bool $full = false;

    public function withFull(): self
    {
        $this->full = true;

        return $this;
    }

    public function toArray($request): array
    {
        $proposal = $this->proposal;

        $data = [
            'id' => $this->id,
            'status' => $this->bid_status,
            'price' => (float) $this->price,
            'currency' => $proposal?->currency_symbol,
            'awarded' => (bool) $this->awarded,
            'awarded_price' => $this->awarded_price,
            'check' => $this->check,
            'is_seen' => (bool) $this->is_seen,
            'created_at' => optional($this->created_at)->toIso8601String(),
            'proposal' => $proposal ? [
                'id' => $proposal->id,
                'title' => $proposal->title,
                'project_id' => $proposal->project_id,
                'type' => $proposal->type,
                'country' => $proposal->country,
                'min_budget' => $proposal->min_budget,
                'max_budget' => $proposal->max_budget,
                'seo_url' => $proposal->seo_url,
                'skills' => $proposal->skills ?? [],
            ] : null,
        ];

        if ($this->full) {
            $data['cover_letter'] = $this->cover_letter;
            if ($proposal) {
                $data['proposal']['description'] = $proposal->description;
            }
        }

        return $data;
    }
}
```

- [ ] **Step 4: Create `BidController` with `index` and `show`**

`app/Http/Controllers/Api/V1/BidController.php`:

```php
<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\BidResource;
use App\Models\Bid;
use Carbon\Carbon;
use Illuminate\Http\Request;

class BidController extends Controller
{
    private function filteredBidQuery(Request $request)
    {
        $query = Bid::query()
            ->join('proposals', 'bids.proposal_id', '=', 'proposals.id')
            ->select('bids.*');

        if ($request->filled('from')) {
            $query->where('bids.created_at', '>=', Carbon::parse($request->query('from'))->startOfDay());
        }
        if ($request->filled('to')) {
            $query->where('bids.created_at', '<=', Carbon::parse($request->query('to'))->endOfDay());
        }
        if (is_numeric($request->query('min'))) {
            $query->where('bids.price', '>=', (float) $request->query('min'));
        }
        if (is_numeric($request->query('max'))) {
            $query->where('bids.price', '<=', (float) $request->query('max'));
        }
        if (in_array($request->query('type'), ['fixed', 'hourly'], true)) {
            $query->where('proposals.type', $request->query('type'));
        }
        if ($request->filled('q')) {
            $q = $request->query('q');
            $query->where(function ($sub) use ($q) {
                $sub->where('proposals.title', 'like', "%{$q}%")
                    ->orWhere('proposals.project_id', 'like', "%{$q}%");
            });
        }

        return $query;
    }

    public function index(Request $request)
    {
        $placed = ['pending', 'completed'];
        $failed = ['failed', 'expired'];

        $base = $this->filteredBidQuery($request);

        $cards = [
            'total'  => (clone $base)->count(),
            'placed' => (clone $base)->whereIn('bids.bid_status', $placed)->count(),
            'failed' => (clone $base)->whereIn('bids.bid_status', $failed)->count(),
        ];

        $tab = in_array($request->query('tab'), ['failed', 'completed'], true)
            ? $request->query('tab')
            : 'placed';
        $statuses = match ($tab) {
            'failed' => $failed,
            'completed' => ['completed'],
            default => $placed,
        };

        $bids = (clone $base)
            ->whereIn('bids.bid_status', $statuses)
            ->with('proposal')
            ->latest('bids.created_at')
            ->paginate(100)
            ->withQueryString();

        return response()->json([
            'data' => BidResource::collection($bids->items()),
            'cards' => $cards,
            'meta' => [
                'current_page' => $bids->currentPage(),
                'last_page' => $bids->lastPage(),
                'per_page' => $bids->perPage(),
                'total' => $bids->total(),
            ],
        ]);
    }

    public function show(Bid $bid)
    {
        $bid->is_seen = true;
        $bid->save();
        $bid->load('proposal');

        return response()->json([
            'data' => (new BidResource($bid))->withFull(),
        ]);
    }
}
```

- [ ] **Step 5: Add the bids read routes**

In `routes/api.php`, add the import near the top:

```php
use App\Http\Controllers\Api\V1\BidController as ApiBidController;
```

Inside the `auth:sanctum` group of the `v1` block (from Task 1), add:

```php
        Route::get('bids', [ApiBidController::class, 'index']);
        Route::get('bids/{bid}', [ApiBidController::class, 'show']);
```

- [ ] **Step 6: Run the test to verify it passes**

Run: `./vendor/bin/sail test --filter=BidApiTest`
Expected: PASS (5 passed).

- [ ] **Step 7: Commit**

```bash
git add app/Http/Resources/BidResource.php app/Http/Controllers/Api/V1/BidController.php routes/api.php tests/Feature/Api/BidApiTest.php
git commit -m "feat: mobile api v1 bids read (list + detail)"
```

---

### Task 3: Bid mutations (status, check, expire)

**Files:**
- Modify: `app/Http/Controllers/Api/V1/BidController.php` (add `updateStatus`, `updateCheck`, `expire`)
- Modify: `routes/api.php` (add mutation routes)
- Test: `tests/Feature/Api/BidApiTest.php` (add methods)

**Interfaces:**
- Consumes: `BidController` and `BidResource` from Task 2; the `v1` `auth:sanctum` group.
- Produces: `POST /api/v1/bids/{bid}/status`, `POST /api/v1/bids/{bid}/check`, `POST /api/v1/bids/expire`.

- [ ] **Step 1: Add the failing tests**

Append these methods inside the `BidApiTest` class in `tests/Feature/Api/BidApiTest.php`:

```php
    public function test_update_status(): void
    {
        $this->auth();
        $bid = Bid::factory()->create(['bid_status' => 'pending']);

        $this->postJson("/api/v1/bids/{$bid->id}/status", ['status' => 'completed'])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'completed');

        $this->assertSame('completed', $bid->fresh()->bid_status);
    }

    public function test_update_status_requires_status(): void
    {
        $this->auth();
        $bid = Bid::factory()->create();

        $this->postJson("/api/v1/bids/{$bid->id}/status", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['status']);
    }

    public function test_update_status_missing_bid_returns_404(): void
    {
        $this->auth();
        $this->postJson('/api/v1/bids/999999/status', ['status' => 'completed'])
            ->assertStatus(404);
    }

    public function test_update_check(): void
    {
        $this->auth();
        $bid = Bid::factory()->create();

        $this->postJson("/api/v1/bids/{$bid->id}/check", ['check' => 'Reviewed'])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('check', 'Reviewed');

        $this->assertSame('Reviewed', $bid->fresh()->check);
    }

    public function test_expire_sets_non_completed_to_expired(): void
    {
        $this->auth();
        Bid::factory()->create(['bid_status' => 'pending']);
        Bid::factory()->create(['bid_status' => 'completed']);
        Bid::factory()->create(['bid_status' => 'failed']);

        $res = $this->postJson('/api/v1/bids/expire')->assertOk();
        $this->assertSame(2, $res->json('expired_count'));
        $this->assertSame(0, Bid::where('bid_status', 'pending')->count());
        $this->assertSame(1, Bid::where('bid_status', 'completed')->count());
        $this->assertSame(2, Bid::where('bid_status', 'expired')->count());
    }
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `./vendor/bin/sail test --filter=BidApiTest`
Expected: FAIL (new routes return 404).

- [ ] **Step 3: Add the mutation methods to `BidController`**

Append these three methods inside the `BidController` class (after `show`) in `app/Http/Controllers/Api/V1/BidController.php`:

```php
    public function updateStatus(Request $request, Bid $bid)
    {
        $request->validate(['status' => 'required|string']);

        $bid->bid_status = $request->input('status');
        $bid->save();
        $bid->load('proposal');

        return response()->json([
            'success' => true,
            'data' => new BidResource($bid),
        ]);
    }

    public function updateCheck(Request $request, Bid $bid)
    {
        $request->validate(['check' => 'required|string']);

        $bid->check = $request->input('check');
        $bid->save();

        return response()->json([
            'success' => true,
            'check' => $bid->check,
        ]);
    }

    public function expire()
    {
        $bids = Bid::where('bid_status', '!=', 'completed')->get();
        foreach ($bids as $bid) {
            $bid->bid_status = 'expired';
            $bid->save();
        }

        return response()->json([
            'success' => true,
            'expired_count' => $bids->count(),
        ]);
    }
```

- [ ] **Step 4: Add the mutation routes**

In `routes/api.php`, inside the `v1` `auth:sanctum` group, add (place `bids/expire` before the `{bid}` routes for clarity):

```php
        Route::post('bids/expire', [ApiBidController::class, 'expire']);
        Route::post('bids/{bid}/status', [ApiBidController::class, 'updateStatus']);
        Route::post('bids/{bid}/check', [ApiBidController::class, 'updateCheck']);
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `./vendor/bin/sail test --filter=BidApiTest`
Expected: PASS (9 passed — 5 from Task 2 + 4 new).

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/Api/V1/BidController.php routes/api.php tests/Feature/Api/BidApiTest.php
git commit -m "feat: mobile api v1 bid mutations (status, check, expire)"
```

---

### Task 4: Review (load + feedback) + ProposalResource

**Files:**
- Create: `app/Http/Resources/ProposalResource.php`
- Create: `app/Http/Controllers/Api/V1/ReviewController.php`
- Modify: `routes/api.php` (add review routes)
- Test: `tests/Feature/Api/ReviewApiTest.php`

**Interfaces:**
- Consumes: the `v1` `auth:sanctum` group; `App\Models\Proposal` with scope `needsReview()` (`review_label` null).
- Produces: `ProposalResource`; `ReviewController@index`, `ReviewController@storeFeedback`. Routes `GET /api/v1/review`, `POST /api/v1/review/feedback`.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Api/ReviewApiTest.php`:

```php
<?php

namespace Tests\Feature\Api;

use App\Models\Proposal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ReviewApiTest extends TestCase
{
    use RefreshDatabase;

    private function auth(): void
    {
        Sanctum::actingAs(User::factory()->create());
    }

    public function test_index_returns_new_proposals_needing_review(): void
    {
        $this->auth();
        Proposal::factory()->create(['review_label' => null, 'created_at' => now()]);
        Proposal::factory()->create(['review_label' => 'relevant', 'created_at' => now()]); // labeled -> excluded
        Proposal::factory()->create(['review_label' => null, 'created_at' => now()->subDays(30)]); // old

        $res = $this->getJson('/api/v1/review?tab=new')->assertOk();
        $res->assertJsonStructure([
            'data' => [[
                'id', 'title', 'description', 'type', 'country',
                'min_budget', 'max_budget', 'currency_symbol', 'skills', 'seo_url', 'created_at',
            ]],
            'hasMore', 'newCount', 'oldCount',
        ]);
        $this->assertSame(1, $res->json('newCount'));
        $this->assertSame(1, $res->json('oldCount'));
        $this->assertCount(1, $res->json('data'));
    }

    public function test_old_tab_returns_only_old(): void
    {
        $this->auth();
        Proposal::factory()->create(['review_label' => null, 'created_at' => now()->subDays(30)]);
        Proposal::factory()->create(['review_label' => null, 'created_at' => now()]);

        $res = $this->getJson('/api/v1/review?tab=old')->assertOk();
        $this->assertCount(1, $res->json('data'));
    }

    public function test_after_id_pages_older_ids(): void
    {
        $this->auth();
        $a = Proposal::factory()->create(['review_label' => null, 'created_at' => now()]);
        $b = Proposal::factory()->create(['review_label' => null, 'created_at' => now()]);

        $res = $this->getJson("/api/v1/review?tab=new&after_id={$b->id}")->assertOk();
        $ids = array_column($res->json('data'), 'id');
        $this->assertEquals([$a->id], $ids);
    }

    public function test_feedback_persists_label(): void
    {
        $this->auth();
        $p = Proposal::factory()->create(['review_label' => null]);

        $this->postJson('/api/v1/review/feedback', ['proposal_id' => $p->id, 'label' => 'scam'])
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->assertSame('scam', $p->fresh()->review_label);
    }

    public function test_feedback_invalid_label_returns_422(): void
    {
        $this->auth();
        $p = Proposal::factory()->create();

        $this->postJson('/api/v1/review/feedback', ['proposal_id' => $p->id, 'label' => 'bogus'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['label']);
    }

    public function test_requires_auth(): void
    {
        $this->getJson('/api/v1/review')->assertStatus(401);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `./vendor/bin/sail test --filter=ReviewApiTest`
Expected: FAIL (routes return 404 for authed requests).

- [ ] **Step 3: Create `ProposalResource`**

`app/Http/Resources/ProposalResource.php`:

```php
<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class ProposalResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'description' => $this->description,
            'type' => $this->type,
            'country' => $this->country,
            'min_budget' => $this->min_budget,
            'max_budget' => $this->max_budget,
            'currency_symbol' => $this->currency_symbol,
            'skills' => $this->skills ?? [],
            'seo_url' => $this->seo_url,
            'created_at' => optional($this->created_at)->toIso8601String(),
        ];
    }
}
```

- [ ] **Step 4: Create `ReviewController`**

`app/Http/Controllers/Api/V1/ReviewController.php`:

```php
<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProposalResource;
use App\Models\Proposal;
use Illuminate\Http\Request;

class ReviewController extends Controller
{
    private const NEW_WINDOW_DAYS = 7;
    private const PER_PAGE = 20;

    private function tabQuery(string $tab)
    {
        $cutoff = now()->subDays(self::NEW_WINDOW_DAYS);
        $query = Proposal::needsReview();

        return $tab === 'old'
            ? $query->where('created_at', '<', $cutoff)
            : $query->where('created_at', '>=', $cutoff);
    }

    public function index(Request $request)
    {
        $tab = $request->query('tab') === 'old' ? 'old' : 'new';

        $query = $this->tabQuery($tab)->orderByDesc('id');
        if ($request->filled('after_id')) {
            $query->where('id', '<', (int) $request->query('after_id'));
        }

        $proposals = $query->limit(self::PER_PAGE + 1)->get();
        $hasMore = $proposals->count() > self::PER_PAGE;
        $proposals = $proposals->take(self::PER_PAGE);

        return response()->json([
            'data' => ProposalResource::collection($proposals),
            'hasMore' => $hasMore,
            'newCount' => $this->tabQuery('new')->count(),
            'oldCount' => $this->tabQuery('old')->count(),
        ]);
    }

    public function storeFeedback(Request $request)
    {
        $validated = $request->validate([
            'proposal_id' => 'required|exists:proposals,id',
            'label' => 'required|in:relevant,not_relevant_skill,scam',
        ]);

        $proposal = Proposal::find($validated['proposal_id']);
        $proposal->review_label = $validated['label'];
        $proposal->save();

        return response()->json(['success' => true]);
    }
}
```

- [ ] **Step 5: Add the review routes**

In `routes/api.php`, add the import near the top:

```php
use App\Http\Controllers\Api\V1\ReviewController as ApiReviewController;
```

Inside the `v1` `auth:sanctum` group, add:

```php
        Route::get('review', [ApiReviewController::class, 'index']);
        Route::post('review/feedback', [ApiReviewController::class, 'storeFeedback']);
```

- [ ] **Step 6: Run the test to verify it passes**

Run: `./vendor/bin/sail test --filter=ReviewApiTest`
Expected: PASS (6 passed).

- [ ] **Step 7: Run the whole API suite**

Run: `./vendor/bin/sail test --filter=Api`
Expected: PASS (all AuthApiTest + BidApiTest + ReviewApiTest green).

- [ ] **Step 8: Commit**

```bash
git add app/Http/Resources/ProposalResource.php app/Http/Controllers/Api/V1/ReviewController.php routes/api.php tests/Feature/Api/ReviewApiTest.php
git commit -m "feat: mobile api v1 review (load + feedback)"
```

---

## Final `routes/api.php` v1 block (reference)

After all four tasks the `v1` group should read:

```php
Route::prefix('v1')->group(function () {
    Route::post('login', [AuthController::class, 'login']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('user', [AuthController::class, 'me']);
        Route::post('logout', [AuthController::class, 'logout']);

        Route::get('bids', [ApiBidController::class, 'index']);
        Route::post('bids/expire', [ApiBidController::class, 'expire']);
        Route::get('bids/{bid}', [ApiBidController::class, 'show']);
        Route::post('bids/{bid}/status', [ApiBidController::class, 'updateStatus']);
        Route::post('bids/{bid}/check', [ApiBidController::class, 'updateCheck']);

        Route::get('review', [ApiReviewController::class, 'index']);
        Route::post('review/feedback', [ApiReviewController::class, 'storeFeedback']);
    });
});
```

## Endpoint summary (for Postman)

- `POST /api/v1/login` → `{token, user}` (body: `email`, `password`, `device_name?`)
- `GET /api/v1/user` (Bearer) → `{user}`
- `POST /api/v1/logout` (Bearer) → `204`
- `GET /api/v1/bids?tab=&q=&type=&from=&to=&min=&max=&page=` (Bearer) → `{data, cards, meta}`
- `GET /api/v1/bids/{id}` (Bearer) → `{data}` (full, with cover_letter)
- `POST /api/v1/bids/{id}/status` (Bearer, body `status`) → `{success, data}`
- `POST /api/v1/bids/{id}/check` (Bearer, body `check`) → `{success, check}`
- `POST /api/v1/bids/expire` (Bearer) → `{success, expired_count}`
- `GET /api/v1/review?tab=new|old&after_id=` (Bearer) → `{data, hasMore, newCount, oldCount}`
- `POST /api/v1/review/feedback` (Bearer, body `proposal_id`, `label`) → `{success}`
```