<?php

namespace Tests\Feature;

use App\Models\Bid;
use App\Models\Proposal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BidDetailQualificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_detail_shows_reason_and_no_summary_placeholder(): void
    {
        $proposal = Proposal::factory()->create(['qualified' => true, 'qualify_reason' => 'safe web project', 'qualify_summary' => null]);
        $bid = Bid::factory()->create(['proposal_id' => $proposal->id]);

        $this->actingAs(User::factory()->create())
            ->get("/bids/{$bid->id}/detail")->assertOk()
            ->assertSee('safe web project')
            ->assertSee('No summary available');
    }

    public function test_detail_hides_block_when_no_reason(): void
    {
        $proposal = Proposal::factory()->create(['qualified' => null, 'qualify_reason' => null]);
        $bid = Bid::factory()->create(['proposal_id' => $proposal->id]);

        $this->actingAs(User::factory()->create())
            ->get("/bids/{$bid->id}/detail")->assertOk()
            ->assertDontSee('Qualification');
    }
}
