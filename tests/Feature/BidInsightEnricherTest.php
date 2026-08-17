<?php

namespace Tests\Feature;

use App\Models\Bid;
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

    /** A qualified proposal with a placed bid, the shape every blank row has. */
    private function placedBid(int $projectId, array $proposal = [], array $bid = []): Proposal
    {
        $p = Proposal::factory()->create(array_merge([
            'project_id' => $projectId,
            'qualified' => true,
            'seo_url' => 'php/Some-Project',
            'currency_name' => 'USD',
        ], $proposal));

        Bid::factory()->create(array_merge([
            'proposal_id' => $p->id,
            'bid_status' => 'completed',
            'price' => 250,
        ], $bid));

        return $p;
    }

    public function test_own_bid_is_read_back_for_a_row_the_capture_never_covered(): void
    {
        $posted = Carbon::parse('2026-08-13 21:21:42');
        $this->placedBid(555, ['project_added_time' => $posted->getTimestamp()]);
        // Blank row — ThreadSyncer created it from the client's identity alone.
        $insight = BidInsight::create(['project_id' => 555, 'client_name' => 'Ada', 'last_scraped_at' => now()]);

        Http::fake(['*bids*' => Http::response(['result' => ['bids' => [[
            'id' => 492337425,
            'project_id' => 555,
            'amount' => 675,
            'time_submitted' => $posted->copy()->addSeconds(72)->getTimestamp(),
            'description' => 'With my experience...',
        ]]]], 200)]);

        $result = (new BidInsightEnricher)->run();

        $insight->refresh();
        $this->assertSame(1, $result['own_bid']);
        $this->assertSame(492337425, $insight->bid_id);
        $this->assertEquals(675, $insight->bid_amount);
        $this->assertSame('2026-08-13 21:22:54', $insight->time_submitted->format('Y-m-d H:i:s'));
        $this->assertSame('With my experience...', $insight->description);
        // And the submit time it just recovered feeds time to bid in the same run.
        $this->assertSame(72, $insight->time_to_bid_seconds);
    }

    public function test_a_project_freelancer_no_longer_serves_still_shows_what_we_bid(): void
    {
        $this->placedBid(555, [], ['price' => 320]);
        $insight = BidInsight::create(['project_id' => 555, 'last_scraped_at' => now()]);

        Http::fake(['*bids*' => Http::response(['result' => ['bids' => []]], 200)]);

        $this->assertSame(1, (new BidInsightEnricher)->backfillOwnBids());

        $insight->refresh();
        $this->assertEquals(320, $insight->bid_amount);
        // posted_at only approximates the real submit time, so it is not used —
        // an invented time to bid would be worse than none.
        $this->assertNull($insight->time_submitted);
        $this->assertNull($insight->time_to_bid_seconds);
    }

    public function test_projects_we_never_placed_a_bid_on_are_not_polled(): void
    {
        Http::fake(['*bids*' => Http::response(['result' => ['bids' => []]], 200)]);
        Proposal::factory()->create(['project_id' => 555, 'qualified' => true]);
        Bid::factory()->create([
            'proposal_id' => Proposal::where('project_id', 555)->value('id'),
            'bid_status' => 'Failed',
        ]);
        BidInsight::create(['project_id' => 555, 'last_scraped_at' => now()]);

        $this->assertSame(0, (new BidInsightEnricher)->backfillOwnBids());
        Http::assertNothingSent();
    }

    public function test_own_bid_backfill_leaves_captured_values_alone(): void
    {
        $this->placedBid(555);
        $insight = BidInsight::create([
            'project_id' => 555,
            'bid_amount' => 250,
            'description' => 'From the capture',
            'last_scraped_at' => now(),
        ]);

        Http::fake(['*bids*' => Http::response(['result' => ['bids' => [[
            'id' => 42, 'project_id' => 555, 'amount' => 999, 'description' => 'From the API',
        ]]]], 200)]);

        $this->assertSame(1, (new BidInsightEnricher)->backfillOwnBids());

        $insight->refresh();
        $this->assertEquals(250, $insight->bid_amount);
        $this->assertSame('From the capture', $insight->description);
        $this->assertSame(42, $insight->bid_id);
    }

    public function test_currency_and_project_link_come_off_the_proposal(): void
    {
        Proposal::factory()->create([
            'project_id' => 555,
            'currency_name' => 'GBP',
            'seo_url' => 'research/Sourcer-for-Listing-Agent-Hiring',
        ]);
        $insight = BidInsight::create(['project_id' => 555, 'bid_amount' => 250, 'last_scraped_at' => now()]);

        $this->assertSame(1, (new BidInsightEnricher)->backfillProjectFacts());

        $insight->refresh();
        $this->assertSame('GBP', $insight->bid_currency);
        $this->assertSame(
            'https://www.freelancer.com/projects/research/Sourcer-for-Listing-Agent-Hiring',
            $insight->project_url
        );
    }

    public function test_project_facts_do_not_overwrite_what_the_crawler_sent(): void
    {
        Proposal::factory()->create(['project_id' => 555, 'currency_name' => 'GBP', 'seo_url' => 'php/x']);
        $insight = BidInsight::create([
            'project_id' => 555,
            'bid_currency' => 'USD',
            'project_url' => 'https://www.freelancer.com/projects/php/from-the-capture',
            'last_scraped_at' => now(),
        ]);

        $this->assertSame(0, (new BidInsightEnricher)->backfillProjectFacts());

        $insight->refresh();
        $this->assertSame('USD', $insight->bid_currency);
        $this->assertSame('https://www.freelancer.com/projects/php/from-the-capture', $insight->project_url);
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
            ->expectsOutputToContain('Own bid filled: 0')
            ->expectsOutputToContain('Time to bid filled: 1')
            ->expectsOutputToContain('Winning bid filled: 1')
            ->assertExitCode(0);
    }
}
