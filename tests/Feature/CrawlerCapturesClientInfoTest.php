<?php

namespace Tests\Feature;

use App\Http\Controllers\ProposalController;
use App\Models\BidInsight;
use App\Models\Filter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class CrawlerCapturesClientInfoTest extends TestCase
{
    use RefreshDatabase;

    private function filter(): void
    {
        Filter::factory()->create([
            'id' => 1, 'crawler_on' => 1, 'useminfix' => 0,
            'useminhour' => 0, 'usekeywords' => 0, 'usecountries' => 0,
        ]);
    }

    private function fakeProjects(array $project, array $users): void
    {
        Http::fake([
            '*support*' => Http::response(['result' => null], 200),
            '*projects/active*' => Http::response([
                'status' => 'success',
                'result' => ['projects' => [$project], 'users' => $users],
            ], 200),
        ]);
    }

    private function baseProject(): array
    {
        return [
            'id' => 555,
            'title' => 'Build a Laravel API',
            'description' => 'desc',
            'seo_url' => 'build-laravel-api',
            'type' => 'fixed',
            'language' => 'en',
            'owner_id' => 42,
            'time_submitted' => 1700000000,
            'budget' => ['minimum' => 250, 'maximum' => 750],
            'currency' => ['code' => 'EUR', 'sign' => '€', 'country' => 'Germany', 'exchange_rate' => 1.1],
            'upgrades' => ['NDA' => false, 'sealed' => false],
            'jobs' => [['id' => 1, 'name' => 'PHP']],
        ];
    }

    public function test_request_carries_client_detail_flags(): void
    {
        Queue::fake();
        $this->filter();
        Http::fake([
            '*support*' => Http::response(['result' => null], 200),
            '*projects/active*' => Http::response(['status' => 'success', 'result' => ['projects' => []]], 200),
        ]);

        (new ProposalController)->getProposals();

        Http::assertSent(function ($request) {
            $url = $request->url();

            return str_contains($url, 'projects/active')
                && str_contains($url, 'owner_info=1')
                && str_contains($url, 'user_details=1')
                && str_contains($url, 'user_avatar=1')
                && str_contains($url, 'user_display_info=1')
                && str_contains($url, 'user_employer_reputation=1')
                && str_contains($url, 'user_country_details=1');
        });
    }

    public function test_upserts_bid_insight_from_employer_reputation(): void
    {
        Queue::fake();
        $this->filter();

        // owner_info=true attaches the client object directly (real shape:
        // reputation.entire_history, top-level country, status, registration_date).
        $project = $this->baseProject();
        $project['invited_freelancers'] = [1, 2];
        $project['owner_info'] = [
            'display_name' => 'Acme Corp',
            'avatar_cdn' => 'https://cdn.freelancer.com/avatar/42.jpg',
            'registration_date' => 1700000000,
            'country' => ['name' => 'Nigeria', 'flag_url_cdn' => '//cdn.f-cdn.com/img/flags/png/ng.png'],
            'status' => ['payment_verified' => true, 'email_verified' => true],
            'reputation' => ['entire_history' => [
                'overall' => 5, 'reviews' => 1, 'complete' => 3,
            ]],
        ];

        $this->fakeProjects($project, []); // no users map — owner_info is primary

        (new ProposalController)->getProposals();

        $insight = BidInsight::where('project_id', 555)->first();
        $this->assertNotNull($insight, 'crawler should create a bid_insights row when none exists');
        $this->assertSame('Acme Corp', $insight->client_name);
        $this->assertSame('https://cdn.freelancer.com/avatar/42.jpg', $insight->client_avatar);
        $this->assertSame('Nigeria', $insight->client_country);
        $this->assertSame('//cdn.f-cdn.com/img/flags/png/ng.png', $insight->client_country_flag);
        $this->assertSame('5.00', (string) $insight->client_rating);
        $this->assertSame(1, $insight->client_reviews);
        $this->assertTrue($insight->client_verification['payment_verified']);
        $this->assertNotNull($insight->client_member_since);
        $this->assertSame(3, $insight->client_engagement['completed']);
        $this->assertSame(2, $insight->client_engagement['invited']);
    }

    public function test_does_not_clobber_existing_insight_with_missing_fields(): void
    {
        Queue::fake();
        $this->filter();

        BidInsight::create([
            'project_id' => 555,
            'client_country' => 'Canada',
            'client_rating' => 4.5,
            'client_reviews' => 9,
            'last_scraped_at' => now(),
        ]);

        // Payload with no users map — nothing to update; existing data stays.
        $this->fakeProjects($this->baseProject(), []);

        (new ProposalController)->getProposals();

        $insight = BidInsight::where('project_id', 555)->first();
        $this->assertSame('Canada', $insight->client_country);
        $this->assertSame('4.50', (string) $insight->client_rating);
        $this->assertSame(9, $insight->client_reviews);
    }
}
