<?php

namespace Tests\Feature;

use App\Models\Bid;
use App\Models\Proposal;
use App\Services\BidAwardChecker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BidAwardCheckerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['variables.flUserId' => '999', 'variables.flKey' => 'test-key']);
    }

    private function fakeBidsResponse(array $bids): void
    {
        Http::fake([
            '*bids*' => Http::response(['status' => 'success', 'result' => ['bids' => $bids]], 200),
        ]);
    }

    public function test_marks_awarded_and_stores_amount(): void
    {
        $this->fakeBidsResponse([
            ['project_id' => 555, 'award_status' => 'awarded', 'amount' => 320],
        ]);
        $p = Proposal::factory()->create(['project_id' => 555]);
        $bid = Bid::factory()->create(['proposal_id' => $p->id, 'bid_status' => 'completed', 'awarded' => false, 'price' => 100]);

        (new BidAwardChecker())->run();

        $bid->refresh();
        $this->assertTrue($bid->awarded);
        $this->assertEquals(320, $bid->awarded_price);
    }

    public function test_falls_back_to_bid_price_when_amount_missing(): void
    {
        $this->fakeBidsResponse([
            ['project_id' => 555, 'award_status' => 'awarded'], // no amount
        ]);
        $p = Proposal::factory()->create(['project_id' => 555]);
        $bid = Bid::factory()->create(['proposal_id' => $p->id, 'bid_status' => 'completed', 'awarded' => false, 'price' => 100]);

        (new BidAwardChecker())->run();

        $bid->refresh();
        $this->assertTrue($bid->awarded);
        $this->assertEquals(100, $bid->awarded_price);
    }

    public function test_pending_award_leaves_bid_unawarded(): void
    {
        $this->fakeBidsResponse([
            ['project_id' => 555, 'award_status' => 'pending', 'amount' => 320],
        ]);
        $p = Proposal::factory()->create(['project_id' => 555]);
        $bid = Bid::factory()->create(['proposal_id' => $p->id, 'bid_status' => 'completed', 'awarded' => false]);

        (new BidAwardChecker())->run();

        $bid->refresh();
        $this->assertFalse($bid->awarded);
        $this->assertNull($bid->awarded_price);
    }

    public function test_only_completed_unawarded_bids_are_polled(): void
    {
        // API says project 777 is awarded, but our bid there is 'failed' -> must not change
        $this->fakeBidsResponse([
            ['project_id' => 777, 'award_status' => 'awarded', 'amount' => 500],
        ]);
        $p = Proposal::factory()->create(['project_id' => 777]);
        $bid = Bid::factory()->create(['proposal_id' => $p->id, 'bid_status' => 'failed', 'awarded' => false]);

        (new BidAwardChecker())->run();

        $bid->refresh();
        $this->assertFalse($bid->awarded);
    }
}
