<?php

namespace Tests\Feature;

use App\Exceptions\UpworkFetchException;
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

    public function test_throws_when_credentials_missing(): void
    {
        config()->set('variables.upworkAccessToken', null);
        config()->set('variables.upworkRefreshToken', null);
        Cache::forget('upwork_access_token');

        Http::fake();

        $this->expectException(UpworkFetchException::class);

        try {
            (new UpworkClient)->fetchRecentJobs();
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_throws_on_graphql_errors_returned_with_http_200(): void
    {
        $this->setCreds();
        Cache::forget('upwork_access_token');

        Http::fake(['api.upwork.com/graphql' => Http::response([
            'errors' => [['message' => 'Cannot query field "ciphertext"']],
        ], 200)]);

        $this->expectException(UpworkFetchException::class);
        $this->expectExceptionMessage('Cannot query field "ciphertext"');

        (new UpworkClient)->fetchRecentJobs();
    }

    public function test_throws_on_non_successful_response(): void
    {
        $this->setCreds();
        Cache::forget('upwork_access_token');

        Http::fake(['api.upwork.com/graphql' => Http::response(['oops' => true], 500)]);

        $this->expectException(UpworkFetchException::class);
        $this->expectExceptionMessage('HTTP 500');

        (new UpworkClient)->fetchRecentJobs();
    }

    public function test_returns_empty_array_when_search_matches_nothing(): void
    {
        $this->setCreds();
        Cache::forget('upwork_access_token');

        Http::fake(['api.upwork.com/graphql' => Http::response([
            'data' => ['marketplaceJobPostingsSearch' => ['edges' => []]],
        ], 200)]);

        // No jobs is a normal outcome, not a failure.
        $this->assertSame([], (new UpworkClient)->fetchRecentJobs());
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

    public function test_omits_tenant_header_when_not_configured(): void
    {
        $this->setCreds();
        config()->set('variables.upworkTenantId', '');
        Cache::forget('upwork_access_token');

        Http::fake(['api.upwork.com/graphql' => Http::response($this->jobsResponse(), 200)]);

        (new UpworkClient)->fetchRecentJobs();

        Http::assertSent(fn ($request) => ! $request->hasHeader('X-Upwork-API-TenantId'));
    }

    public function test_mints_access_token_when_only_refresh_token_is_available(): void
    {
        $this->setCreds();
        config()->set('variables.upworkAccessToken', null);
        Cache::forget('upwork_access_token');

        Http::fake([
            'www.upwork.com/api/v3/oauth2/token' => Http::response(['access_token' => 'minted'], 200),
            'api.upwork.com/graphql' => Http::response($this->jobsResponse(), 200),
        ]);

        $rows = (new UpworkClient)->fetchRecentJobs();

        $this->assertCount(1, $rows);
        $this->assertSame('minted', Cache::get('upwork_access_token'));
        // Refreshed before the first GraphQL call, not after a wasted 401.
        Http::assertSentCount(2);
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
