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

    public function test_last24h_totals_and_skills(): void
    {
        // Recent + awarded (completed): 100 * 1 = 100 USD, skills php/laravel
        $awarded = Proposal::factory()->create([
            'type' => 'fixed', 'min_budget' => 100, 'exchange_rate' => 1,
            'skills' => ['php', 'laravel'], 'created_at' => Carbon::now()->subHours(2),
        ]);
        Bid::factory()->create(['proposal_id' => $awarded->id, 'bid_status' => 'completed']);

        // Recent + not awarded (pending): 200 USD, posted only
        $pending = Proposal::factory()->create([
            'type' => 'fixed', 'min_budget' => 200, 'exchange_rate' => 1,
            'skills' => ['react'], 'created_at' => Carbon::now()->subHours(3),
        ]);
        Bid::factory()->create(['proposal_id' => $pending->id, 'bid_status' => 'pending']);

        // Old proposal (>24h): excluded entirely
        Proposal::factory()->create(['min_budget' => 999, 'created_at' => Carbon::now()->subDays(3)]);

        $res = $this->actingAs(User::factory()->create())
            ->getJson('/stats/last24h')
            ->assertOk();

        $this->assertEquals(300, $res->json('value_posted_usd')); // 100 + 200
        $this->assertEquals(100, $res->json('value_awarded_usd')); // awarded only
        $skills = collect($res->json('skills'));
        $this->assertEquals(1, $skills->firstWhere('name', 'php')['count']);
        $this->assertEquals(1, $skills->firstWhere('name', 'laravel')['count']);
        $this->assertNull($skills->firstWhere('name', 'react')); // not awarded
    }
}
