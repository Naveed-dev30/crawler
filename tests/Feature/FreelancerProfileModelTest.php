<?php

namespace Tests\Feature;

use App\Models\Bid;
use App\Models\FreelancerProfile;
use App\Models\Proposal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FreelancerProfileModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_stores_freelancer_id_verbatim(): void
    {
        $p = FreelancerProfile::create(['id' => 987654, 'title' => 'Web Development']);

        $this->assertSame(987654, FreelancerProfile::first()->id);
        $this->assertSame('Web Development', $p->title);
    }

    public function test_bid_has_nullable_profile_id(): void
    {
        $proposal = Proposal::factory()->create();
        $bid = Bid::factory()->create(['proposal_id' => $proposal->id, 'profile_id' => 987654]);

        $this->assertSame(987654, (int) $bid->fresh()->profile_id);

        $bid2 = Bid::factory()->create(['proposal_id' => $proposal->id]);
        $this->assertNull($bid2->fresh()->profile_id);
    }
}
