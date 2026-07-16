<?php

namespace Tests\Feature;

use App\Models\Bid;
use App\Models\Proposal;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StatisticsLast24hTest extends TestCase
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

    public function test_awarded_value_uses_awarded_price_and_exchange_rate(): void
    {
        // Awarded: posted = 100*1 = 100; awarded = awarded_price(250)*1 = 250
        $awarded = Proposal::factory()->create([
            'type' => 'fixed', 'min_budget' => 100, 'exchange_rate' => 1,
            'skills' => ['php', 'laravel'], 'created_at' => Carbon::now()->subHours(2),
        ]);
        Bid::factory()->create(['proposal_id' => $awarded->id, 'bid_status' => 'completed', 'awarded' => true, 'awarded_price' => 250, 'price' => 90]);

        // Placed but not awarded: posted 200, not awarded
        $placed = Proposal::factory()->create([
            'type' => 'fixed', 'min_budget' => 200, 'exchange_rate' => 1,
            'skills' => ['react'], 'created_at' => Carbon::now()->subHours(3),
        ]);
        Bid::factory()->create(['proposal_id' => $placed->id, 'bid_status' => 'completed', 'awarded' => false]);

        // Old proposal (>24h): excluded
        Proposal::factory()->create(['min_budget' => 999, 'created_at' => Carbon::now()->subDays(3)]);

        $res = $this->actingAs(User::factory()->create())->getJson('/stats/last24h')->assertOk();

        $this->assertEquals(300, $res->json('value_posted_usd'));  // 100 + 200
        $this->assertEquals(250, $res->json('value_awarded_usd')); // awarded_price * exchange_rate
        $skills = collect($res->json('skills'));
        $this->assertEquals(1, $skills->firstWhere('name', 'php')['count']);
        $this->assertNull($skills->firstWhere('name', 'react')); // not awarded
    }

    public function test_awarded_value_falls_back_to_bid_price(): void
    {
        // awarded_price null -> use bid price 50; exchange_rate 2 -> 100
        $p = Proposal::factory()->create([
            'type' => 'fixed', 'min_budget' => 10, 'exchange_rate' => 2,
            'skills' => [], 'created_at' => Carbon::now()->subHours(1),
        ]);
        Bid::factory()->create(['proposal_id' => $p->id, 'bid_status' => 'completed', 'awarded' => true, 'awarded_price' => null, 'price' => 50]);

        $res = $this->actingAs(User::factory()->create())->getJson('/stats/last24h')->assertOk();

        $this->assertEquals(100, $res->json('value_awarded_usd')); // 50 * 2
    }
}
