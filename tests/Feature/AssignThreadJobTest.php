<?php

namespace Tests\Feature;

use App\Jobs\AssignThreadJob;
use App\Jobs\GenerateAiReplyJob;
use App\Jobs\SendFcmPushJob;
use App\Models\Filter;
use App\Models\MobileNotification;
use App\Models\Thread;
use App\Models\ThreadMessage;
use App\Models\Transition;
use App\Models\TransitionUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class AssignThreadJobTest extends TestCase
{
    use RefreshDatabase;

    private function mobileUser(string $name = 'u'): User
    {
        return User::factory()->create(['role' => 'mobile', 'name' => $name, 'fcm_token' => "tok-{$name}"]);
    }

    /** Build a transition number => [ordered user ids]. */
    private function transition(int $number, array $users): Transition
    {
        $t = Transition::factory()->create(['number' => $number]);
        foreach (array_values($users) as $i => $u) {
            TransitionUser::factory()->create(['transition_id' => $t->id, 'user_id' => $u->id, 'position' => $i]);
        }

        return $t;
    }

    private function threadWithMessage(): Thread
    {
        $thread = Thread::factory()->create();
        ThreadMessage::factory()->create([
            'thread_id' => $thread->id,
            'direction' => 'received',
            'message' => 'Hi, can you help?',
        ]);

        return $thread;
    }

    public function test_allocated_transition_first_user_is_assigned_and_pointer_set(): void
    {
        Queue::fake();
        Filter::factory()->create(['id' => 1, 'allocation_prompt' => 'route it']);
        $abid = $this->mobileUser('abid');
        $irfan = $this->mobileUser('irfan');
        $t = $this->transition(7, [$abid, $irfan]);

        Http::fake(['https://api.openai.com/*' => Http::response([
            'choices' => [['message' => ['content' => '{"number": 7}']]],
        ])]);

        $thread = $this->threadWithMessage();
        app()->call([new AssignThreadJob($thread->id), 'handle']);

        $fresh = $thread->fresh();
        $this->assertSame($abid->id, (int) $fresh->assigned_user_id);
        $this->assertSame($t->id, (int) $fresh->transition_id);
        $this->assertSame(0, (int) $fresh->transition_position);
        $this->assertNotNull(MobileNotification::where('user_id', $abid->id)->first());
        Queue::assertPushed(SendFcmPushJob::class, fn ($job) => $job->userId === $abid->id);
    }

    public function test_unknown_number_leaves_thread_unassigned(): void
    {
        Queue::fake();
        Filter::factory()->create(['id' => 1, 'allocation_prompt' => 'route it']);
        $abid = $this->mobileUser('abid');
        $this->transition(7, [$abid]);

        Http::fake(['https://api.openai.com/*' => Http::response([
            'choices' => [['message' => ['content' => '{"number": 99}']]],
        ])]);

        $thread = $this->threadWithMessage();
        app()->call([new AssignThreadJob($thread->id), 'handle']);

        $this->assertNull($thread->fresh()->assigned_user_id);
        Queue::assertNotPushed(GenerateAiReplyJob::class);
    }

    public function test_no_transitions_leaves_thread_unassigned(): void
    {
        Queue::fake();
        Filter::factory()->create(['id' => 1]);
        Http::fake();

        $thread = $this->threadWithMessage();
        app()->call([new AssignThreadJob($thread->id), 'handle']);

        $this->assertNull($thread->fresh()->assigned_user_id);
        Queue::assertNothingPushed();
    }

    public function test_assignment_queues_ai_reply_for_latest_client_message(): void
    {
        Queue::fake();
        Filter::factory()->create(['id' => 1, 'allocation_prompt' => 'route it']);
        $abid = $this->mobileUser('abid');
        $this->transition(7, [$abid]);
        Http::fake(['https://api.openai.com/*' => Http::response([
            'choices' => [['message' => ['content' => '{"number": 7}']]],
        ])]);

        $thread = $this->threadWithMessage();
        $latest = ThreadMessage::factory()->create([
            'thread_id' => $thread->id,
            'direction' => 'received',
            'message' => 'Any update?',
            'message_time' => now(),
        ]);

        app()->call([new AssignThreadJob($thread->id), 'handle']);

        Queue::assertPushed(
            GenerateAiReplyJob::class,
            fn ($job) => $job->threadId === $thread->id && $job->clientMessageId === $latest->id
        );
    }
}
