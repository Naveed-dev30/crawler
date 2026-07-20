<?php

namespace Tests\Feature;

use App\Models\Proposal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NotQualifiedTabTest extends TestCase
{
    use RefreshDatabase;

    private function user(): User
    {
        return User::factory()->create();
    }

    public function test_tab_returns_only_not_qualified_proposals(): void
    {
        Proposal::factory()->create([
            'project_id' => 111,
            'title' => 'Bad crypto project',
            'qualified' => false,
            'qualify_reason' => 'Matches crypto criteria',
            'qualify_summary' => 'A crypto bot build request',
        ]);
        Proposal::factory()->create(['project_id' => 222, 'title' => 'Good qualified project', 'qualified' => true]);
        Proposal::factory()->create(['project_id' => 333, 'title' => 'Unjudged project', 'qualified' => null]);

        $res = $this->actingAs($this->user())
            ->getJson('/bids/data?tab=not-qualified')
            ->assertOk()
            ->json();

        $this->assertArrayHasKey('cards', $res);
        $this->assertArrayHasKey('statusCounts', $res);
        $this->assertArrayHasKey('paginationHtml', $res);
        $this->assertStringContainsString('Bad crypto project', $res['rowsHtml']);
        $this->assertStringContainsString('Matches crypto criteria', $res['rowsHtml']);
        $this->assertStringContainsString('A crypto bot build request', $res['rowsHtml']);
        $this->assertStringContainsString('freelancer.com/projects/111', $res['rowsHtml']);
        $this->assertStringNotContainsString('Good qualified project', $res['rowsHtml']);
        $this->assertStringNotContainsString('Unjudged project', $res['rowsHtml']);
    }

    public function test_search_filters_by_title(): void
    {
        Proposal::factory()->create(['project_id' => 1, 'title' => 'Crypto bot', 'qualified' => false, 'qualify_reason' => 'r']);
        Proposal::factory()->create(['project_id' => 2, 'title' => 'Laravel site', 'qualified' => false, 'qualify_reason' => 'r']);

        $res = $this->actingAs($this->user())
            ->getJson('/bids/data?tab=not-qualified&q=Crypto')
            ->assertOk()
            ->json();

        $this->assertStringContainsString('Crypto bot', $res['rowsHtml']);
        $this->assertStringNotContainsString('Laravel site', $res['rowsHtml']);
    }

    public function test_search_filters_by_project_id(): void
    {
        Proposal::factory()->create(['project_id' => 987654, 'title' => 'Alpha', 'qualified' => false, 'qualify_reason' => 'r']);
        Proposal::factory()->create(['project_id' => 111111, 'title' => 'Beta', 'qualified' => false, 'qualify_reason' => 'r']);

        $res = $this->actingAs($this->user())
            ->getJson('/bids/data?tab=not-qualified&q=987654')
            ->assertOk()
            ->json();

        $this->assertStringContainsString('Alpha', $res['rowsHtml']);
        $this->assertStringNotContainsString('Beta', $res['rowsHtml']);
    }

    public function test_missing_summary_shows_placeholder(): void
    {
        Proposal::factory()->create(['project_id' => 5, 'title' => 'No summary one', 'qualified' => false, 'qualify_reason' => 'r', 'qualify_summary' => null]);

        $res = $this->actingAs($this->user())
            ->getJson('/bids/data?tab=not-qualified')
            ->assertOk()
            ->json();

        $this->assertStringContainsString('No summary available', $res['rowsHtml']);
    }

    public function test_empty_state_row(): void
    {
        $res = $this->actingAs($this->user())
            ->getJson('/bids/data?tab=not-qualified')
            ->assertOk()
            ->json();

        $this->assertStringContainsString('No not-qualified proposals yet.', $res['rowsHtml']);
        $this->assertStringContainsString('colspan="6"', $res['rowsHtml']);
    }
}
