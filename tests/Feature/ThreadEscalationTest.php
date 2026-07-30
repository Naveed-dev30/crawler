<?php

namespace Tests\Feature;

use App\Models\Filter;
use App\Models\Thread;
use App\Models\Transition;
use App\Models\TransitionUser;
use App\Models\User;
use App\Services\ThreadEscalator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ThreadEscalationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake(); // swallow SendFcmPushJob
        Filter::factory()->create(['id' => 1, 'escalation_minutes' => 30]);
    }

    private function lane(int $number, array $users): Transition
    {
        $t = Transition::factory()->create(['number' => $number]);
        foreach (array_values($users) as $i => $u) {
            TransitionUser::factory()->create(['transition_id' => $t->id, 'user_id' => $u->id, 'position' => $i]);
        }

        return $t;
    }

    public function test_escalates_to_next_user_after_window(): void
    {
        $a = User::factory()->create(['role' => 'mobile']);
        $b = User::factory()->create(['role' => 'mobile']);
        $lane = $this->lane(1, [$a, $b]);
        $thread = Thread::factory()->create([
            'status' => 'fresh',
            'blocked' => false,
            'assigned_user_id' => $a->id,
            'transition_id' => $lane->id,
            'transition_position' => 0,
            'last_client_message_at' => now()->subMinutes(45),
        ]);

        app(ThreadEscalator::class)->run();

        $fresh = $thread->fresh();
        $this->assertSame($b->id, (int) $fresh->assigned_user_id);
        $this->assertSame(1, (int) $fresh->transition_position);
    }

    public function test_does_not_escalate_before_window(): void
    {
        $a = User::factory()->create(['role' => 'mobile']);
        $b = User::factory()->create(['role' => 'mobile']);
        $lane = $this->lane(1, [$a, $b]);
        $thread = Thread::factory()->create([
            'status' => 'fresh', 'blocked' => false, 'assigned_user_id' => $a->id,
            'transition_id' => $lane->id, 'transition_position' => 0,
            'last_client_message_at' => now()->subMinutes(5),
        ]);

        app(ThreadEscalator::class)->run();

        $this->assertSame($a->id, (int) $thread->fresh()->assigned_user_id);
    }

    public function test_last_user_in_lane_stays_put(): void
    {
        $a = User::factory()->create(['role' => 'mobile']);
        $b = User::factory()->create(['role' => 'mobile']);
        $lane = $this->lane(1, [$a, $b]);
        $thread = Thread::factory()->create([
            'status' => 'fresh', 'blocked' => false, 'assigned_user_id' => $b->id,
            'transition_id' => $lane->id, 'transition_position' => 1,
            'last_client_message_at' => now()->subMinutes(45),
        ]);

        app(ThreadEscalator::class)->run();

        $fresh = $thread->fresh();
        $this->assertSame($b->id, (int) $fresh->assigned_user_id);
        $this->assertSame(1, (int) $fresh->transition_position);
    }

    public function test_thread_without_transition_is_ignored(): void
    {
        $a = User::factory()->create(['role' => 'mobile']);
        $thread = Thread::factory()->create([
            'status' => 'fresh', 'blocked' => false, 'assigned_user_id' => $a->id,
            'transition_id' => null, 'transition_position' => null,
            'last_client_message_at' => now()->subMinutes(45),
        ]);

        app(ThreadEscalator::class)->run();

        $this->assertSame($a->id, (int) $thread->fresh()->assigned_user_id);
    }

    public function test_a_future_dated_client_message_does_not_escalate_immediately(): void
    {
        // Freelancer clock skew puts last_client_message_at in the future.
        // diffInMinutes() is ABSOLUTE in Carbon 2, so the old check read such a
        // timestamp as long overdue and escalated on the very next pass.
        $a = User::factory()->create(['role' => 'mobile']);
        $b = User::factory()->create(['role' => 'mobile']);
        $lane = $this->lane(1, [$a, $b]);
        $thread = Thread::factory()->create([
            'status' => 'fresh', 'blocked' => false, 'assigned_user_id' => $a->id,
            'transition_id' => $lane->id, 'transition_position' => 0,
            'last_client_message_at' => now()->addHours(2),
        ]);

        app(ThreadEscalator::class)->run();

        $fresh = $thread->fresh();
        $this->assertSame($a->id, (int) $fresh->assigned_user_id);
        $this->assertSame(0, (int) $fresh->transition_position);
    }
}
