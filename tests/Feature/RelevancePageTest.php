<?php

namespace Tests\Feature;

use App\Models\Bid;
use App\Models\Proposal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RelevancePageTest extends TestCase
{
    use RefreshDatabase;

    public function test_page_shows_unreviewed_and_hides_reviewed(): void
    {
        $shown = Proposal::factory()->create(['title' => 'SHOWN PROJECT']);
        Bid::factory()->create(['proposal_id' => $shown->id, 'admin_feedback' => null]);

        $hidden = Proposal::factory()->create(['title' => 'HIDDEN PROJECT']);
        Bid::factory()->create(['proposal_id' => $hidden->id, 'admin_feedback' => 'relevant']);

        $response = $this->actingAs(User::factory()->create())->get('/relevance');

        $response->assertOk()
            ->assertSee('SHOWN PROJECT')
            ->assertDontSee('HIDDEN PROJECT');
    }
}
