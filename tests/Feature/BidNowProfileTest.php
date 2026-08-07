<?php

namespace Tests\Feature;

use App\Jobs\BidNowJob;
use App\Models\Bid;
use App\Models\Proposal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BidNowProfileTest extends TestCase
{
    use RefreshDatabase;

    private function fakeBidOk(): void
    {
        Http::fake(['*/api/projects/0.1/bids/*' => Http::response(['result' => ['id' => 1]], 200)]);
    }

    public function test_payload_includes_profile_id_when_set(): void
    {
        $this->fakeBidOk();
        $proposal = Proposal::factory()->create();
        $bid = Bid::factory()->create(['proposal_id' => $proposal->id, 'profile_id' => 101, 'price' => 100]);

        (new BidNowJob($bid))->handle();

        Http::assertSent(fn ($request) => ($request->data()['profile_id'] ?? null) === 101);
    }

    public function test_payload_omits_profile_id_when_null(): void
    {
        $this->fakeBidOk();
        $proposal = Proposal::factory()->create();
        $bid = Bid::factory()->create(['proposal_id' => $proposal->id, 'profile_id' => null, 'price' => 100]);

        (new BidNowJob($bid))->handle();

        Http::assertSent(fn ($request) => ! array_key_exists('profile_id', $request->data()));
    }

    public function test_successful_post_stamps_posted_and_last_action(): void
    {
        $this->fakeBidOk();
        $proposal = Proposal::factory()->create();
        $bid = Bid::factory()->create(['proposal_id' => $proposal->id, 'profile_id' => null, 'price' => 100]);

        (new BidNowJob($bid))->handle();

        $fresh = $bid->fresh();
        $this->assertSame('completed', $fresh->bid_status);
        $this->assertNotNull($fresh->posted_at);       // Last Bid
        $this->assertNotNull($fresh->last_action_at);   // Last Action
    }

    public function test_failed_post_stamps_last_action_but_not_posted(): void
    {
        Http::fake(['*/api/projects/0.1/bids/*' => Http::response(['message' => 'nope'], 400)]);
        $proposal = Proposal::factory()->create(['min_budget' => null]);
        $bid = Bid::factory()->create(['proposal_id' => $proposal->id, 'profile_id' => null, 'price' => 100]);

        (new BidNowJob($bid))->handle();

        $fresh = $bid->fresh();
        $this->assertSame('Failed', $fresh->bid_status);
        $this->assertNull($fresh->posted_at);           // never posted
        $this->assertNotNull($fresh->last_action_at);   // but the attempt is timed
    }
}
