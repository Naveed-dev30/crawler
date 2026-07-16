<?php

namespace Tests\Feature;

use App\Models\Bid;
use App\Models\Proposal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BidsDetailTest extends TestCase
{
    use RefreshDatabase;

    public function test_requires_auth(): void
    {
        $bid = Bid::factory()->create();
        $this->get('/bids/' . $bid->id . '/detail')->assertRedirect('/login');
    }

    public function test_detail_returns_panel_and_marks_seen(): void
    {
        $proposal = Proposal::factory()->create(['project_id' => 4242, 'title' => 'Seeded project']);
        $bid = Bid::factory()->create(['proposal_id' => $proposal->id, 'is_seen' => false, 'bid_status' => 'pending']);

        $res = $this->actingAs(User::factory()->create())->get('/bids/' . $bid->id . '/detail')->assertOk();

        $res->assertSee('View on Freelancer');
        $res->assertSee('4242'); // freelancer project link uses project_id
        $res->assertSee('Correct');
        $res->assertSee('Incorrect');

        $this->assertTrue((bool) Bid::find($bid->id)->is_seen);
    }

    public function test_unknown_bid_returns_404(): void
    {
        $this->actingAs(User::factory()->create())->get('/bids/999999/detail')->assertNotFound();
    }
}
