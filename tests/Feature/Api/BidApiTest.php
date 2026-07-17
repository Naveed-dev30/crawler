<?php

namespace Tests\Feature\Api;

use App\Models\Bid;
use App\Models\Proposal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BidApiTest extends TestCase
{
    use RefreshDatabase;

    private function auth(): void
    {
        Sanctum::actingAs(User::factory()->create());
    }

    public function test_index_returns_bids_cards_and_meta(): void
    {
        $this->auth();
        $p = Proposal::factory()->create(['currency_symbol' => '$']);
        Bid::factory()->create(['proposal_id' => $p->id, 'bid_status' => 'pending']);

        $res = $this->getJson('/api/v1/bids')->assertOk();
        $res->assertJsonStructure([
            'data' => [[
                'id', 'status', 'price', 'currency', 'awarded', 'awarded_price',
                'check', 'is_seen', 'created_at',
                'proposal' => ['id', 'title', 'project_id', 'type', 'country', 'min_budget', 'max_budget', 'seo_url', 'skills'],
            ]],
            'cards' => ['total', 'placed', 'failed'],
            'meta' => ['current_page', 'last_page', 'per_page', 'total'],
        ]);
        $this->assertSame(1, $res->json('cards.total'));
        $this->assertSame('$', $res->json('data.0.currency'));
        $this->assertArrayNotHasKey('cover_letter', $res->json('data.0'));
    }

    public function test_index_failed_tab_filters_status(): void
    {
        $this->auth();
        Bid::factory()->create(['bid_status' => 'pending']);
        Bid::factory()->create(['bid_status' => 'failed']);

        $res = $this->getJson('/api/v1/bids?tab=failed')->assertOk();
        $statuses = array_values(array_unique(array_column($res->json('data'), 'status')));
        $this->assertEquals(['failed'], $statuses);
    }

    public function test_show_returns_full_bid_and_marks_seen(): void
    {
        $this->auth();
        $bid = Bid::factory()->create(['is_seen' => false]);

        $res = $this->getJson("/api/v1/bids/{$bid->id}")->assertOk();
        $res->assertJsonPath('data.id', $bid->id);
        $this->assertArrayHasKey('cover_letter', $res->json('data'));
        $this->assertTrue((bool) $bid->fresh()->is_seen);
    }

    public function test_show_missing_bid_returns_404(): void
    {
        $this->auth();
        $this->getJson('/api/v1/bids/999999')->assertStatus(404);
    }

    public function test_requires_auth(): void
    {
        $this->getJson('/api/v1/bids')->assertStatus(401);
    }
}
