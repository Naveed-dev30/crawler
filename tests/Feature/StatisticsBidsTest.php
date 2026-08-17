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

    /** The All preset anchors to the oldest row we hold, not to a magic date. */
    public function test_all_time_covers_rows_older_than_the_default_window(): void
    {
        $old = Proposal::factory()->create(['type' => 'fixed', 'created_at' => '2026-01-05 10:00:00']);
        Bid::factory()->create(['proposal_id' => $old->id, 'bid_status' => 'completed', 'created_at' => '2026-01-05 10:00:00']);

        $rows = $this->actingAs(User::factory()->create())
            ->getJson('/stats/bids?type=all&granularity=monthly&all=1')
            ->assertOk()->json();

        $this->assertSame('2026-01', $rows[0]['bucket']);
        $this->assertSame(1, $rows[0]['placed']);
    }

    /** The card carries the same five outcomes the donut and value chart do. */
    public function test_skill_failures_and_not_qualified_get_their_own_series(): void
    {
        $fixed = Proposal::factory()->create(['type' => 'fixed']);
        Bid::factory()->create(['proposal_id' => $fixed->id, 'bid_status' => 'failed', 'error_message' => 'boom', 'created_at' => '2026-07-10 10:00:00']);
        Bid::factory()->create(['proposal_id' => $fixed->id, 'bid_status' => 'failed', 'error_message' => 'Skill not matched for this project', 'created_at' => '2026-07-10 11:00:00']);
        // No bid at all — bucketed by the project's own date.
        Proposal::factory()->create(['type' => 'fixed', 'qualified' => false, 'created_at' => '2026-07-10 09:00:00']);
        // Different type: excluded from the fixed chart.
        Proposal::factory()->create(['type' => 'hourly', 'qualified' => false, 'created_at' => '2026-07-10 09:00:00']);

        $day = collect(
            $this->actingAs(User::factory()->create())
                ->getJson('/stats/bids?type=fixed&granularity=daily&from=2026-07-10&to=2026-07-10')
                ->assertOk()->json()
        )->firstWhere('bucket', '2026-07-10');

        $this->assertEquals(1, $day['failed']);   // skill failure no longer counted here
        $this->assertEquals(1, $day['skills']);
        $this->assertEquals(1, $day['nq']);

        $all = collect(
            $this->actingAs(User::factory()->create())
                ->getJson('/stats/bids?type=all&granularity=daily&from=2026-07-10&to=2026-07-10')
                ->assertOk()->json()
        )->firstWhere('bucket', '2026-07-10');

        $this->assertEquals(2, $all['nq']);       // both types
    }

    public function test_empty_buckets_carry_every_series(): void
    {
        $day = collect(
            $this->actingAs(User::factory()->create())
                ->getJson('/stats/bids?granularity=daily&from=2026-07-10&to=2026-07-10')
                ->assertOk()->json()
        )->firstWhere('bucket', '2026-07-10');

        $this->assertSame(['bucket', 'awarded', 'placed', 'failed', 'skills', 'nq'], array_keys($day));
    }
}
