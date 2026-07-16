<?php

namespace Tests\Feature;

use App\Models\Proposal;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReviewLoadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-07-16 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function makeProposal(int $projectId, string $createdAt, ?string $label = null): Proposal
    {
        $proposal = new Proposal();
        $proposal->project_id = $projectId;
        $proposal->title = "Project {$projectId}";
        $proposal->description = 'desc';
        $proposal->review_label = $label;
        $proposal->created_at = $createdAt;
        $proposal->updated_at = $createdAt;
        $proposal->save();

        return $proposal;
    }

    public function test_new_tab_returns_recent_unlabeled_only(): void
    {
        $this->makeProposal(1, '2026-07-15 09:00:00');            // within 7 days → new
        $this->makeProposal(2, '2026-07-01 09:00:00');            // older → old
        $this->makeProposal(3, '2026-07-15 09:00:00', 'scam');   // labeled → excluded

        $res = $this->actingAs(User::factory()->create())
            ->getJson('/review/load?tab=new')->assertOk();

        $this->assertStringContainsString('Project 1', $res->json('html'));
        $this->assertStringNotContainsString('Project 2', $res->json('html'));
        $this->assertStringNotContainsString('Project 3', $res->json('html'));
        $this->assertFalse($res->json('hasMore'));
    }

    public function test_old_tab_returns_older_unlabeled_only(): void
    {
        $this->makeProposal(1, '2026-07-15 09:00:00');   // new
        $this->makeProposal(2, '2026-07-01 09:00:00');   // old

        $res = $this->actingAs(User::factory()->create())
            ->getJson('/review/load?tab=old')->assertOk();

        $this->assertStringContainsString('Project 2', $res->json('html'));
        $this->assertStringNotContainsString('Project 1', $res->json('html'));
    }

    public function test_cursor_pagination_hasmore_and_after_id(): void
    {
        // 21 recent unlabeled proposals → first page hasMore=true, 20 rows
        for ($i = 1; $i <= 21; $i++) {
            $this->makeProposal(1000 + $i, '2026-07-15 09:00:00');
        }
        $user = User::factory()->create();

        $first = $this->actingAs($user)->getJson('/review/load?tab=new')->assertOk();
        $this->assertTrue($first->json('hasMore'));
        $this->assertSame(20, substr_count($first->json('html'), 'data-proposal-id'));

        // lowest id on the page is the 1st created proposal (id 1). after_id=2 → only id 1 remains.
        $second = $this->actingAs($user)->getJson('/review/load?tab=new&after_id=2')->assertOk();
        $this->assertFalse($second->json('hasMore'));
        $this->assertSame(1, substr_count($second->json('html'), 'data-proposal-id'));
    }
}
