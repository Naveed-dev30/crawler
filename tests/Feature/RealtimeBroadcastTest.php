<?php
// tests/Feature/RealtimeBroadcastTest.php

namespace Tests\Feature;

use App\Events\ThreadAssigned;
use App\Events\ThreadMessageCreated;
use App\Events\ThreadReadStateChanged;
use App\Jobs\MarkThreadReadJob;
use App\Models\Proposal;
use App\Models\Thread;
use App\Models\ThreadMessage;
use App\Models\User;
use App\Services\ThreadAssigner;
use App\Services\ThreadSyncer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RealtimeBroadcastTest extends TestCase
{
    use RefreshDatabase;

    // ---- channel auth (mobile, sanctum) ----

    private function pusherTestConfig(): void
    {
        config([
            'broadcasting.default' => 'pusher',
            'broadcasting.connections.pusher.key' => 'test-key',
            'broadcasting.connections.pusher.secret' => 'test-secret',
            'broadcasting.connections.pusher.app_id' => 'test-app',
            'broadcasting.connections.pusher.options' => [
                'host' => 'localhost', 'port' => 6001, 'scheme' => 'http', 'useTLS' => false,
            ],
        ]);

        // Channels were registered on the boot-time (log) broadcaster;
        // register them again on the pusher broadcaster under test.
        require base_path('routes/channels.php');
    }

    public function test_guest_cannot_auth_channels(): void
    {
        $this->postJson('/api/broadcasting/auth', [
            'channel_name' => 'private-user.1',
            'socket_id' => '123.456',
        ])->assertUnauthorized();
    }

    public function test_user_can_auth_own_user_channel_but_not_others(): void
    {
        $this->pusherTestConfig();
        $me = User::factory()->create(['role' => 'mobile']);
        Sanctum::actingAs($me);

        $this->postJson('/api/broadcasting/auth', [
            'channel_name' => "private-user.{$me->id}",
            'socket_id' => '123.456',
        ])->assertOk()->assertJsonStructure(['auth']);

        $this->postJson('/api/broadcasting/auth', [
            'channel_name' => 'private-user.999999',
            'socket_id' => '123.456',
        ])->assertForbidden();
    }

    public function test_thread_channel_only_for_assignee_or_admin(): void
    {
        $this->pusherTestConfig();
        $assignee = User::factory()->create(['role' => 'mobile']);
        $stranger = User::factory()->create(['role' => 'mobile']);
        $admin = User::factory()->create(['role' => 'admin']);
        $thread = Thread::factory()->create(['assigned_user_id' => $assignee->id]);

        Sanctum::actingAs($assignee);
        $this->postJson('/api/broadcasting/auth', [
            'channel_name' => "private-thread.{$thread->id}",
            'socket_id' => '123.456',
        ])->assertOk();

        Sanctum::actingAs($stranger);
        $this->postJson('/api/broadcasting/auth', [
            'channel_name' => "private-thread.{$thread->id}",
            'socket_id' => '123.456',
        ])->assertForbidden();

        Sanctum::actingAs($admin);
        $this->postJson('/api/broadcasting/auth', [
            'channel_name' => "private-thread.{$thread->id}",
            'socket_id' => '123.456',
        ])->assertOk();
    }

    public function test_admin_web_session_can_auth_thread_channel(): void
    {
        $this->pusherTestConfig();
        $admin = User::factory()->create(['role' => 'admin']);
        $thread = Thread::factory()->create();

        $this->actingAs($admin)->post('/broadcasting/auth', [
            'channel_name' => "private-thread.{$thread->id}",
            'socket_id' => '123.456',
        ])->assertOk();
    }

    // ---- event dispatch points ----

    public function test_syncer_import_fires_message_created(): void
    {
        Queue::fake();
        Event::fake([ThreadMessageCreated::class]);
        config([
            'variables.flUserId' => 55555,
            'variables.flBase' => 'https://www.freelancer.com',
            'variables.flKey' => 'k',
        ]);
        Proposal::factory()->create(['project_id' => 777]);
        Http::fake([
            'https://www.freelancer.com/api/messages/0.1/threads/*' => Http::response([
                'status' => 'success',
                'result' => ['threads' => [[
                    'id' => 9001,
                    'thread' => ['context' => ['type' => 'project', 'id' => 777]],
                    'time_updated' => 1700000100,
                ]]],
            ]),
            'https://www.freelancer.com/api/messages/0.1/messages/*' => Http::response([
                'status' => 'success',
                'result' => ['messages' => [[
                    'id' => 1, 'thread_id' => 9001, 'from_user' => 111,
                    'message' => 'hi', 'time_created' => 1700000050,
                ]]],
            ]),
        ]);

        app(ThreadSyncer::class)->run();

        Event::assertDispatched(ThreadMessageCreated::class, fn ($e) => $e->message->message === 'hi');
    }

    public function test_mobile_send_fires_message_created(): void
    {
        Event::fake([ThreadMessageCreated::class]);
        config(['variables.flBase' => 'https://www.freelancer.com', 'variables.flKey' => 'k']);
        $me = User::factory()->create(['role' => 'mobile']);
        $thread = Thread::factory()->create(['assigned_user_id' => $me->id, 'freelancer_thread_id' => 9001]);
        Sanctum::actingAs($me);
        Http::fake([
            'https://www.freelancer.com/api/messages/0.1/threads/9001/messages/*' => Http::response([
                'status' => 'success', 'result' => ['id' => 777],
            ]),
        ]);

        $this->postJson("/api/v1/mobile/threads/{$thread->id}/messages", ['message' => 'hello'])
            ->assertStatus(201);

        Event::assertDispatched(ThreadMessageCreated::class, fn ($e) => $e->message->sender_user_id === $me->id);
    }

    public function test_assign_fires_thread_assigned_for_both_users(): void
    {
        Queue::fake();
        Event::fake([ThreadAssigned::class]);
        $from = User::factory()->create(['role' => 'mobile']);
        $to = User::factory()->create(['role' => 'mobile']);
        $thread = Thread::factory()->create(['assigned_user_id' => $from->id]);

        app(ThreadAssigner::class)->assign($thread, $to, ThreadAssigner::TYPE_MANUAL, $from);

        Event::assertDispatched(ThreadAssigned::class, function ($e) use ($to, $from) {
            $channels = collect($e->broadcastOn())->map(fn ($c) => (string) $c);
            return $e->to->id === $to->id
                && $channels->contains("private-user.{$to->id}")
                && $channels->contains("private-user.{$from->id}");
        });
    }

    public function test_mark_read_fires_read_state_changed(): void
    {
        Event::fake([ThreadReadStateChanged::class]);
        config(['variables.flBase' => 'https://www.freelancer.com', 'variables.flKey' => 'k']);
        Http::fake([
            'https://www.freelancer.com/api/messages/0.1/threads/9001/*' => Http::response(['status' => 'success']),
        ]);
        $thread = Thread::factory()->create(['freelancer_thread_id' => 9001]);
        ThreadMessage::factory()->create(['thread_id' => $thread->id, 'direction' => 'received', 'is_read' => false]);

        (new MarkThreadReadJob($thread->id))->handle(app(\App\Services\FreelancerMessenger::class));

        Event::assertDispatched(ThreadReadStateChanged::class, fn ($e) => $e->threadId === $thread->id);
    }

    public function test_message_created_payload_and_channels(): void
    {
        $user = User::factory()->create(['role' => 'mobile', 'name' => 'Ali R']);
        $thread = Thread::factory()->create(['assigned_user_id' => $user->id]);
        $message = ThreadMessage::factory()->create([
            'thread_id' => $thread->id,
            'direction' => 'sent',
            'sender_user_id' => null,
            'freelancer_message_id' => 5,
            'message' => 'from web',
        ]);

        $event = new ThreadMessageCreated($message);

        $channels = collect($event->broadcastOn())->map(fn ($c) => (string) $c);
        $this->assertTrue($channels->contains("private-thread.{$thread->id}"));
        $this->assertTrue($channels->contains("private-user.{$user->id}"));

        $payload = $event->broadcastWith();
        $this->assertSame('Owner', $payload['sender_name']);
        $this->assertTrue($payload['is_sent']);
        $this->assertSame('message.created', $event->broadcastAs());
    }
}
