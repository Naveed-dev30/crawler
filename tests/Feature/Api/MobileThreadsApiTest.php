<?php

namespace Tests\Feature\Api;

use App\Models\Bid;
use App\Models\Proposal;
use App\Models\Thread;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MobileThreadsApiTest extends TestCase
{
    use RefreshDatabase;

    private User $me;

    protected function setUp(): void
    {
        parent::setUp();
        $this->me = User::factory()->create(['role' => 'mobile', 'escalation_ladder' => 1]);
    }

    private function actAsMe(): void
    {
        Sanctum::actingAs($this->me);
    }

    public function test_requires_mobile_role(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));

        $this->getJson('/api/v1/mobile/threads')->assertForbidden();
    }

    public function test_lists_only_my_unblocked_threads(): void
    {
        $other = User::factory()->create(['role' => 'mobile', 'escalation_ladder' => 2]);

        $mine = Thread::factory()->create(['assigned_user_id' => $this->me->id]);
        Thread::factory()->create(['assigned_user_id' => $this->me->id, 'blocked' => true]);
        Thread::factory()->create(['assigned_user_id' => $other->id]);

        $this->actAsMe();
        $response = $this->getJson('/api/v1/mobile/threads')->assertOk();

        $ids = collect($response->json('data'))->pluck('id');
        $this->assertSame([$mine->id], $ids->all());
    }

    public function test_blocked_param_lists_only_blocked_threads(): void
    {
        Thread::factory()->create(['assigned_user_id' => $this->me->id]);
        $blocked = Thread::factory()->create(['assigned_user_id' => $this->me->id, 'blocked' => true]);

        $this->actAsMe();
        $response = $this->getJson('/api/v1/mobile/threads?blocked=1')->assertOk();

        $ids = collect($response->json('data'))->pluck('id');
        $this->assertSame([$blocked->id], $ids->all());
    }

    public function test_show_includes_proposal_and_bid_cover_letter(): void
    {
        $proposal = Proposal::factory()->create(['title' => 'Big Laravel job']);
        Bid::factory()->create(['proposal_id' => $proposal->id, 'cover_letter' => 'Dear client, we rock.']);
        $thread = Thread::factory()->create([
            'assigned_user_id' => $this->me->id,
            'proposal_id' => $proposal->id,
        ]);

        $this->actAsMe();
        $this->getJson("/api/v1/mobile/threads/{$thread->id}")
            ->assertOk()
            ->assertJsonPath('data.proposal.title', 'Big Laravel job')
            ->assertJsonPath('data.proposal.bid.cover_letter', 'Dear client, we rock.');
    }

    public function test_show_foreign_thread_is_forbidden(): void
    {
        $other = User::factory()->create(['role' => 'mobile', 'escalation_ladder' => 2]);
        $thread = Thread::factory()->create(['assigned_user_id' => $other->id]);

        $this->actAsMe();
        $this->getJson("/api/v1/mobile/threads/{$thread->id}")->assertForbidden();
    }
}
