<?php

namespace Tests\Feature;

use App\Models\Bid;
use App\Models\Proposal;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StatisticsBidsTest extends TestCase
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
        $fixed = Proposal::factory()->create(['type' => 'fixed']);
        $hourly = Proposal::factory()->create(['type' => 'hourly']);

        // awarded (completed + awarded)
        Bid::factory()->create(['proposal_id' => $fixed->id, 'bid_status' => 'completed', 'awarded' => true, 'created_at' => '2026-07-10 09:00:00']);
        // placed (completed, not awarded)
        Bid::factory()->create(['proposal_id' => $fixed->id, 'bid_status' => 'completed', 'awarded' => false, 'created_at' => '2026-07-10 10:00:00']);
        // failed + expired
        Bid::factory()->create(['proposal_id' => $fixed->id, 'bid_status' => 'failed', 'created_at' => '2026-07-10 11:00:00']);
        Bid::factory()->create(['proposal_id' => $fixed->id, 'bid_status' => 'expired', 'created_at' => '2026-07-10 12:00:00']);
        // pending -> not shown
        Bid::factory()->create(['proposal_id' => $fixed->id, 'bid_status' => 'pending', 'created_at' => '2026-07-10 08:00:00']);
        // hourly awarded
        Bid::factory()->create(['proposal_id' => $hourly->id, 'bid_status' => 'completed', 'awarded' => true, 'created_at' => '2026-07-10 09:30:00']);
    }

    public function test_requires_auth(): void
    {
        $this->getJson('/stats/bids')->assertUnauthorized();
    }

    public function test_fixed_type_awarded_placed_failed_daily(): void
    {
        $this->seedBids();

        $res = $this->actingAs(User::factory()->create())
            ->getJson('/stats/bids?type=fixed&granularity=daily&from=2026-07-10&to=2026-07-10')
            ->assertOk();

        $day = collect($res->json())->firstWhere('bucket', '2026-07-10');
        $this->assertEquals(1, $day['awarded']); // completed+awarded
        $this->assertEquals(1, $day['placed']);  // completed, not awarded
        $this->assertEquals(2, $day['failed']);  // failed + expired
    }

    public function test_type_all_includes_hourly_awarded(): void
    {
        $this->seedBids();

        $res = $this->actingAs(User::factory()->create())
            ->getJson('/stats/bids?type=all&granularity=daily&from=2026-07-10&to=2026-07-10')
            ->assertOk();

        $day = collect($res->json())->firstWhere('bucket', '2026-07-10');
        $this->assertEquals(2, $day['awarded']); // fixed awarded + hourly awarded
    }

    public function test_zero_filled_buckets_present(): void
    {
        $this->seedBids();

        $res = $this->actingAs(User::factory()->create())
            ->getJson('/stats/bids?type=fixed&granularity=daily&from=2026-07-10&to=2026-07-12')
            ->assertOk();

        $this->assertCount(3, $res->json());
        $empty = collect($res->json())->firstWhere('bucket', '2026-07-11');
        $this->assertEquals(0, $empty['awarded']);
    }
}
