<?php

// tests/Feature/Api/MobileThreadClientInfoTest.php

namespace Tests\Feature\Api;

use App\Models\BidInsight;
use App\Models\Thread;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MobileThreadClientInfoTest extends TestCase
{
    use RefreshDatabase;

    private User $me;

    protected function setUp(): void
    {
        parent::setUp();
        $this->me = User::factory()->create(['role' => 'mobile', 'escalation_ladder' => 1]);
        Sanctum::actingAs($this->me);
    }

    public function test_show_includes_client_block_from_bid_insight(): void
    {
        $thread = Thread::factory()->create(['assigned_user_id' => $this->me->id, 'project_id' => 40597933]);
        BidInsight::create([
            'project_id' => 40597933,
            'client_country' => 'Nigeria',
            'client_rating' => 5.0,
            'client_reviews' => 1,
            'client_engagement' => ['contacted' => 0, 'invited' => 0, 'completed' => 1],
            'last_scraped_at' => now(),
        ]);

        $res = $this->getJson("/api/v1/mobile/threads/{$thread->id}")->assertOk();

        $res->assertJsonPath('data.client.country', 'Nigeria');
        $res->assertJsonPath('data.client.reviews', 1);
        $res->assertJsonPath('data.client.engagement.completed', 1);
        $this->assertSame('5.00', (string) $res->json('data.client.rating'));
    }

    public function test_client_falls_back_to_static_when_no_insight(): void
    {
        $thread = Thread::factory()->create(['assigned_user_id' => $this->me->id, 'project_id' => 999]);
        $res = $this->getJson("/api/v1/mobile/threads/{$thread->id}")->assertOk();

        $res->assertJsonPath('data.client.name', 'Sarah Mitchell');
        $this->assertNotNull($res->json('data.client.avatar'));
    }
}
