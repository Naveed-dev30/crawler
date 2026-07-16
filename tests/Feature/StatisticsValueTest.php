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
}
