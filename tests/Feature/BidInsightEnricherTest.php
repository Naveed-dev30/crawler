<?php

namespace Tests\Feature;

use App\Models\BidInsight;
use App\Models\Proposal;
use App\Services\BidInsightEnricher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BidInsightEnricherTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['variables.flKey' => 'test-key', 'variables.flBase' => 'https://www.freelancer.com']);
    }

    private function fakeAwardedBids(array $bids): void
    {
        Http::fake([
            '*bids*' => Http::response(['status' => 'success', 'result' => ['bids' => $bids]], 200),
        ]);
    }

    public function test_time_to_bid_is_derived_from_the_projects_posted_time(): void
    {
        $posted = Carbon::parse('2026-08-13 21:21:42');
        Proposal::factory()->create(['project_id' => 555, 'project_added_time' => $posted->getTimestamp()]);
        $insight = BidInsight::create([
            'project_id' => 555,
            'time_submitted' => $posted->copy()->addSeconds(72),
            'last_scraped_at' => now(),
        ]);

        $this->assertSame(1, (new BidInsightEnricher)->backfillTimeToBid());
        $this->assertSame(72, $insight->refresh()->time_to_bid_seconds);
    }

    public function test_time_to_bid_skips_rows_without_a_proposal_or_with_a_negative_delta(): void
    {
        $posted = Carbon::parse('2026-08-13 21:21:42');
        Proposal::factory()->create(['project_id' => 556, 'project_added_time' => $posted->getTimestamp()]);

        $noProposal = BidInsight::create(['project_id' => 777, 'time_submitted' => $posted, 'last_scraped_at' => now()]);
        $beforePosting = BidInsight::create([
            'project_id' => 556,
            'time_submitted' => $posted->copy()->subMinute(),
            'last_scraped_at' => now(),
        ]);

        $this->assertSame(0, (new BidInsightEnricher)->backfillTimeToBid());
        $this->assertNull($noProposal->refresh()->time_to_bid_seconds);
        $this->assertNull($beforePosting->refresh()->time_to_bid_seconds);
    }

    public function test_time_to_bid_leaves_a_value_the_crawler_already_sent(): void
    {
        Proposal::factory()->create(['project_id' => 555, 'project_added_time' => now()->subHour()->getTimestamp()]);
        $insight = BidInsight::create([
            'project_id' => 555,
            'time_submitted' => now(),
            'time_to_bid_seconds' => 94,
            'last_scraped_at' => now(),
        ]);

        $this->assertSame(0, (new BidInsightEnricher)->backfillTimeToBid());
        $this->assertSame(94, $insight->refresh()->time_to_bid_seconds);
    }

    public function test_winning_bid_is_stored_even_when_somebody_else_won(): void
    {
        $this->fakeAwardedBids([
            ['project_id' => 555, 'bidder_id' => 41059193, 'award_status' => 'awarded',
                'amount' => 1200, 'sealed' => false, 'description' => 'Hi — Elias here.'],
        ]);
        $insight = BidInsight::create(['project_id' => 555, 'bid_id' => 9001, 'last_scraped_at' => now()]);

        $this->assertSame(1, (new BidInsightEnricher)->syncWinningBids());

        $insight->refresh();
        $this->assertEquals(1200, $insight->winning_bid_amount);
        $this->assertFalse($insight->winning_bid_sealed);
        $this->assertSame('Hi — Elias here.', $insight->winning_bid_text);
    }

    public function test_sealed_winner_is_recorded_as_sealed_not_as_zero(): void
    {
        $this->fakeAwardedBids([
            ['project_id' => 555, 'award_status' => 'awarded', 'amount' => 0, 'sealed' => true],
        ]);
        $insight = BidInsight::create(['project_id' => 555, 'bid_amount' => 250, 'last_scraped_at' => now()]);

        $this->assertSame(1, (new BidInsightEnricher)->syncWinningBids());

        $insight->refresh();
        $this->assertTrue($insight->winning_bid_sealed);
        $this->assertNull($insight->winning_bid_amount);
    }

    public function test_projects_without_an_award_and_client_only_rows_are_left_alone(): void
    {
        $this->fakeAwardedBids([]);
        $stillOpen = BidInsight::create(['project_id' => 555, 'bid_id' => 9001, 'last_scraped_at' => now()]);
        $clientOnly = BidInsight::create(['project_id' => 556, 'client_name' => 'Ada', 'last_scraped_at' => now()]);

        $this->assertSame(0, (new BidInsightEnricher)->syncWinningBids());
        $this->assertNull($stillOpen->refresh()->winning_bid_amount);
        $this->assertNull($clientOnly->refresh()->winning_bid_amount);

        // Client-only rows carry no bid, so they are never even asked about.
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => str_contains(urldecode($request->url()), 'projects[]=555')
            && ! str_contains(urldecode($request->url()), 'projects[]=556'));
    }

    public function test_an_already_recorded_winner_is_not_refetched(): void
    {
        $this->fakeAwardedBids([]);
        BidInsight::create([
            'project_id' => 555,
            'bid_id' => 9001,
            'winning_bid_amount' => 220,
            'last_scraped_at' => now(),
        ]);

        $this->assertSame(0, (new BidInsightEnricher)->syncWinningBids());
        Http::assertNothingSent();
    }

    public function test_days_window_limits_which_projects_are_checked(): void
    {
        $this->fakeAwardedBids([]);
        BidInsight::create(['project_id' => 555, 'bid_id' => 9001, 'last_scraped_at' => now()->subDays(90)]);

        $this->assertSame(0, (new BidInsightEnricher)->syncWinningBids(60));
        Http::assertNothingSent();

        $this->assertSame(0, (new BidInsightEnricher)->syncWinningBids(0));
        Http::assertSentCount(1);
    }

    public function test_multi_award_projects_keep_the_first_real_amount(): void
    {
        // Freelancer lets a client award several freelancers on one project.
        $this->fakeAwardedBids([
            ['project_id' => 555, 'award_status' => 'awarded', 'amount' => 0, 'sealed' => true],
            ['project_id' => 555, 'award_status' => 'awarded', 'amount' => 900, 'sealed' => false],
            ['project_id' => 555, 'award_status' => 'awarded', 'amount' => 1500, 'sealed' => false],
        ]);
        $insight = BidInsight::create(['project_id' => 555, 'bid_id' => 9001, 'last_scraped_at' => now()]);

        $this->assertSame(2, (new BidInsightEnricher)->syncWinningBids());

        $insight->refresh();
        $this->assertEquals(900, $insight->winning_bid_amount);
        $this->assertFalse($insight->winning_bid_sealed);
    }

    public function test_a_failed_request_does_not_blow_up_the_run(): void
    {
        Http::fake(['*bids*' => Http::response('nope', 500)]);
        $insight = BidInsight::create(['project_id' => 555, 'bid_id' => 9001, 'last_scraped_at' => now()]);

        $this->assertSame(0, (new BidInsightEnricher)->syncWinningBids());
        $this->assertNull($insight->refresh()->winning_bid_amount);
    }

    public function test_command_reports_what_it_filled(): void
    {
        $posted = now()->subMinutes(3);
        Proposal::factory()->create(['project_id' => 555, 'project_added_time' => $posted->getTimestamp()]);
        BidInsight::create(['project_id' => 555, 'time_submitted' => $posted->copy()->addSeconds(30), 'last_scraped_at' => now()]);
        $this->fakeAwardedBids([
            ['project_id' => 555, 'award_status' => 'awarded', 'amount' => 400, 'sealed' => false],
        ]);

        $this->artisan('insights:enrich-bids')
            ->expectsOutputToContain('Time to bid filled: 1')
            ->expectsOutputToContain('Winning bid filled: 1')
            ->assertExitCode(0);
    }
}
