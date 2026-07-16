<?php

namespace Tests\Feature;

use App\Models\Proposal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReviewFeedbackTest extends TestCase
{
    use RefreshDatabase;

    private function makeProposal(): Proposal
    {
        $proposal = new Proposal();
        $proposal->project_id = 555;
        $proposal->title = 'Labelling target';
        $proposal->description = 'desc';
        $proposal->save();

        return $proposal;
    }

    public function test_requires_auth(): void
    {
        $this->postJson('/review/feedback', ['proposal_id' => 1, 'label' => 'relevant'])
            ->assertUnauthorized();
    }

    public function test_valid_label_persists(): void
    {
        $proposal = $this->makeProposal();

        $this->actingAs(User::factory()->create())
            ->postJson('/review/feedback', ['proposal_id' => $proposal->id, 'label' => 'not_relevant_skill'])
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->assertSame('not_relevant_skill', $proposal->fresh()->review_label);
    }

    public function test_invalid_label_rejected(): void
    {
        $proposal = $this->makeProposal();

        $this->actingAs(User::factory()->create())
            ->postJson('/review/feedback', ['proposal_id' => $proposal->id, 'label' => 'maybe'])
            ->assertStatus(422);

        $this->assertNull($proposal->fresh()->review_label);
    }

    public function test_unknown_proposal_rejected(): void
    {
        $this->actingAs(User::factory()->create())
            ->postJson('/review/feedback', ['proposal_id' => 999999, 'label' => 'relevant'])
            ->assertStatus(422);
    }
}
