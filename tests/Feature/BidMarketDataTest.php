<?php

// tests/Feature/BidMarketDataTest.php

namespace Tests\Feature;

use App\Jobs\RefreshBidMarketDataJob;
use App\Models\BidInsight;
use App\Models\User;
use App\Services\FreelancerProjectStats;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class BidMarketDataTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'variables.flBase' => 'https://www.freelancer.com',
            'variables.flKey' => 'k',
            'variables.ingestToken' => 'tok',
        ]);
    }

    private function fakeStats(array $projects, array $bids = []): void
    {
        Http::fake([
            // Order matters: the bids route is the more specific pattern.
            '*projects/0.1/bids*' => Http::response(['result' => ['bids' => $bids]], 200),
            '*projects/0.1/projects*' => Http::response(['result' => ['projects' => $projects]], 200),
        ]);
    }

    public function test_stats_service_reads_bid_count(): void
    {
        $this->fakeStats([
            ['id' => 40652640, 'bid_stats' => ['bid_count' => 234]],
            ['id' => 40597174, 'bid_stats' => ['bid_count' => 102]],
        ]);

        $this->assertSame(
            [40652640 => 234, 40597174 => 102],
            app(FreelancerProjectStats::class)->bidCounts([40652640, 40597174]),
        );
    }

    public function test_stats_service_skips_projects_without_stats(): void
    {
        $this->fakeStats([
            ['id' => 1, 'bid_stats' => null],
            ['id' => 2, 'bid_stats' => ['bid_count' => 7]],
        ]);

        $this->assertSame([2 => 7], app(FreelancerProjectStats::class)->bidCounts([1, 2]));
    }

    public function test_stats_service_survives_a_failed_call(): void
    {
        Http::fake(['*projects/0.1/projects*' => Http::response([], 500)]);

        $this->assertSame([], app(FreelancerProjectStats::class)->bidCounts([1]));
    }

    public function test_job_stores_the_total(): void
    {
        $this->fakeStats([['id' => 40652640, 'bid_stats' => ['bid_count' => 234]]]);
        BidInsight::create(['project_id' => 40652640, 'bid_rank' => 3, 'last_scraped_at' => now()]);

        (new RefreshBidMarketDataJob([40652640]))->handle(app(FreelancerProjectStats::class));

        $this->assertSame(234, (int) BidInsight::sole()->total_bids);
    }

    /**
     * The field only grows while a project is open, so a partial read must not
     * walk the denominator backwards.
     */
    public function test_job_never_lowers_an_existing_total(): void
    {
        $this->fakeStats([['id' => 40652640, 'bid_stats' => ['bid_count' => 100]]]);
        BidInsight::create([
            'project_id' => 40652640, 'bid_rank' => 3, 'total_bids' => 234, 'last_scraped_at' => now(),
        ]);

        (new RefreshBidMarketDataJob([40652640]))->handle(app(FreelancerProjectStats::class));

        $this->assertSame(234, (int) BidInsight::sole()->total_bids);
    }

    public function test_ingest_queues_a_totals_refresh(): void
    {
        Queue::fake();

        $this->postJson('/api/insights/bids/ingest', [
            'bids' => [
                ['id' => 1, 'project_id' => 40652640, 'rank' => 3],
                ['id' => 2, 'project_id' => 40597174, 'rank' => 9],
            ],
        ], ['X-Ingest-Token' => 'tok'])->assertOk();

        Queue::assertPushed(RefreshBidMarketDataJob::class);
    }

    public function test_backfill_command_fills_ranks_missing_a_total(): void
    {
        $this->fakeStats([['id' => 40652640, 'bid_stats' => ['bid_count' => 234]]]);
        BidInsight::create(['project_id' => 40652640, 'bid_rank' => 3, 'last_scraped_at' => now()]);
        // No rank, so no denominator is needed.
        BidInsight::create(['project_id' => 999, 'last_scraped_at' => now()]);

        $this->artisan('bids:market-data --limit=50')->assertExitCode(0);

        $this->assertSame(234, (int) BidInsight::where('project_id', 40652640)->sole()->total_bids);
        $this->assertNull(BidInsight::where('project_id', 999)->sole()->total_bids);
    }

    public function test_backfill_dry_run_writes_nothing(): void
    {
        $this->fakeStats([['id' => 40652640, 'bid_stats' => ['bid_count' => 234]]]);
        BidInsight::create(['project_id' => 40652640, 'bid_rank' => 3, 'last_scraped_at' => now()]);

        $this->artisan('bids:market-data --dry-run')->assertExitCode(0);

        $this->assertNull(BidInsight::sole()->total_bids);
    }

    // ---- winning bid -------------------------------------------------------

    public function test_winning_bid_is_read_from_the_awarded_bid(): void
    {
        $this->fakeStats([], [[
            'project_id' => 40594715, 'award_status' => 'awarded',
            'amount' => 60, 'sealed' => false, 'description' => 'I can do this.',
        ]]);

        $out = app(FreelancerProjectStats::class)->winningBids([40594715]);

        $this->assertSame(60.0, $out[40594715]['amount']);
        $this->assertFalse($out[40594715]['sealed']);
        $this->assertSame('I can do this.', $out[40594715]['text']);
    }

    /**
     * A sealed project hides the figure; recording the zero the API sends would
     * read as "won for nothing".
     */
    public function test_sealed_winner_keeps_the_amount_null(): void
    {
        $this->fakeStats([], [[
            'project_id' => 1, 'award_status' => 'awarded', 'amount' => 0, 'sealed' => true,
        ]]);

        $out = app(FreelancerProjectStats::class)->winningBids([1]);

        $this->assertNull($out[1]['amount']);
        $this->assertTrue($out[1]['sealed']);
    }

    public function test_non_awarded_bids_are_ignored(): void
    {
        $this->fakeStats([], [
            ['project_id' => 1, 'award_status' => null, 'amount' => 10],
            ['project_id' => 1, 'award_status' => 'rejected', 'amount' => 20],
        ]);

        $this->assertSame([], app(FreelancerProjectStats::class)->winningBids([1]));
    }

    public function test_job_stores_the_winning_bid(): void
    {
        $this->fakeStats(
            [['id' => 40594715, 'bid_stats' => ['bid_count' => 61]]],
            [['project_id' => 40594715, 'award_status' => 'awarded', 'amount' => 60, 'sealed' => false]],
        );
        BidInsight::create(['project_id' => 40594715, 'bid_rank' => 3, 'last_scraped_at' => now()]);

        (new RefreshBidMarketDataJob([40594715]))->handle(app(FreelancerProjectStats::class));

        $insight = BidInsight::sole();
        $this->assertSame(61, (int) $insight->total_bids);
        $this->assertEquals(60.0, (float) $insight->winning_bid_amount);
        $this->assertFalse((bool) $insight->winning_bid_sealed);
    }

    public function test_backfill_fills_winning_bids_too(): void
    {
        $this->fakeStats(
            [['id' => 40594715, 'bid_stats' => ['bid_count' => 61]]],
            [['project_id' => 40594715, 'award_status' => 'awarded', 'amount' => 60, 'sealed' => false]],
        );
        BidInsight::create(['project_id' => 40594715, 'bid_rank' => 3, 'last_scraped_at' => now()]);

        $this->artisan('bids:market-data --limit=50')->assertExitCode(0);

        $this->assertEquals(60.0, (float) BidInsight::sole()->winning_bid_amount);
    }

    // ---- display -----------------------------------------------------------

    public function test_bid_insights_page_shows_rank_out_of_total(): void
    {
        BidInsight::create([
            'project_id' => 40652640, 'bid_rank' => 3, 'total_bids' => 234, 'last_scraped_at' => now(),
        ]);

        $this->actingAs(User::factory()->create())->get('/insights/bids')
            ->assertOk()
            ->assertSee('#3')
            ->assertSee('of 234');
    }

    public function test_rank_renders_alone_when_the_total_is_unknown(): void
    {
        BidInsight::create([
            'project_id' => 40652641, 'bid_rank' => 5, 'last_scraped_at' => now(),
        ]);

        $res = $this->actingAs(User::factory()->create())->get('/insights/bids')->assertOk();
        $res->assertSee('#5');
        $res->assertDontSee('of 0');
    }
}
