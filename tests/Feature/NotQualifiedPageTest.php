<?php

namespace Tests\Feature;

use App\Models\Proposal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NotQualifiedPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_requires_auth(): void
    {
        $this->get('/not-qualified')->assertRedirect('/login');
    }

    public function test_lists_only_not_qualified_proposals(): void
    {
        $rejected = Proposal::factory()->create(['qualified' => false, 'qualify_reason' => 'crypto match', 'title' => 'Rejected One']);
        Proposal::factory()->create(['qualified' => true, 'title' => 'Passed One']);
        Proposal::factory()->create(['qualified' => null, 'title' => 'Ungated One']);

        $res = $this->actingAs(User::factory()->create())->get('/not-qualified')->assertOk();
        $res->assertSee('Rejected One');
        $res->assertSee('crypto match');
        $res->assertDontSee('Passed One');
        $res->assertDontSee('Ungated One');
    }

    public function test_shows_no_summary_available_when_summary_empty(): void
    {
        Proposal::factory()->create(['qualified' => false, 'qualify_reason' => 'r', 'qualify_summary' => null]);

        $this->actingAs(User::factory()->create())
            ->get('/not-qualified')->assertOk()
            ->assertSee('No summary available');
    }
}
