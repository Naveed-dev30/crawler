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

    private function failedBid(string $errorMessage, string $status = 'failed'): void
    {
        $proposal = Proposal::factory()->create();
        Bid::factory()->create([
            'proposal_id' => $proposal->id,
            'bid_status' => $status,
            'error_message' => $errorMessage,
        ]);
    }

    public function test_expired_with_skill_error_shows_skills_not_matched(): void
    {
        $this->failedBid('required skills missing', 'expired');

        $rows = $this->actingAs(User::factory()->create())
            ->getJson('/bids/data?tab=skill-not-matched')->assertOk()
            ->json('rowsHtml');

        $this->assertStringContainsString('Skills Not Matched', $rows);
        $this->assertStringNotContainsString('>expired<', $rows);
    }

    public function test_expired_without_skill_error_shows_failed_badge(): void
    {
        $this->failedBid('', 'expired');

        $rows = $this->actingAs(User::factory()->create())
            ->getJson('/bids/data?tab=failed')->assertOk()
            ->json('rowsHtml');

        $this->assertStringContainsString('>failed<', $rows);
        $this->assertStringNotContainsString('>expired<', $rows);
    }

    public function test_skill_failure_shows_skills_not_matched_badge_instead_of_failed(): void
    {
        $this->failedBid('You must have the required skills to bid on this project.');

        $rows = $this->actingAs(User::factory()->create())
            ->getJson('/bids/data?tab=skill-not-matched')->assertOk()
            ->json('rowsHtml');

        $this->assertStringContainsString('Skills Not Matched', $rows);
        $this->assertStringNotContainsString('>failed<', $rows);
        $this->assertStringNotContainsString('>Other<', $rows);
    }

    public function test_other_failure_shows_plain_failed_badge(): void
    {
        $this->failedBid('Project is no longer available.');

        $rows = $this->actingAs(User::factory()->create())
            ->getJson('/bids/data?tab=failed')->assertOk()
            ->json('rowsHtml');

        $this->assertStringContainsString('failed', $rows);
        $this->assertStringNotContainsString('>Other<', $rows);
        $this->assertStringNotContainsString('Skills Not Matched', $rows);
    }
}
