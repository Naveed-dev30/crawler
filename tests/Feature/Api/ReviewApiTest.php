<?php

namespace Tests\Feature\Api;

use App\Models\Proposal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ReviewApiTest extends TestCase
{
    use RefreshDatabase;

    private function auth(): void
    {
        Sanctum::actingAs(User::factory()->create());
    }

    public function test_index_returns_new_proposals_needing_review(): void
    {
        $this->auth();
        Proposal::factory()->create(['review_label' => null, 'created_at' => now()]);
        Proposal::factory()->create(['review_label' => 'relevant', 'created_at' => now()]); // labeled -> excluded
        Proposal::factory()->create(['review_label' => null, 'created_at' => now()->subDays(30)]); // old

        $res = $this->getJson('/api/v1/review?tab=new')->assertOk();
        $res->assertJsonStructure([
            'data' => [[
                'id', 'title', 'description', 'type', 'country',
                'min_budget', 'max_budget', 'currency_symbol', 'skills', 'seo_url', 'created_at',
            ]],
            'hasMore', 'newCount', 'oldCount',
        ]);
        $this->assertSame(1, $res->json('newCount'));
        $this->assertSame(1, $res->json('oldCount'));
        $this->assertCount(1, $res->json('data'));
    }

    public function test_old_tab_returns_only_old(): void
    {
        $this->auth();
        Proposal::factory()->create(['review_label' => null, 'created_at' => now()->subDays(30)]);
        Proposal::factory()->create(['review_label' => null, 'created_at' => now()]);

        $res = $this->getJson('/api/v1/review?tab=old')->assertOk();
        $this->assertCount(1, $res->json('data'));
    }

    public function test_after_id_pages_older_ids(): void
    {
        $this->auth();
        $a = Proposal::factory()->create(['review_label' => null, 'created_at' => now()]);
        $b = Proposal::factory()->create(['review_label' => null, 'created_at' => now()]);

        $res = $this->getJson("/api/v1/review?tab=new&after_id={$b->id}")->assertOk();
        $ids = array_column($res->json('data'), 'id');
        $this->assertEquals([$a->id], $ids);
    }

    public function test_feedback_persists_label(): void
    {
        $this->auth();
        $p = Proposal::factory()->create(['review_label' => null]);

        $this->postJson('/api/v1/review/feedback', ['proposal_id' => $p->id, 'label' => 'scam'])
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->assertSame('scam', $p->fresh()->review_label);
    }

    public function test_feedback_invalid_label_returns_422(): void
    {
        $this->auth();
        $p = Proposal::factory()->create();

        $this->postJson('/api/v1/review/feedback', ['proposal_id' => $p->id, 'label' => 'bogus'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['label']);
    }

    public function test_requires_auth(): void
    {
        $this->getJson('/api/v1/review')->assertStatus(401);
    }
}
