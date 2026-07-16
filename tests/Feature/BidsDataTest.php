<?php

namespace Tests\Feature;

use App\Models\Bid;
use App\Models\Proposal;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BidsDataTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-07-15 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function seedBids(): void
    {
        $p1 = Proposal::factory()->create(['type' => 'fixed', 'title' => 'Laravel API build', 'project_id' => 1234, 'country' => 'India']);
        $p2 = Proposal::factory()->create(['type' => 'hourly', 'title' => 'React app', 'project_id' => 5678, 'country' => 'USA']);

        Bid::factory()->create(['proposal_id' => $p1->id, 'bid_status' => 'pending',   'price' => 100, 'created_at' => '2026-07-10 09:00:00']);
        Bid::factory()->create(['proposal_id' => $p1->id, 'bid_status' => 'completed', 'price' => 500, 'created_at' => '2026-07-11 09:00:00']);
        Bid::factory()->create(['proposal_id' => $p2->id, 'bid_status' => 'failed',    'price' => 200, 'created_at' => '2026-07-12 09:00:00']);
        Bid::factory()->create(['proposal_id' => $p2->id, 'bid_status' => 'expired',   'price' => 300, 'created_at' => '2026-07-13 09:00:00']);
    }

    public function test_requires_auth(): void
    {
        $this->getJson('/bids/data')->assertUnauthorized();
    }

    public function test_cards_and_default_placed_tab(): void
    {
        $this->seedBids();

        $res = $this->actingAs(User::factory()->create())->getJson('/bids/data')->assertOk();

        $this->assertEquals(4, $res->json('cards.total'));
        $this->assertEquals(2, $res->json('cards.placed'));
        $this->assertEquals(2, $res->json('cards.failed'));
        // default tab = placed → rows contain the pending+completed bids' projects, not the failed ones
        $this->assertStringContainsString('1234', $res->json('rowsHtml'));
        $this->assertStringContainsString('pending', $res->json('rowsHtml'));
        $this->assertStringNotContainsString('expired', $res->json('rowsHtml'));
    }

    public function test_failed_tab(): void
    {
        $this->seedBids();

        $res = $this->actingAs(User::factory()->create())->getJson('/bids/data?tab=failed')->assertOk();

        $this->assertStringContainsString('failed', $res->json('rowsHtml'));
        $this->assertStringContainsString('expired', $res->json('rowsHtml'));
        $this->assertStringNotContainsString('pending', $res->json('rowsHtml'));
    }

    public function test_type_filter(): void
    {
        $this->seedBids();

        $res = $this->actingAs(User::factory()->create())->getJson('/bids/data?type=hourly')->assertOk();

        $this->assertEquals(2, $res->json('cards.total'));   // both p2 bids
        $this->assertEquals(0, $res->json('cards.placed'));
        $this->assertEquals(2, $res->json('cards.failed'));
    }

    public function test_min_price_filter(): void
    {
        $this->seedBids();

        $res = $this->actingAs(User::factory()->create())->getJson('/bids/data?min=250')->assertOk();

        // prices >= 250 : completed(500) + expired(300)
        $this->assertEquals(2, $res->json('cards.total'));
        $this->assertEquals(1, $res->json('cards.placed'));
        $this->assertEquals(1, $res->json('cards.failed'));
    }

    public function test_search_by_title_and_project_id(): void
    {
        $this->seedBids();
        $user = User::factory()->create();

        $byTitle = $this->actingAs($user)->getJson('/bids/data?q=Laravel')->assertOk();
        $this->assertEquals(2, $byTitle->json('cards.total')); // both p1 bids

        $byId = $this->actingAs($user)->getJson('/bids/data?q=5678')->assertOk();
        $this->assertEquals(2, $byId->json('cards.total')); // both p2 bids
    }
}
