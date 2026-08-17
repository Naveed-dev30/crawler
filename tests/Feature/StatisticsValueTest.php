<?php

namespace Tests\Feature;

use App\Models\Bid;
use App\Models\Proposal;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StatisticsValueTest extends TestCase
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

    public function test_value_endpoint_computes_usd_with_hourly_multiplier(): void
    {
        // fixed: 100 * 2 = 200 USD, completed -> placed
        $fixed = Proposal::factory()->create(['type' => 'fixed', 'min_budget' => 100, 'exchange_rate' => 2]);
        Bid::factory()->create(['proposal_id' => $fixed->id, 'bid_status' => 'completed', 'created_at' => '2026-07-10 10:00:00']);

        // hourly: 50 * 1 * 10 = 500 USD, failed -> failed
        $hourly = Proposal::factory()->create(['type' => 'hourly', 'min_budget' => 50, 'exchange_rate' => 1]);
        Bid::factory()->create(['proposal_id' => $hourly->id, 'bid_status' => 'failed', 'created_at' => '2026-07-10 11:00:00']);

        $res = $this->actingAs(User::factory()->create())
            ->getJson('/stats/value?granularity=daily&from=2026-07-10&to=2026-07-10')
            ->assertOk();

        $day = collect($res->json())->firstWhere('bucket', '2026-07-10');
        $this->assertEquals(200, $day['placed_usd']);
        $this->assertEquals(500, $day['failed_usd']);
    }

    /** The chart mirrors the Bids by Status donut, so all four must be priced. */
    public function test_value_endpoint_splits_the_four_dashboard_categories(): void
    {
        // placed: fixed 100 * 1 = 100
        $placed = Proposal::factory()->create(['type' => 'fixed', 'min_budget' => 100, 'exchange_rate' => 1]);
        Bid::factory()->create(['proposal_id' => $placed->id, 'bid_status' => 'completed', 'created_at' => '2026-07-10 10:00:00']);

        // failed (non-skill): fixed 200
        $failed = Proposal::factory()->create(['type' => 'fixed', 'min_budget' => 200, 'exchange_rate' => 1]);
        Bid::factory()->create(['proposal_id' => $failed->id, 'bid_status' => 'failed', 'error_message' => 'boom', 'created_at' => '2026-07-10 10:00:00']);

        // skills not matched: fixed 300
        $skills = Proposal::factory()->create(['type' => 'fixed', 'min_budget' => 300, 'exchange_rate' => 1]);
        Bid::factory()->create(['proposal_id' => $skills->id, 'bid_status' => 'failed', 'error_message' => 'Skill not matched for this project', 'created_at' => '2026-07-10 10:00:00']);

        // not qualified: no bid at all, bucketed by the project's own date — hourly 40 * 10 = 400
        Proposal::factory()->create([
            'type' => 'hourly', 'min_budget' => 40, 'exchange_rate' => 1,
            'qualified' => false, 'created_at' => '2026-07-10 09:00:00',
        ]);

        $day = collect(
            $this->actingAs(User::factory()->create())
                ->getJson('/stats/value?granularity=daily&from=2026-07-10&to=2026-07-10')
                ->assertOk()->json()
        )->firstWhere('bucket', '2026-07-10');

        $this->assertEquals(100, $day['placed_usd']);
        $this->assertEquals(200, $day['failed_usd']);
        $this->assertEquals(300, $day['skills_usd']);
        $this->assertEquals(400, $day['nq_usd']);
    }

    public function test_empty_buckets_carry_all_four_series(): void
    {
        $day = collect(
            $this->actingAs(User::factory()->create())
                ->getJson('/stats/value?granularity=daily&from=2026-07-10&to=2026-07-10')
                ->assertOk()->json()
        )->firstWhere('bucket', '2026-07-10');

        $this->assertSame(['bucket', 'placed_usd', 'failed_usd', 'skills_usd', 'nq_usd'], array_keys($day));
    }
}
