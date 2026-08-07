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

class MobileAgentStatsRowsTest extends TestCase
{
    use RefreshDatabase;

    private Carbon $from;
    private Carbon $to;

    protected function setUp(): void
    {
        parent::setUp();
        $this->from = Carbon::parse('2026-07-01 00:00:00');
        $this->to = Carbon::parse('2026-07-31 23:59:59');
    }

    private function thread(User $agent): Thread
    {
        $proposal = Proposal::factory()->create();

        return Thread::factory()->create(['proposal_id' => $proposal->id, 'assigned_user_id' => $agent->id]);
    }

    private function log(array $attrs, Carbon $at): void
    {
        $log = ActivityLog::create(array_merge(['message' => 'x'], $attrs));
        $log->forceFill(['created_at' => $at])->save();
    }

    private function rowFor(int $userId): array
    {
        $rows = (new MobileAgentStats)->rows($this->from, $this->to);

        return collect($rows)->firstWhere('user_id', $userId);
    }

    public function test_counts_assigned_responded_blocked_reassigned(): void
    {
        $agent = User::factory()->create(['role' => 'mobile', 'name' => 'Ana']);
        $other = User::factory()->create(['role' => 'mobile', 'name' => 'Bob']);
        $t = $this->thread($agent);

        // assigned TO agent (3 types) = 2 here
        $this->log(['thread_id' => $t->id, 'to_user_id' => $agent->id, 'type' => 'ai_match'], $this->from->copy()->addDay());
        $this->log(['thread_id' => $t->id, 'from_user_id' => $other->id, 'to_user_id' => $agent->id, 'type' => 'manual_assign'], $this->from->copy()->addDays(2));
        // reassigned BY agent to other
        $this->log(['thread_id' => $t->id, 'from_user_id' => $agent->id, 'to_user_id' => $other->id, 'type' => 'manual_assign'], $this->from->copy()->addDays(3));
        // block BY agent
        $this->log(['thread_id' => $t->id, 'from_user_id' => $agent->id, 'to_user_id' => $agent->id, 'type' => 'block'], $this->from->copy()->addDays(4));

        // responded: 2 human sent, 1 AI (excluded), 1 received (excluded)
        ThreadMessage::factory()->create(['thread_id' => $t->id, 'sender_user_id' => $agent->id, 'direction' => 'sent', 'sent_by_ai' => false, 'message_time' => $this->from->copy()->addDays(2)]);
        ThreadMessage::factory()->create(['thread_id' => $t->id, 'sender_user_id' => $agent->id, 'direction' => 'sent', 'sent_by_ai' => false, 'message_time' => $this->from->copy()->addDays(3)]);
        ThreadMessage::factory()->create(['thread_id' => $t->id, 'sender_user_id' => $agent->id, 'direction' => 'sent', 'sent_by_ai' => true, 'message_time' => $this->from->copy()->addDays(3)]);
        ThreadMessage::factory()->create(['thread_id' => $t->id, 'sender_user_id' => null, 'direction' => 'received', 'sent_by_ai' => false, 'message_time' => $this->from->copy()->addDays(1)]);

        $row = $this->rowFor($agent->id);
        $this->assertSame('Ana', $row['name']);
        $this->assertSame(2, $row['assigned']);
        $this->assertSame(2, $row['responded']);
        $this->assertSame(1, $row['blocked']);
        $this->assertSame(1, $row['reassigned']);
    }

    public function test_avg_response_seconds_from_first_reply(): void
    {
        $agent = User::factory()->create(['role' => 'mobile']);
        $t = $this->thread($agent);
        $assignedAt = $this->from->copy()->addDays(5)->setTime(10, 0);

        $this->log(['thread_id' => $t->id, 'to_user_id' => $agent->id, 'type' => 'manual_assign'], $assignedAt);
        // first reply 1h later; a later reply must not change the average
        ThreadMessage::factory()->create(['thread_id' => $t->id, 'sender_user_id' => $agent->id, 'direction' => 'sent', 'sent_by_ai' => false, 'message_time' => $assignedAt->copy()->addHour()]);
        ThreadMessage::factory()->create(['thread_id' => $t->id, 'sender_user_id' => $agent->id, 'direction' => 'sent', 'sent_by_ai' => false, 'message_time' => $assignedAt->copy()->addHours(5)]);

        $this->assertSame(3600, $this->rowFor($agent->id)['avg_response_seconds']);
    }

    public function test_avg_null_when_no_reply(): void
    {
        $agent = User::factory()->create(['role' => 'mobile']);
        $t = $this->thread($agent);
        $this->log(['thread_id' => $t->id, 'to_user_id' => $agent->id, 'type' => 'manual_assign'], $this->from->copy()->addDays(5));

        $this->assertNull($this->rowFor($agent->id)['avg_response_seconds']);
    }

    public function test_respects_date_range(): void
    {
        $agent = User::factory()->create(['role' => 'mobile']);
        $t = $this->thread($agent);
        $this->log(['thread_id' => $t->id, 'to_user_id' => $agent->id, 'type' => 'manual_assign'], Carbon::parse('2026-06-01 10:00:00')); // out of range

        $this->assertSame(0, $this->rowFor($agent->id)['assigned']);
    }
}
