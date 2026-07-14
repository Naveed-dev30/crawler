<?php

namespace Tests\Feature;

use App\Models\Bid;
use App\Models\Proposal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RelevanceColumnTest extends TestCase
{
    use RefreshDatabase;

    private function makeBid(?string $feedback = null): Bid
    {
        $proposal = new Proposal();
        $proposal->project_id = 111;
        $proposal->title = 'Test Project';
        $proposal->description = 'Test project description';
        $proposal->save();

        $bid = new Bid();
        $bid->proposal_id = $proposal->id;
        $bid->bid_status = 'pending';
        $bid->price = 100;
        $bid->cover_letter = 'Test cover letter';
        $bid->admin_feedback = $feedback;
        $bid->save();

        return $bid;
    }

    public function test_needs_feedback_scope_returns_only_null_feedback(): void
    {
        $this->makeBid(null);
        $this->makeBid('relevant');
        $this->makeBid('scam');

        $this->assertSame(1, Bid::needsFeedback()->count());
    }
}
