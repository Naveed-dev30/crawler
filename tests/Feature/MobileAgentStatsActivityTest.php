<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Proposal;
use App\Models\Thread;
use App\Models\ThreadMessage;
use App\Models\User;
use App\Services\MobileAgentStats;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MobileAgentStatsActivityTest extends TestCase
{
    use RefreshDatabase;

    public function test_merges_logs_and_responses_newest_first(): void
    {
        $from = Carbon::parse('2026-07-01 00:00:00');
        $to = Carbon::parse('2026-07-31 23:59:59');

        $agent = User::factory()->create(['role' => 'mobile']);
        $proposal = Proposal::factory()->create();
        $thread = Thread::factory()->create(['proposal_id' => $proposal->id, 'assigned_user_id' => $agent->id, 'project_id' => 9911]);

        $assign = ActivityLog::create(['thread_id' => $thread->id, 'to_user_id' => $agent->id, 'type' => 'manual_assign', 'message' => 'assigned']);
        $assign->forceFill(['created_at' => Carbon::parse('2026-07-10 09:00:00')])->save();

        ThreadMessage::factory()->create(['thread_id' => $thread->id, 'sender_user_id' => $agent->id, 'direction' => 'sent', 'sent_by_ai' => false, 'message' => 'Hello client', 'message_time' => Carbon::parse('2026-07-10 10:00:00')]);

        // out of range — must be excluded
        $old = ActivityLog::create(['thread_id' => $thread->id, 'to_user_id' => $agent->id, 'type' => 'ai_match', 'message' => 'old']);
        $old->forceFill(['created_at' => Carbon::parse('2026-06-01 09:00:00')])->save();

        $items = (new MobileAgentStats)->activityFor($agent, $from, $to);

        $this->assertCount(2, $items);
        // newest first: the response (10:00) before the assignment (09:00)
        $this->assertSame('responded', $items[0]['type']);
        $this->assertSame('manual_assign', $items[1]['type']);
        $this->assertSame(9911, $items[0]['project_id']);
    }
}
