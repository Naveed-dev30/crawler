<?php

namespace Tests\Feature;

use App\Models\Bid;
use App\Models\Proposal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StatisticsStatusTest extends TestCase
{
    use RefreshDatabase;

    public function test_status_breakdown_uses_tab_categories(): void
    {
        $p = Proposal::factory()->create(['min_budget' => 100, 'exchange_rate' => 1, 'type' => 'fixed']);

        Bid::factory()->create(['proposal_id' => $p->id, 'bid_status' => 'completed']);
        Bid::factory()->create(['proposal_id' => $p->id, 'bid_status' => 'pending']);
        Bid::factory()->create(['proposal_id' => $p->id, 'bid_status' => 'expired', 'error_message' => '']);
        Bid::factory()->create(['proposal_id' => $p->id, 'bid_status' => 'failed', 'error_message' => 'Skill not matched']);

        $res = $this->actingAs(User::factory()->create())->getJson('/stats/status')->assertOk();
        $rows = collect($res->json())->keyBy('status');

        $this->assertSame(2, $rows['Bids Placed']['count']);
        $this->assertSame(1, $rows['Failed']['count']);
        $this->assertSame(1, $rows['Skills Not Matched']['count']);
        $this->assertArrayHasKey('Not Qualified', $rows->all());
        $this->assertEquals(200, $rows['Bids Placed']['amount_usd']);
    }
}
