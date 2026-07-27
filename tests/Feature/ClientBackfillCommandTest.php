<?php

namespace Tests\Feature;

use App\Models\BidInsight;
use App\Models\Proposal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ClientBackfillCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['variables.flBase' => 'https://www.freelancer.com', 'variables.flKey' => 'tok']);
    }

    private function ownerProject(int $id): array
    {
        return [
            'id' => $id,
            'owner_info' => [
                'display_name' => 'Ada Client',
                'country' => ['name' => 'Nigeria', 'flag_url_cdn' => '//cdn/ng.png'],
                'registration_date' => 1700000000,
                'status' => ['payment_verified' => true],
                'reputation' => ['entire_history' => ['overall' => 5, 'reviews' => 2, 'complete' => 4]],
            ],
        ];
    }

    public function test_backfills_missing_client_info_and_requests_owner_info(): void
    {
        Proposal::factory()->create(['project_id' => 555]);

        Http::fake([
            '*projects/*' => Http::response([
                'status' => 'success',
                'result' => ['projects' => [$this->ownerProject(555)], 'users' => []],
            ], 200),
        ]);

        $this->artisan('client:backfill')->assertSuccessful();

        $insight = BidInsight::where('project_id', 555)->first();
        $this->assertNotNull($insight);
        $this->assertSame('Nigeria', $insight->client_country);
        $this->assertSame('Ada Client', $insight->client_name);
        $this->assertSame(2, $insight->client_reviews);

        Http::assertSent(fn ($r) => str_contains($r->url(), 'owner_info=true')
            && str_contains($r->url(), 'projects%5B%5D=555') || str_contains($r->url(), 'projects[]=555'));
    }

    public function test_skips_proposals_that_already_have_client_info(): void
    {
        Proposal::factory()->create(['project_id' => 777]);
        BidInsight::create(['project_id' => 777, 'client_country' => 'Canada', 'last_scraped_at' => now()]);

        Http::fake();

        $this->artisan('client:backfill')->assertSuccessful();

        Http::assertNothingSent();
        $this->assertSame('Canada', BidInsight::where('project_id', 777)->first()->client_country);
    }
}
