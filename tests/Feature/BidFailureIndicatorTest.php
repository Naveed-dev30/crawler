<?php

namespace Tests\Feature;

use App\Models\Bid;
use App\Models\Proposal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BidFailureIndicatorTest extends TestCase
{
    use RefreshDatabase;

    private function failedBid(string $errorMessage): void
    {
        $proposal = Proposal::factory()->create();
        Bid::factory()->create([
            'proposal_id' => $proposal->id,
            'bid_status' => 'failed',
            'error_message' => $errorMessage,
        ]);
    }

    public function test_skill_failure_shows_skill_not_matched(): void
    {
        $this->failedBid('You must have the required skills to bid on this project.');

        $rows = $this->actingAs(User::factory()->create())
            ->getJson('/bids/data?tab=failed')->assertOk()
            ->json('rowsHtml');

        $this->assertStringContainsString('Skill not matched', $rows);
        $this->assertStringNotContainsString('>Other<', $rows);
    }

    public function test_other_failure_shows_other(): void
    {
        $this->failedBid('Project is no longer available.');

        $rows = $this->actingAs(User::factory()->create())
            ->getJson('/bids/data?tab=failed')->assertOk()
            ->json('rowsHtml');

        $this->assertStringContainsString('Other', $rows);
        $this->assertStringNotContainsString('Skill not matched', $rows);
    }
}
