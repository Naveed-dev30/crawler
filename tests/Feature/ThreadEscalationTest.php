<?php

namespace Tests\Feature;

use App\Jobs\SendFcmPushJob;
use App\Models\ActivityLog;
use App\Models\Filter;
use App\Models\MobileNotification;
use App\Models\Thread;
use App\Models\User;
use App\Services\ThreadEscalator;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ThreadEscalationTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function ladderUser(int $ladder, string $name = null): User
    {
        return User::factory()->create([
            'role' => 'mobile',
            'escalation_ladder' => $ladder,
            'name' => $name ?? "User L{$ladder}",
            'fcm_token' => "token-{$ladder}",
        ]);
    }

    private function overdueThread(User $assignee, array $attrs = []): Thread
    {
        return Thread::factory()->create(array_merge([
            'assigned_user_id' => $assignee->id,
            'status' => 'fresh',
            'last_client_message_at' => now()->subMinutes(45),
            'last_escalated_at' => null,
        ], $attrs));
    }

    public function test_overdue_fresh_thread_escalates_to_next_ladder(): void
    {
        Queue::fake();
        Filter::factory()->create(['escalation_minutes' => 30]);
        $userA = $this->ladderUser(1, 'Alice');
        $userB = $this->ladderUser(2, 'Bob');
        $thread = $this->overdueThread($userA);

        app(ThreadEscalator::class)->run();

        $fresh = $thread->fresh();
        $this->assertSame($userB->id, (int) $fresh->assigned_user_id);
        $this->assertNotNull($fresh->last_escalated_at);

        $log = ActivityLog::first();
        $this->assertNotNull($log);
        $this->assertSame('escalation', $log->type);
        $this->assertStringContainsString("thread {$thread->project_id} escalated from user(Alice) to user(Bob)", $log->message);

        $this->assertSame(2, MobileNotification::count()); // both users
        Queue::assertPushed(SendFcmPushJob::class, 2);
    }

    public function test_not_yet_overdue_thread_is_untouched(): void
    {
        Queue::fake();
        Filter::factory()->create(['escalation_minutes' => 120]);
        $userA = $this->ladderUser(1);
        $this->ladderUser(2);
        $thread = $this->overdueThread($userA, ['last_client_message_at' => now()->subMinutes(45)]);

        app(ThreadEscalator::class)->run();

        $this->assertSame($userA->id, (int) $thread->fresh()->assigned_user_id);
    }

    public function test_escalation_timer_resets_after_escalation(): void
    {
        Queue::fake();
        Filter::factory()->create(['escalation_minutes' => 30]);
        $userA = $this->ladderUser(1);
        $userB = $this->ladderUser(2);
        $this->ladderUser(3);

        // Escalated to B 10 minutes ago; client message is old. Timer must
        // count from last_escalated_at, so no second escalation yet.
        $thread = $this->overdueThread($userB, [
            'last_client_message_at' => now()->subHours(3),
            'last_escalated_at' => now()->subMinutes(10),
        ]);

        app(ThreadEscalator::class)->run();

        $this->assertSame($userB->id, (int) $thread->fresh()->assigned_user_id);
    }

    public function test_top_of_ladder_thread_is_skipped(): void
    {
        Queue::fake();
        Filter::factory()->create(['escalation_minutes' => 30]);
        $userTop = $this->ladderUser(2);
        $thread = $this->overdueThread($userTop); // nobody at ladder 3

        app(ThreadEscalator::class)->run();

        $this->assertSame($userTop->id, (int) $thread->fresh()->assigned_user_id);
        $this->assertSame(0, ActivityLog::count());
    }

    public function test_blocked_and_answered_threads_are_skipped(): void
    {
        Queue::fake();
        Filter::factory()->create(['escalation_minutes' => 30]);
        $userA = $this->ladderUser(1);
        $this->ladderUser(2);

        $blocked = $this->overdueThread($userA, ['blocked' => true]);
        $answered = $this->overdueThread($userA, ['status' => 'answered']);

        app(ThreadEscalator::class)->run();

        $this->assertSame($userA->id, (int) $blocked->fresh()->assigned_user_id);
        $this->assertSame($userA->id, (int) $answered->fresh()->assigned_user_id);
        $this->assertSame(0, ActivityLog::count());
    }
}
