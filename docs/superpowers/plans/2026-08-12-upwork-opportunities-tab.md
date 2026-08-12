# Upwork Opportunities Tab Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add an "Upwork" tab to the Opportunities page that lists Upwork marketplace jobs crawled from the Upwork GraphQL API and stored locally, alongside the untouched existing "Freelancer" view.

**Architecture:** Mirror the existing Freelancer `proposals:fetch` pipeline. A scheduled `upwork:fetch` command calls `App\Services\UpworkClient`, which POSTs a `marketplaceJobPostingsSearch` GraphQL query to the Upwork API (Bearer access token + tenant header, refresh-on-401), normalizes each job edge, and `updateOrCreate`s rows in a new `upwork_jobs` table. The Opportunities blade (`home.blade.php`) wraps its current content in a marketplace tab switcher; the Upwork pane lazy-loads server-rendered rows from `GET /bids/upwork/data`.

**Tech Stack:** Laravel (PHP), Blade, Bootstrap 5, Laravel `Http` client, Laravel `Cache`, PHPUnit with `Http::fake()` + `RefreshDatabase`.

## Global Constraints

- No Claude/AI co-author trailer in any commit message (project rule).
- Follow existing patterns: crawl via `Http::withHeaders()`, store via `updateOrCreate` keyed on the marketplace id, server-render rows in a `_partials` blade and return `{ rowsHtml, paginationHtml }` JSON — same contract as `BidController@data`.
- All new web routes live inside the existing authenticated route group in `routes/web.php` (same group as `/bids`).
- Config values read via `config('variables.upwork*')`, defined in `config/variables.php`, backed by `env()` — same style as `flKey`/`flBase`.
- Crawl is best-effort: network/GraphQL/credential failures are logged and yield `[]`, never a thrown exception or user-facing error.
- Scope: list only. No proposals, bidding, detail panel, filters, or search on the Upwork tab.

---

### Task 1: `upwork_jobs` table + `UpworkJob` model + factory

**Files:**
- Create: `database/migrations/2026_08_12_000000_create_upwork_jobs_table.php`
- Create: `app/Models/UpworkJob.php`
- Create: `database/factories/UpworkJobFactory.php`
- Test: `tests/Unit/UpworkJobModelTest.php`

**Interfaces:**
- Produces: `App\Models\UpworkJob` with fillable columns `job_id, title, description, url, job_type, budget_amount, hourly_min, hourly_max, currency, posted_at, skills, client_country, client_total_spent, client_payment_verified`. Casts: `skills => array`, `posted_at => datetime`, `client_payment_verified => boolean`, `client_total_spent => float`, `budget_amount => float`, `hourly_min => float`, `hourly_max => float`.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Unit;

use App\Models\UpworkJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UpworkJobModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_casts_and_persists_a_job(): void
    {
        $job = UpworkJob::create([
            'job_id' => '~0123abc',
            'title' => 'Build a Laravel API',
            'description' => 'Clean project',
            'url' => 'https://www.upwork.com/jobs/~0123abc',
            'job_type' => 'fixed',
            'budget_amount' => 500,
            'currency' => 'USD',
            'posted_at' => '2026-08-10 09:00:00',
            'skills' => ['PHP', 'Laravel'],
            'client_country' => 'United States',
            'client_total_spent' => 12000.5,
            'client_payment_verified' => true,
        ]);

        $fresh = $job->fresh();
        $this->assertSame(['PHP', 'Laravel'], $fresh->skills);
        $this->assertTrue($fresh->client_payment_verified);
        $this->assertEquals('2026-08-10', $fresh->posted_at->format('Y-m-d'));
        $this->assertSame(500.0, $fresh->budget_amount);
    }

    public function test_factory_creates_a_job(): void
    {
        $job = UpworkJob::factory()->create();
        $this->assertNotNull($job->job_id);
        $this->assertIsArray($job->skills);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=UpworkJobModelTest`
Expected: FAIL — class `App\Models\UpworkJob` not found / table missing.

- [ ] **Step 3: Write the migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('upwork_jobs', function (Blueprint $table) {
            $table->id();
            $table->string('job_id')->unique();     // Upwork id / ciphertext — dedup key
            $table->string('title')->nullable();
            $table->longText('description')->nullable();
            $table->string('url')->nullable();
            $table->string('job_type')->nullable();  // hourly | fixed
            $table->double('budget_amount')->nullable();
            $table->double('hourly_min')->nullable();
            $table->double('hourly_max')->nullable();
            $table->string('currency')->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->json('skills')->nullable();
            $table->string('client_country')->nullable();
            $table->double('client_total_spent')->nullable();
            $table->boolean('client_payment_verified')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('upwork_jobs');
    }
};
```

- [ ] **Step 4: Write the model**

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class UpworkJob extends Model
{
    use HasFactory;

    protected $fillable = [
        'job_id', 'title', 'description', 'url', 'job_type',
        'budget_amount', 'hourly_min', 'hourly_max', 'currency',
        'posted_at', 'skills', 'client_country', 'client_total_spent',
        'client_payment_verified',
    ];

    protected $casts = [
        'skills' => 'array',
        'posted_at' => 'datetime',
        'client_payment_verified' => 'boolean',
        'client_total_spent' => 'float',
        'budget_amount' => 'float',
        'hourly_min' => 'float',
        'hourly_max' => 'float',
    ];
}
```

- [ ] **Step 5: Write the factory**

```php
<?php

namespace Database\Factories;

use App\Models\UpworkJob;
use Illuminate\Database\Eloquent\Factories\Factory;

class UpworkJobFactory extends Factory
{
    protected $model = UpworkJob::class;

    public function definition(): array
    {
        $id = '~0'.$this->faker->unique()->bothify('##########');

        return [
            'job_id' => $id,
            'title' => $this->faker->sentence(4),
            'description' => $this->faker->paragraph(),
            'url' => 'https://www.upwork.com/jobs/'.$id,
            'job_type' => $this->faker->randomElement(['hourly', 'fixed']),
            'budget_amount' => $this->faker->numberBetween(100, 5000),
            'hourly_min' => null,
            'hourly_max' => null,
            'currency' => 'USD',
            'posted_at' => $this->faker->dateTimeBetween('-10 days', 'now'),
            'skills' => ['PHP', 'Laravel'],
            'client_country' => $this->faker->country(),
            'client_total_spent' => $this->faker->numberBetween(0, 50000),
            'client_payment_verified' => $this->faker->boolean(),
        ];
    }
}
```

- [ ] **Step 6: Run test to verify it passes**

Run: `php artisan test --filter=UpworkJobModelTest`
Expected: PASS (both tests).

- [ ] **Step 7: Commit**

```bash
git add database/migrations/2026_08_12_000000_create_upwork_jobs_table.php app/Models/UpworkJob.php database/factories/UpworkJobFactory.php tests/Unit/UpworkJobModelTest.php
git commit -m "feat: add upwork_jobs table and UpworkJob model"
```

---

### Task 2: Upwork config keys + `UpworkClient` service

**Files:**
- Modify: `config/variables.php` (add `upwork*` keys near the `flKey`/`flBase` block)
- Modify: `.env.example` (add blank `UPWORK_*` keys)
- Create: `app/Services/UpworkClient.php`
- Test: `tests/Feature/UpworkClientTest.php`

**Interfaces:**
- Consumes: `App\Models\UpworkJob` (Task 1) is NOT used here — this service only returns arrays.
- Produces: `App\Services\UpworkClient::fetchRecentJobs(int $limit = 50): array` — returns a list of associative arrays each shaped exactly like the `UpworkJob` fillable columns (`job_id, title, description, url, job_type, budget_amount, hourly_min, hourly_max, currency, posted_at, skills, client_country, client_total_spent, client_payment_verified`). Returns `[]` on any failure or missing credentials.

- [ ] **Step 1: Add config keys**

In `config/variables.php`, immediately after the `"flFake" => ...` line, add:

```php
    // Upwork GraphQL API (Opportunities → Upwork tab). Values supplied via .env.
    "upworkBase"         => env("UPWORK_BASE_URL", "https://api.upwork.com/graphql"),
    "upworkTokenUrl"     => env("UPWORK_OAUTH_TOKEN_URL", "https://www.upwork.com/api/v3/oauth2/token"),
    "upworkClientId"     => env("UPWORK_CLIENT_ID"),
    "upworkClientSecret" => env("UPWORK_CLIENT_SECRET"),
    "upworkAccessToken"  => env("UPWORK_ACCESS_TOKEN"),
    "upworkRefreshToken" => env("UPWORK_REFRESH_TOKEN"),
    "upworkTenantId"     => env("UPWORK_TENANT_ID"),
```

In `.env.example`, add:

```
UPWORK_BASE_URL=https://api.upwork.com/graphql
UPWORK_OAUTH_TOKEN_URL=https://www.upwork.com/api/v3/oauth2/token
UPWORK_CLIENT_ID=
UPWORK_CLIENT_SECRET=
UPWORK_ACCESS_TOKEN=
UPWORK_REFRESH_TOKEN=
UPWORK_TENANT_ID=
```

- [ ] **Step 2: Write the failing test**

```php
<?php

namespace Tests\Feature;

use App\Services\UpworkClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class UpworkClientTest extends TestCase
{
    private function setCreds(): void
    {
        config()->set('variables.upworkBase', 'https://api.upwork.com/graphql');
        config()->set('variables.upworkTokenUrl', 'https://www.upwork.com/api/v3/oauth2/token');
        config()->set('variables.upworkClientId', 'cid');
        config()->set('variables.upworkClientSecret', 'secret');
        config()->set('variables.upworkAccessToken', 'access-1');
        config()->set('variables.upworkRefreshToken', 'refresh-1');
        config()->set('variables.upworkTenantId', 'tenant-1');
    }

    private function jobsResponse(): array
    {
        return [
            'data' => [
                'marketplaceJobPostingsSearch' => [
                    'edges' => [
                        ['node' => [
                            'id' => '1111',
                            'ciphertext' => '~0abc',
                            'title' => 'Build a Laravel API',
                            'description' => 'Clean project',
                            'amount' => ['rawValue' => '500', 'currency' => 'USD'],
                            'hourlyBudgetMin' => null,
                            'hourlyBudgetMax' => null,
                            'createdDateTime' => '2026-08-10T09:00:00Z',
                            'skills' => [['name' => 'PHP'], ['name' => 'Laravel']],
                            'client' => [
                                'location' => ['country' => 'United States'],
                                'totalSpent' => ['rawValue' => '12000'],
                                'verificationStatus' => 'VERIFIED',
                            ],
                        ]],
                    ],
                ],
            ],
        ];
    }

    public function test_returns_empty_when_credentials_missing(): void
    {
        config()->set('variables.upworkAccessToken', null);
        config()->set('variables.upworkRefreshToken', null);
        Cache::forget('upwork_access_token');

        Http::fake();
        $this->assertSame([], (new UpworkClient)->fetchRecentJobs());
        Http::assertNothingSent();
    }

    public function test_normalizes_job_edges(): void
    {
        $this->setCreds();
        Cache::forget('upwork_access_token');

        Http::fake([
            'api.upwork.com/graphql' => Http::response($this->jobsResponse(), 200),
        ]);

        $rows = (new UpworkClient)->fetchRecentJobs();

        $this->assertCount(1, $rows);
        $row = $rows[0];
        $this->assertSame('~0abc', $row['job_id']);
        $this->assertSame('Build a Laravel API', $row['title']);
        $this->assertSame('https://www.upwork.com/jobs/~0abc', $row['url']);
        $this->assertSame('fixed', $row['job_type']);
        $this->assertSame(500.0, $row['budget_amount']);
        $this->assertSame('USD', $row['currency']);
        $this->assertSame(['PHP', 'Laravel'], $row['skills']);
        $this->assertSame('United States', $row['client_country']);
        $this->assertSame(12000.0, $row['client_total_spent']);
        $this->assertTrue($row['client_payment_verified']);
        $this->assertSame('2026-08-10', substr($row['posted_at'], 0, 10));
    }

    public function test_refreshes_token_on_401_and_retries_once(): void
    {
        $this->setCreds();
        Cache::forget('upwork_access_token');

        $graphqlCalls = 0;
        Http::fake([
            'www.upwork.com/api/v3/oauth2/token' => Http::response(['access_token' => 'access-2'], 200),
            'api.upwork.com/graphql' => function () use (&$graphqlCalls) {
                $graphqlCalls++;
                if ($graphqlCalls === 1) {
                    return Http::response(['error' => 'unauthorized'], 401);
                }
                return Http::response($this->jobsResponse(), 200);
            },
        ]);

        $rows = (new UpworkClient)->fetchRecentJobs();

        $this->assertCount(1, $rows);
        $this->assertSame(2, $graphqlCalls);              // retried once
        $this->assertSame('access-2', Cache::get('upwork_access_token')); // new token persisted
    }
}
```

- [ ] **Step 3: Run test to verify it fails**

Run: `php artisan test --filter=UpworkClientTest`
Expected: FAIL — class `App\Services\UpworkClient` not found.

- [ ] **Step 4: Write the service**

```php
<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class UpworkClient
{
    /**
     * Fetch recent Upwork marketplace jobs and return them normalized to the
     * upwork_jobs column shape. Best-effort: returns [] on any failure.
     */
    public function fetchRecentJobs(int $limit = 50): array
    {
        if (! $this->accessToken() || ! config('variables.upworkRefreshToken')) {
            Log::warning('UpworkClient: missing credentials, skipping fetch.');

            return [];
        }

        try {
            $response = $this->post($this->searchQuery($limit));

            if ($response->status() === 401 && $this->refreshToken()) {
                $response = $this->post($this->searchQuery($limit));
            }

            if (! $response->successful()) {
                Log::warning('UpworkClient: fetch failed', ['status' => $response->status()]);

                return [];
            }

            $edges = data_get($response->json(), 'data.marketplaceJobPostingsSearch.edges', []);

            return array_map(fn ($edge) => $this->normalizeEdge($edge['node'] ?? []), $edges);
        } catch (\Throwable $e) {
            Log::warning('UpworkClient: exception during fetch', ['message' => $e->getMessage()]);

            return [];
        }
    }

    private function accessToken(): ?string
    {
        return Cache::get('upwork_access_token') ?: config('variables.upworkAccessToken');
    }

    private function post(string $query)
    {
        return Http::timeout(30)
            ->withToken($this->accessToken())
            ->withHeaders(['X-Upwork-API-TenantId' => config('variables.upworkTenantId')])
            ->post(config('variables.upworkBase'), ['query' => $query]);
    }

    private function refreshToken(): bool
    {
        $res = Http::asForm()->timeout(30)->post(config('variables.upworkTokenUrl'), [
            'grant_type' => 'refresh_token',
            'refresh_token' => config('variables.upworkRefreshToken'),
            'client_id' => config('variables.upworkClientId'),
            'client_secret' => config('variables.upworkClientSecret'),
        ]);

        $token = data_get($res->json(), 'access_token');

        if ($res->successful() && $token) {
            Cache::forever('upwork_access_token', $token);

            return true;
        }

        Log::warning('UpworkClient: token refresh failed', ['status' => $res->status()]);

        return false;
    }

    private function searchQuery(int $limit): string
    {
        // No filter this iteration — list recent postings. Selection set is
        // finalized against the official Upwork GraphQL docs when live creds land.
        return <<<GQL
        query {
          marketplaceJobPostingsSearch(
            marketPlaceJobFilter: { pagination_eq: { first: {$limit} } }
            sortAttributes: [{ field: "RECENCY" }]
          ) {
            edges {
              node {
                id
                ciphertext
                title
                description
                amount { rawValue currency }
                hourlyBudgetMin { rawValue }
                hourlyBudgetMax { rawValue }
                createdDateTime
                skills { name }
                client {
                  location { country }
                  totalSpent { rawValue }
                  verificationStatus
                }
              }
            }
          }
        }
        GQL;
    }

    private function normalizeEdge(array $node): array
    {
        $cipher = data_get($node, 'ciphertext') ?: data_get($node, 'id');
        $budget = data_get($node, 'amount.rawValue');
        $hourlyMin = data_get($node, 'hourlyBudgetMin.rawValue');
        $hourlyMax = data_get($node, 'hourlyBudgetMax.rawValue');
        $isHourly = $hourlyMin !== null || $hourlyMax !== null;

        return [
            'job_id' => $cipher,
            'title' => data_get($node, 'title'),
            'description' => data_get($node, 'description'),
            'url' => $cipher ? 'https://www.upwork.com/jobs/'.$cipher : null,
            'job_type' => $isHourly ? 'hourly' : 'fixed',
            'budget_amount' => $isHourly ? null : ($budget !== null ? (float) $budget : null),
            'hourly_min' => $hourlyMin !== null ? (float) $hourlyMin : null,
            'hourly_max' => $hourlyMax !== null ? (float) $hourlyMax : null,
            'currency' => data_get($node, 'amount.currency'),
            'posted_at' => data_get($node, 'createdDateTime'),
            'skills' => collect(data_get($node, 'skills', []))->pluck('name')->filter()->values()->all(),
            'client_country' => data_get($node, 'client.location.country'),
            'client_total_spent' => ($s = data_get($node, 'client.totalSpent.rawValue')) !== null ? (float) $s : null,
            'client_payment_verified' => data_get($node, 'client.verificationStatus') === 'VERIFIED',
        ];
    }
}
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test --filter=UpworkClientTest`
Expected: PASS (all three tests).

- [ ] **Step 6: Commit**

```bash
git add config/variables.php .env.example app/Services/UpworkClient.php tests/Feature/UpworkClientTest.php
git commit -m "feat: add UpworkClient service and Upwork API config"
```

---

### Task 3: `upwork:fetch` command + scheduler entry

**Files:**
- Create: `app/Console/Commands/FetchUpworkJobs.php`
- Modify: `app/Console/Kernel.php` (add schedule entry in `schedule()`)
- Test: `tests/Feature/FetchUpworkJobsTest.php`

**Interfaces:**
- Consumes: `App\Services\UpworkClient::fetchRecentJobs()` (Task 2), `App\Models\UpworkJob` (Task 1).
- Produces: Artisan command signature `upwork:fetch`.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use App\Models\UpworkJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FetchUpworkJobsTest extends TestCase
{
    use RefreshDatabase;

    private function fakeCredsAndApi(): void
    {
        config()->set('variables.upworkAccessToken', 'access-1');
        config()->set('variables.upworkRefreshToken', 'refresh-1');
        config()->set('variables.upworkTenantId', 'tenant-1');
        config()->set('variables.upworkBase', 'https://api.upwork.com/graphql');

        Http::fake([
            'api.upwork.com/graphql' => Http::response([
                'data' => ['marketplaceJobPostingsSearch' => ['edges' => [
                    ['node' => [
                        'id' => '1', 'ciphertext' => '~0abc', 'title' => 'Laravel API',
                        'description' => 'x', 'amount' => ['rawValue' => '500', 'currency' => 'USD'],
                        'createdDateTime' => '2026-08-10T09:00:00Z',
                        'skills' => [['name' => 'PHP']],
                        'client' => ['location' => ['country' => 'US'], 'totalSpent' => ['rawValue' => '10'], 'verificationStatus' => 'VERIFIED'],
                    ]],
                ]]],
            ], 200),
        ]);
    }

    public function test_command_stores_jobs(): void
    {
        $this->fakeCredsAndApi();

        $this->artisan('upwork:fetch')->assertSuccessful();

        $this->assertDatabaseHas('upwork_jobs', ['job_id' => '~0abc', 'title' => 'Laravel API']);
        $this->assertSame(1, UpworkJob::count());
    }

    public function test_command_is_idempotent_on_job_id(): void
    {
        $this->fakeCredsAndApi();

        $this->artisan('upwork:fetch')->assertSuccessful();
        $this->artisan('upwork:fetch')->assertSuccessful();

        $this->assertSame(1, UpworkJob::count());   // updateOrCreate, no duplicate
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=FetchUpworkJobsTest`
Expected: FAIL — command `upwork:fetch` not defined.

- [ ] **Step 3: Write the command**

```php
<?php

namespace App\Console\Commands;

use App\Models\UpworkJob;
use App\Services\UpworkClient;
use Illuminate\Console\Command;

class FetchUpworkJobs extends Command
{
    protected $signature = 'upwork:fetch';

    protected $description = 'Fetch recent Upwork marketplace jobs';

    public function handle(UpworkClient $client): int
    {
        $rows = $client->fetchRecentJobs();

        foreach ($rows as $row) {
            if (empty($row['job_id'])) {
                continue;
            }
            UpworkJob::updateOrCreate(['job_id' => $row['job_id']], $row);
        }

        $this->info('Upwork fetch complete: '.count($rows).' jobs.');

        return self::SUCCESS;
    }
}
```

- [ ] **Step 4: Register in the scheduler**

In `app/Console/Kernel.php`, inside `schedule()`, after the `profiles:sync` block, add:

```php
        $schedule->command('upwork:fetch')
            ->everyThirtyMinutes()
            ->runInBackground()
            ->withoutOverlapping(25);
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test --filter=FetchUpworkJobsTest`
Expected: PASS (both tests).

- [ ] **Step 6: Commit**

```bash
git add app/Console/Commands/FetchUpworkJobs.php app/Console/Kernel.php tests/Feature/FetchUpworkJobsTest.php
git commit -m "feat: add upwork:fetch command and schedule"
```

---

### Task 4: `UpworkController@data` + route + rows partial

**Files:**
- Create: `app/Http/Controllers/UpworkController.php`
- Create: `resources/views/_partials/upwork-row.blade.php`
- Modify: `routes/web.php` (add route inside the authed group, near the `/bids/data` route)
- Test: `tests/Feature/UpworkTabTest.php`

**Interfaces:**
- Consumes: `App\Models\UpworkJob` (Task 1).
- Produces: `GET /bids/upwork/data` (name `bids.upwork.data`) returning JSON `{ rowsHtml, paginationHtml }`. Empty table returns an empty-state `<tr>` in `rowsHtml`.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use App\Models\UpworkJob;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UpworkTabTest extends TestCase
{
    use RefreshDatabase;

    public function test_requires_auth(): void
    {
        $this->getJson('/bids/upwork/data')->assertUnauthorized();
    }

    public function test_lists_jobs(): void
    {
        UpworkJob::factory()->create([
            'job_id' => '~0abc',
            'title' => 'Senior Laravel Engineer',
            'url' => 'https://www.upwork.com/jobs/~0abc',
            'job_type' => 'fixed',
            'budget_amount' => 750,
            'currency' => 'USD',
            'skills' => ['PHP', 'Laravel'],
            'client_country' => 'United States',
        ]);

        $res = $this->actingAs(User::factory()->create())
            ->getJson('/bids/upwork/data')->assertOk();

        $html = $res->json('rowsHtml');
        $this->assertStringContainsString('Senior Laravel Engineer', $html);
        $this->assertStringContainsString('https://www.upwork.com/jobs/~0abc', $html);
        $this->assertStringContainsString('Laravel', $html);
        $this->assertStringContainsString('United States', $html);
        $this->assertArrayHasKey('paginationHtml', $res->json());
    }

    public function test_empty_state(): void
    {
        $res = $this->actingAs(User::factory()->create())
            ->getJson('/bids/upwork/data')->assertOk();

        $this->assertStringContainsString('No Upwork jobs yet', $res->json('rowsHtml'));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=UpworkTabTest`
Expected: FAIL — route `/bids/upwork/data` not defined (404/500).

- [ ] **Step 3: Write the rows partial**

`resources/views/_partials/upwork-row.blade.php`:

```blade
@php
    $budget = $job->job_type === 'hourly'
        ? trim(($job->hourly_min ? '$'.rtrim(rtrim(number_format($job->hourly_min, 2), '0'), '.') : '') .
               ($job->hourly_max ? ' – $'.rtrim(rtrim(number_format($job->hourly_max, 2), '0'), '.') : '') . '/hr')
        : ($job->budget_amount ? ($job->currency ? $job->currency.' ' : '$').number_format($job->budget_amount, 0) : '—');
    $budget = $budget !== '' ? $budget : '—';
@endphp
<tr>
    <td>
        <a href="{{ $job->url }}" target="_blank" rel="noopener" class="fw-semibold text-body">
            {{ $job->title ?? 'Untitled' }}
        </a>
    </td>
    <td class="text-nowrap">{{ $budget }}</td>
    <td class="text-nowrap">{{ $job->posted_at?->diffForHumans() ?? '—' }}</td>
    <td>
        @foreach (($job->skills ?? []) as $skill)
            <span class="badge bg-label-primary me-1 mb-1">{{ $skill }}</span>
        @endforeach
    </td>
    <td class="text-nowrap">
        {{ $job->client_country ?? '—' }}
        @if ($job->client_payment_verified)
            <i class="bx bx-badge-check text-success" title="Payment verified"></i>
        @endif
        @if ($job->client_total_spent)
            <div class="text-muted small">${{ number_format($job->client_total_spent, 0) }} spent</div>
        @endif
    </td>
</tr>
```

- [ ] **Step 4: Write the controller**

```php
<?php

namespace App\Http\Controllers;

use App\Models\UpworkJob;
use Illuminate\Http\Request;

class UpworkController extends Controller
{
    public function data(Request $request)
    {
        $jobs = UpworkJob::orderByDesc('posted_at')->orderByDesc('id')->paginate(50);

        $rowsHtml = '';
        foreach ($jobs as $job) {
            $rowsHtml .= view('_partials.upwork-row', ['job' => $job])->render();
        }

        if ($rowsHtml === '') {
            $rowsHtml = '<tr><td colspan="5" class="text-center text-muted py-4">No Upwork jobs yet.</td></tr>';
        }

        return response()->json([
            'rowsHtml' => $rowsHtml,
            'paginationHtml' => $jobs->links('vendor.pagination.bootstrap-5')->render(),
        ]);
    }
}
```

- [ ] **Step 5: Add the route**

In `routes/web.php`, inside the same authenticated group as `/bids/data` (near line 82), add:

```php
    Route::get('/bids/upwork/data', [\App\Http\Controllers\UpworkController::class, 'data'])->name('bids.upwork.data');
```

- [ ] **Step 6: Run test to verify it passes**

Run: `php artisan test --filter=UpworkTabTest`
Expected: PASS (all three tests).

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/UpworkController.php resources/views/_partials/upwork-row.blade.php routes/web.php tests/Feature/UpworkTabTest.php
git commit -m "feat: add Upwork jobs data endpoint and row partial"
```

---

### Task 5: Marketplace switcher UI in the Opportunities page

**Files:**
- Modify: `resources/views/content/pages/home.blade.php`
- Test: `tests/Feature/OpportunitiesMarketplaceTabsTest.php`

**Interfaces:**
- Consumes: `GET /bids/upwork/data` (Task 4).
- Produces: no server interface; a UI switcher rendered on `GET /bids`.

**Design notes:** The existing content (filter bar, cards, inner tabs, table, offcanvas — current lines 10–300) is wrapped unchanged inside a Freelancer pane. A sibling Upwork pane holds a new table. A tab strip with two brand-icon buttons toggles panes and reflects `?market=` in the URL. The Upwork pane lazy-loads once, then on pagination. Freelancer's existing `setInterval` polling must not run while Upwork is active — guard it with the active-market check.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use App\Models\User;
use Tests\TestCase;

class OpportunitiesMarketplaceTabsTest extends TestCase
{
    use \Illuminate\Foundation\Testing\RefreshDatabase;

    public function test_page_shows_both_marketplace_tabs_and_upwork_pane(): void
    {
        $res = $this->actingAs(User::factory()->create())->get('/bids')->assertOk();

        $res->assertSee('data-market="freelancer"', false);
        $res->assertSee('data-market="upwork"', false);
        // Upwork pane table header columns
        $res->assertSeeInOrder(['id="market-pane-upwork"'], false);
        $res->assertSee('/bids/upwork/data', false);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=OpportunitiesMarketplaceTabsTest`
Expected: FAIL — markers not present.

- [ ] **Step 3: Add the marketplace tab strip**

In `resources/views/content/pages/home.blade.php`, replace the page-title header block (current lines 6–8):

```blade
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
        <h4 class="page-title mb-0">Opportunities</h4>
    </div>
```

with the title plus a marketplace tab strip (brand icons inline as SVG):

```blade
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
        <h4 class="page-title mb-0">Opportunities</h4>
    </div>

    <style>
        #market-tabs .nav-link { color:#6c757d; font-weight:600; border:0; border-bottom:3px solid transparent; border-radius:0; padding:.5rem 1.25rem; display:flex; align-items:center; gap:.5rem; }
        #market-tabs .nav-link.active { color:#696cff; background-color:rgba(105,108,255,.12); border-bottom-color:#696cff; border-radius:.375rem .375rem 0 0; }
        #market-tabs .nav-link svg { width:18px; height:18px; }
    </style>

    <ul class="nav nav-tabs mb-3" id="market-tabs">
        <li class="nav-item">
            <button class="nav-link active" data-market="freelancer" type="button">
                <svg viewBox="0 0 24 24" fill="currentColor"><path d="M2 3h20l-2.4 6H8.8l.6 2H18l-1.2 4H8l-1-4L4.5 3H2z"/></svg>
                Freelancer
            </button>
        </li>
        <li class="nav-item">
            <button class="nav-link" data-market="upwork" type="button">
                <svg viewBox="0 0 24 24" fill="currentColor"><path d="M18.6 6.3c-2 0-3.6 1.3-4.3 3.4-1-1.5-1.7-3.3-2.1-4.9H8.9v6c0 1.2-1 2.2-2.2 2.2s-2.2-1-2.2-2.2v-6H1.8v6c0 2.7 2.2 4.9 4.9 4.9 2.7 0 4.9-2.2 4.9-4.9v-1c.4.8.9 1.6 1.4 2.4l-1.6 7.6h2.7l1.1-5.4c.9.6 2 .9 3.4.9 2.8 0 5-2.3 5-5.2s-2.2-5.2-5-5.2zm0 7.7c-1 0-2-.4-2.8-1.1l.3-1.1v-.1c.2-1.2.9-2.7 2.5-2.7 1.3 0 2.3 1.1 2.3 2.5s-1 2.6-2.3 2.6z"/></svg>
                Upwork
            </button>
        </li>
    </ul>
```

- [ ] **Step 4: Wrap existing content in the Freelancer pane, add the Upwork pane**

Wrap everything from the filter bar (current line 11 `{{-- Filter bar ... --}}`) through the offcanvas close (current line 300 `</div>` of `#bidOffcanvas`) in:

```blade
    <div id="market-pane-freelancer">
        {{-- existing filter bar, cards, tabs, table, offcanvas UNCHANGED --}}
        ... (all current lines 11–300) ...
    </div>

    <div id="market-pane-upwork" class="d-none">
        <div class="card">
            <div class="table-responsive">
                <table class="table table-hover align-middle bids-table mb-0">
                    <thead>
                        <tr>
                            <th>Title</th>
                            <th>Budget / Rate</th>
                            <th>Posted</th>
                            <th>Skills</th>
                            <th>Client</th>
                        </tr>
                    </thead>
                    <tbody id="upwork-tbody">
                        <tr><td colspan="5" class="text-center text-muted py-4">Loading…</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
        <div class="mt-4 card px-4 pt-3" id="upwork-pagination"></div>
    </div>
```

- [ ] **Step 5: Add the marketplace switch script**

At the end of the existing `@section('page-script')` IIFE (just before its closing `})();` on current line 692), add the marketplace controller. It must (a) toggle panes, (b) sync `?market=`, (c) lazy-load the Upwork pane, (d) prevent the Freelancer `setInterval` poll from running while Upwork is active:

```javascript
            // ---- Marketplace switcher (Freelancer | Upwork) ----
            let currentMarket = 'freelancer';
            let upworkLoaded = false;
            let upworkPage = 1;

            function applyMarketToUrl() {
                const p = new URLSearchParams(window.location.search);
                if (currentMarket === 'upwork') p.set('market', 'upwork'); else p.delete('market');
                history.replaceState(null, '', window.location.pathname + (p.toString() ? '?' + p.toString() : ''));
            }

            async function loadUpwork() {
                try {
                    const res = await fetch('/bids/upwork/data?page=' + upworkPage, {
                        headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
                    });
                    if (!res.ok) return;
                    const data = await res.json();
                    el('upwork-tbody').innerHTML = data.rowsHtml;
                    el('upwork-pagination').innerHTML = data.paginationHtml;
                    el('upwork-pagination').style.display = data.paginationHtml.trim() ? '' : 'none';
                    upworkLoaded = true;
                } catch (e) { /* keep last render */ }
            }

            function switchMarket(market) {
                currentMarket = market;
                document.querySelectorAll('#market-tabs .nav-link').forEach(b =>
                    b.classList.toggle('active', b.dataset.market === market));
                el('market-pane-freelancer').classList.toggle('d-none', market !== 'freelancer');
                el('market-pane-upwork').classList.toggle('d-none', market !== 'upwork');
                applyMarketToUrl();
                if (market === 'upwork' && !upworkLoaded) loadUpwork();
            }

            document.querySelectorAll('#market-tabs .nav-link').forEach(btn =>
                btn.addEventListener('click', () => switchMarket(btn.dataset.market)));

            el('upwork-pagination').addEventListener('click', function (ev) {
                const a = ev.target.closest('a');
                if (!a) return;
                ev.preventDefault();
                const page = new URL(a.href, window.location.origin).searchParams.get('page');
                if (page) { upworkPage = parseInt(page, 10); loadUpwork(); }
            });

            // Restore market from URL on load
            if (new URLSearchParams(window.location.search).get('market') === 'upwork') {
                switchMarket('upwork');
            }
```

Then guard the existing Freelancer auto-refresh (current line 659) so it pauses on the Upwork pane. Change:

```javascript
            setInterval(() => { if (!searchFocused && currentPage === 1) loadData(); }, 15000);
```

to:

```javascript
            setInterval(() => { if (currentMarket === 'freelancer' && !searchFocused && currentPage === 1) loadData(); }, 15000);
```

Note: `currentMarket` is declared above the `setInterval` line within the same IIFE, so it is in scope.

- [ ] **Step 6: Run test to verify it passes**

Run: `php artisan test --filter=OpportunitiesMarketplaceTabsTest`
Expected: PASS.

- [ ] **Step 7: Run the full Upwork-related suite + build assets**

Run:
```bash
php artisan test --filter='Upwork|UpworkClient|FetchUpworkJobs|OpportunitiesMarketplaceTabs'
npm run dev
```
Expected: all green; asset build succeeds (no JS syntax errors in the blade script — it is inline, so `npm run dev` won't catch it, but a manual `/bids` load should show both tabs and switching works).

- [ ] **Step 8: Commit**

```bash
git add resources/views/content/pages/home.blade.php tests/Feature/OpportunitiesMarketplaceTabsTest.php
git commit -m "feat: add Freelancer/Upwork marketplace switcher to Opportunities page"
```

---

## Self-Review

**Spec coverage:**
- Config/credentials → Task 2 (Step 1). ✓
- `upwork_jobs` table + model → Task 1. ✓
- Crawl service (fetch, refresh-on-401, normalize) → Task 2. ✓
- Command + schedule → Task 3. ✓
- Controller `data` + route + partial → Task 4. ✓
- UI switcher with Freelancer (untouched) + Upwork panes, brand icons, lazy-load, `?market=` URL sync, poll guard → Task 5. ✓
- Empty-state when creds blank / table empty → Task 2 (returns `[]`), Task 4 (empty-state row). ✓
- Tests: unit normalize + refresh (Task 2), feature endpoint (Task 4), command store (Task 3), UI markers (Task 5). ✓
- Non-goals (no proposals/bidding/detail/filters) → respected; none added. ✓

**Placeholder scan:** No TBD/TODO. The GraphQL selection set is concrete; the plan notes it is confirmed against live docs when real credentials arrive, but the test-driven normalize contract is fully specified and internally consistent. ✓

**Type consistency:** `fetchRecentJobs()` returns rows whose keys exactly match `UpworkJob` fillable (Task 1 ↔ Task 2 ↔ Task 3 `updateOrCreate`). `job_id` is the dedup key everywhere. `/bids/upwork/data` returns `{ rowsHtml, paginationHtml }`, consumed by `loadUpwork()` in Task 5. Partial variable is `$job` in both Task 4 controller and partial. ✓
