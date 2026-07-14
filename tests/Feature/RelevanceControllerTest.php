<?php

namespace Tests\Feature;

use App\Models\Bid;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RelevanceControllerTest extends TestCase
{
    use RefreshDatabase;

    private function actingUser(): User
    {
        return User::factory()->create();
    }

    public function test_relevance_page_requires_auth(): void
    {
        $this->get('/relevance')->assertRedirect('/login');
    }

    public function test_load_returns_json_with_html_and_hasmore(): void
    {
        Bid::factory()->count(25)->create();

        $response = $this->actingAs($this->actingUser())
            ->getJson('/relevance/load');

        $response->assertOk()
            ->assertJsonStructure(['html', 'hasMore']);
        $this->assertTrue($response->json('hasMore')); // 25 bids, first 20 returned
    }

    public function test_load_after_cursor_returns_remainder_with_no_more(): void
    {
        Bid::factory()->count(25)->create();

        // Cursor = id of the 20th bid in descending-id order (the last of page 1).
        $twentiethId = Bid::orderByDesc('id')->skip(19)->take(1)->value('id');

        $response = $this->actingAs($this->actingUser())
            ->getJson('/relevance/load?after_id=' . $twentiethId);

        $response->assertOk();
        $this->assertFalse($response->json('hasMore')); // 5 remaining, no more after
    }

    public function test_store_feedback_saves_label(): void
    {
        $bid = Bid::factory()->create(['admin_feedback' => null]);

        $this->actingAs($this->actingUser())
            ->postJson('/relevance/feedback', [
                'bid_id' => $bid->id,
                'feedback' => 'scam',
            ])
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->assertSame('scam', $bid->fresh()->admin_feedback);
    }

    public function test_store_feedback_rejects_invalid_value(): void
    {
        $bid = Bid::factory()->create(['admin_feedback' => null]);

        $this->actingAs($this->actingUser())
            ->postJson('/relevance/feedback', [
                'bid_id' => $bid->id,
                'feedback' => 'maybe',
            ])
            ->assertStatus(422);

        $this->assertNull($bid->fresh()->admin_feedback);
    }
}
