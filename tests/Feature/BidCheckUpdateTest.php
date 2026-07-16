<?php

namespace Tests\Feature;

use App\Models\Bid;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BidCheckUpdateTest extends TestCase
{
    use RefreshDatabase;

    public function test_requires_auth(): void
    {
        $bid = Bid::factory()->create();
        $this->postJson('/updateBidCheck', ['bid_id' => $bid->id, 'check' => 'Correct'])
            ->assertUnauthorized();
    }

    public function test_updates_check_and_returns_json(): void
    {
        $bid = Bid::factory()->create(['check' => 'Unreviewed']);

        $this->actingAs(User::factory()->create())
            ->postJson('/updateBidCheck', ['bid_id' => $bid->id, 'check' => 'Correct'])
            ->assertOk()
            ->assertJson(['success' => true, 'check' => 'Correct']);

        $this->assertEquals('Correct', Bid::find($bid->id)->check);
    }

    public function test_missing_bid_returns_404(): void
    {
        $this->actingAs(User::factory()->create())
            ->postJson('/updateBidCheck', ['bid_id' => 999999, 'check' => 'Correct'])
            ->assertNotFound();
    }
}
