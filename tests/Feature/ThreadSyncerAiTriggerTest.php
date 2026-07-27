<?php

// tests/Feature/ThreadSyncerAiTriggerTest.php

namespace Tests\Feature;

use App\Jobs\GenerateAiReplyJob;
use App\Models\Thread;
use App\Models\ThreadMessage;
use App\Models\User;
use App\Services\ThreadSyncer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ThreadSyncerAiTriggerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-07-27 10:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function activeUser(): User
    {
        return User::factory()->create([
            'role' => 'mobile', 'escalation_ladder' => 1,
            'ai_schedule_enabled' => true, 'ai_window_start' => '09:00:00',
            'ai_window_end' => '17:00:00', 'ai_timezone' => 'UTC',
        ]);
    }

    public function test_inbound_message_dispatches_reply_when_active(): void
    {
        Queue::fake();
        $user = $this->activeUser();
        $thread = Thread::factory()->create(['assigned_user_id' => $user->id]);
        $msg = ThreadMessage::factory()->create([
            'thread_id' => $thread->id, 'direction' => 'received', 'message_time' => now(),
        ]);

        app(ThreadSyncer::class)->maybeQueueAiReply($thread->fresh(), $msg);

        Queue::assertPushed(GenerateAiReplyJob::class, fn ($j) => $j->threadId === $thread->id && $j->clientMessageId === $msg->id);
    }

    public function test_no_dispatch_when_inactive_or_blocked_or_sent(): void
    {
        Queue::fake();
        $user = $this->activeUser();

        $blocked = Thread::factory()->create(['assigned_user_id' => $user->id, 'blocked' => true]);
        $bMsg = ThreadMessage::factory()->create(['thread_id' => $blocked->id, 'direction' => 'received']);
        app(ThreadSyncer::class)->maybeQueueAiReply($blocked->fresh(), $bMsg);

        $sent = Thread::factory()->create(['assigned_user_id' => $user->id]);
        $sMsg = ThreadMessage::factory()->create(['thread_id' => $sent->id, 'direction' => 'sent']);
        app(ThreadSyncer::class)->maybeQueueAiReply($sent->fresh(), $sMsg);

        $unassigned = Thread::factory()->create(['assigned_user_id' => null]);
        $uMsg = ThreadMessage::factory()->create(['thread_id' => $unassigned->id, 'direction' => 'received']);
        app(ThreadSyncer::class)->maybeQueueAiReply($unassigned->fresh(), $uMsg);

        Queue::assertNothingPushed();
    }
}
