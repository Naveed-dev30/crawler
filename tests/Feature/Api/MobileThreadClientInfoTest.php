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

    public function test_show_includes_bid_stats_and_actions_taken(): void
    {
        $thread = Thread::factory()->create(['assigned_user_id' => $this->me->id, 'project_id' => 40597934]);
        BidInsight::create([
            'project_id' => 40597934,
            'total_bids' => 32,
            'bid_rating' => 4.5,
            'actions_taken' => [
                'client_saw_your_bid' => true,
                'client_saw_your_profile' => false,
            ],
            'last_scraped_at' => now(),
        ]);

        $res = $this->getJson("/api/v1/mobile/threads/{$thread->id}")->assertOk();

        $res->assertJsonPath('data.client.total_bids', 32);
        $res->assertJsonPath('data.client.actions_taken.saw_bid', true);
        $res->assertJsonPath('data.client.actions_taken.saw_profile', false);
        // Rating > 0 is what makes the third icon solid on the web panel.
        $res->assertJsonPath('data.client.actions_taken.rated_bid', true);
        $this->assertSame('4.5', (string) $res->json('data.client.bid_rating'));
    }

    public function test_unrated_bid_reports_rated_false(): void
    {
        $thread = Thread::factory()->create(['assigned_user_id' => $this->me->id, 'project_id' => 40597935]);
        BidInsight::create([
            'project_id' => 40597935,
            // Freelancer reports an unrated bid as 0, never as null.
            'bid_rating' => 0,
            'last_scraped_at' => now(),
        ]);

        $res = $this->getJson("/api/v1/mobile/threads/{$thread->id}")->assertOk();

        $res->assertJsonPath('data.client.actions_taken.rated_bid', false);
        $res->assertJsonPath('data.client.actions_taken.saw_bid', false);
        $res->assertJsonPath('data.client.total_bids', null);
    }

    public function test_legacy_actions_taken_list_shape_still_reads(): void
    {
        // Rows captured under the original ingest contract stored a flat list of
        // the actions taken rather than a boolean map. The web icons read both,
        // so the app must agree with them.
        $thread = Thread::factory()->create(['assigned_user_id' => $this->me->id, 'project_id' => 40597936]);
        BidInsight::create([
            'project_id' => 40597936,
            'actions_taken' => ['viewed_by_client', 'viewed_your_profile'],
            'last_scraped_at' => now(),
        ]);

        $res = $this->getJson("/api/v1/mobile/threads/{$thread->id}")->assertOk();

        $res->assertJsonPath('data.client.actions_taken.saw_bid', true);
        $res->assertJsonPath('data.client.actions_taken.saw_profile', true);
    }

    public function test_client_is_null_when_there_is_no_insight(): void
    {
        // This used to return a hardcoded client ("Sarah Mitchell", a
        // pravatar.cc avatar, 4.8 rating, 27 reviews) with nothing marking it
        // as a placeholder, so the app rendered fabricated data as real. The
        // API now says it does not know, and the app shows an empty state.
        $thread = Thread::factory()->create(['assigned_user_id' => $this->me->id, 'project_id' => 999]);
        $res = $this->getJson("/api/v1/mobile/threads/{$thread->id}")->assertOk();

        $this->assertNull($res->json('data.client'));
    }
}
