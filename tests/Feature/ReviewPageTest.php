<?php

namespace Tests\Feature;

use App\Models\Proposal;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReviewPageTest extends TestCase
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

    private function makeProposal(int $projectId, string $createdAt): void
    {
        $proposal = new Proposal();
        $proposal->project_id = $projectId;
        $proposal->title = "Project {$projectId}";
        $proposal->description = 'desc';
        $proposal->created_at = $createdAt;
        $proposal->updated_at = $createdAt;
        $proposal->save();
    }

    public function test_requires_auth(): void
    {
        $this->get('/review')->assertRedirect();
    }

    public function test_page_renders_tabs_and_new_projects_by_default(): void
    {
        $this->makeProposal(1, '2026-07-15 09:00:00'); // new
        $this->makeProposal(2, '2026-07-01 09:00:00'); // old

        $res = $this->actingAs(User::factory()->create())->get('/review')->assertOk();

        $res->assertSee('New Projects');
        $res->assertSee('Old Projects');
        // default tab is New → shows Project 1, not Project 2
        $res->assertSee('Project 1');
        $res->assertDontSee('Project 2');
    }
}
