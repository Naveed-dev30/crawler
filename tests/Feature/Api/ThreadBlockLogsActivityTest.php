<?php

namespace Tests\Feature\Api;

use App\Models\ActivityLog;
use App\Models\Proposal;
use App\Models\Thread;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ThreadBlockLogsActivityTest extends TestCase
{
    use RefreshDatabase;

    private function agentThread(): array
    {
        $agent = User::factory()->create(['role' => 'mobile']);
        $proposal = Proposal::factory()->create();
        $thread = Thread::factory()->create(['proposal_id' => $proposal->id, 'assigned_user_id' => $agent->id]);

        return [$agent, $thread];
    }

    public function test_block_writes_activity_log(): void
    {
        [$agent, $thread] = $this->agentThread();

        Sanctum::actingAs($agent);
        $this->postJson("/api/v1/mobile/threads/{$thread->id}/block", ['reason' => 'spam'])
            ->assertOk();

        $log = ActivityLog::where('thread_id', $thread->id)->where('type', 'block')->first();
        $this->assertNotNull($log);
        $this->assertSame($agent->id, $log->from_user_id);
    }

    public function test_unblock_writes_activity_log(): void
    {
        [$agent, $thread] = $this->agentThread();
        $thread->update(['blocked' => true, 'block_reason' => 'spam']);

        Sanctum::actingAs($agent);
        $this->postJson("/api/v1/mobile/threads/{$thread->id}/unblock")
            ->assertOk();

        $this->assertSame(1, ActivityLog::where('thread_id', $thread->id)->where('type', 'unblock')->count());
    }
}
