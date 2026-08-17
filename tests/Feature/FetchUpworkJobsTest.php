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

    public function test_command_fails_when_credentials_are_missing(): void
    {
        config()->set('variables.upworkAccessToken', null);
        config()->set('variables.upworkRefreshToken', null);

        $this->artisan('upwork:fetch')->assertFailed();
    }

    public function test_command_fails_when_upwork_returns_an_error(): void
    {
        config()->set('variables.upworkAccessToken', 'access-1');
        config()->set('variables.upworkRefreshToken', 'refresh-1');
        config()->set('variables.upworkBase', 'https://api.upwork.com/graphql');

        Http::fake(['api.upwork.com/graphql' => Http::response(['errors' => [['message' => 'nope']]], 200)]);

        // A rejected query must not look like a successful empty fetch.
        $this->artisan('upwork:fetch')->assertFailed();
        $this->assertSame(0, UpworkJob::count());
    }

    public function test_command_succeeds_when_search_matches_nothing(): void
    {
        config()->set('variables.upworkAccessToken', 'access-1');
        config()->set('variables.upworkRefreshToken', 'refresh-1');
        config()->set('variables.upworkBase', 'https://api.upwork.com/graphql');

        Http::fake(['api.upwork.com/graphql' => Http::response([
            'data' => ['marketplaceJobPostingsSearch' => ['edges' => []]],
        ], 200)]);

        $this->artisan('upwork:fetch')->assertSuccessful();
    }
}
