<?php

namespace Tests\Feature;

use App\Models\Proposal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReviewColumnTest extends TestCase
{
    use RefreshDatabase;

    private function makeProposal(?string $label, int $projectId): Proposal
    {
        $proposal = new Proposal();
        $proposal->project_id = $projectId;
        $proposal->title = 'Test Project';
        $proposal->description = 'Test project description';
        $proposal->review_label = $label;
        $proposal->save();

        return $proposal;
    }

    public function test_needs_review_scope_returns_only_null_label(): void
    {
        $this->makeProposal(null, 101);
        $this->makeProposal('relevant', 102);
        $this->makeProposal('scam', 103);

        $this->assertSame(1, Proposal::needsReview()->count());
    }
}
