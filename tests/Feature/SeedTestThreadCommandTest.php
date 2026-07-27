<?php

namespace Tests\Feature;

use App\Http\Resources\ThreadResource;
use App\Models\Thread;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class SeedTestThreadCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeds_full_chain_with_client_block_for_user(): void
    {
        $user = User::factory()->create(['role' => 'mobile']);

        $this->artisan('test:seed-thread', ['user' => $user->id])->assertSuccessful();

        $thread = Thread::where('assigned_user_id', $user->id)->firstOrFail();
        $this->assertNotNull($thread->proposal);
        $this->assertNotNull($thread->proposal->bid);
        $this->assertSame(2, $thread->messages()->count());

        // The client block resolves the same way the show endpoint builds it.
        $thread->setAttribute(
            'client_insight',
            \App\Models\BidInsight::where('project_id', $thread->project_id)->first()
        );
        $data = (new ThreadResource($thread->load('proposal.bid')))->toArray(Request::create('/'));

        $this->assertSame('Nigeria', $data['client']['country']);
        $this->assertSame(1, $data['client']['reviews']);
        $this->assertTrue($data['client']['verification']['payment_verified']);
        $this->assertContains('PHP', $data['proposal']['skills']);
    }

    public function test_refuses_missing_user(): void
    {
        $this->artisan('test:seed-thread', ['user' => 999999])->assertFailed();
    }
}
