<?php

namespace Tests\Feature;

use App\Jobs\AssignThreadJob;
use App\Jobs\SendFcmPushJob;
use App\Models\MobileNotification;
use App\Models\Proposal;
use App\Models\Thread;
use App\Models\ThreadMessage;
use App\Models\User;
use App\Services\ThreadSyncer;
use App\Support\NotificationType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Covers the push that a client's message produces.
 *
 * Before this, ThreadSyncer broadcast ThreadMessageCreated and stopped there:
 * the only push in the whole application came from ThreadAssigner, so a client
 * replying to an assigned thread produced no notification at all.
 */
class ThreadSyncerPushTest extends TestCase
{
    use RefreshDatabase;

    private const OUR_FL_USER_ID = 55555;

    private const CLIENT_FL_USER_ID = 111;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'variables.flUserId' => self::OUR_FL_USER_ID,
            'variables.flBase' => 'https://www.freelancer.com',
            'variables.flKey' => 'test-key',
        ]);
    }

    private function flThread(int $id, int $projectId, int $timeUpdated = 1700000100): array
    {
        return [
            'id' => $id,
            'thread' => [
                'members' => [self::OUR_FL_USER_ID, self::CLIENT_FL_USER_ID],
                'thread_type' => 'private_chat',
                'context' => ['type' => 'project', 'id' => $projectId],
                'time_created' => 1700000000,
            ],
            'time_updated' => $timeUpdated,
        ];
    }

    private function flMessage(int $id, int $fromUser, ?string $text, int $time = 1700000150, array $attachments = []): array
    {
        return [
            'id' => $id,
            'thread_id' => 9001,
            'from_user' => $fromUser,
            'message' => $text,
            'time_created' => $time,
            'attachments' => $attachments,
        ];
    }

    private function fakeFreelancer(array $threads, array $messages): void
    {
        Http::fake([
            'https://www.freelancer.com/api/messages/0.1/threads/*' => Http::response([
                'status' => 'success',
                'result' => ['threads' => $threads],
            ]),
            'https://www.freelancer.com/api/messages/0.1/messages/*' => Http::response([
                'status' => 'success',
                'result' => ['messages' => $messages],
            ]),
        ]);
    }

    /**
     * An existing thread already owned by a mobile user, ready to receive a
     * client reply on the next pass.
     */
    private function assignedThread(array $overrides = []): array
    {
        $proposal = Proposal::factory()->create([
            'project_id' => 777,
            'title' => 'Build a Flutter app',
        ]);
        $user = User::factory()->create(['role' => 'mobile']);
        $thread = Thread::factory()->create(array_merge([
            'freelancer_thread_id' => 9001,
            'project_id' => 777,
            'proposal_id' => $proposal->id,
            'assigned_user_id' => $user->id,
            'freelancer_time_updated' => 1700000100,
        ], $overrides));

        return [$thread, $user];
    }

    public function test_inbound_message_pushes_the_assigned_user(): void
    {
        Queue::fake();
        [$thread, $user] = $this->assignedThread();

        $this->fakeFreelancer(
            [$this->flThread(9001, 777, 1700000200)],
            [$this->flMessage(1, self::CLIENT_FL_USER_ID, 'Any update on the build?')]
        );

        app(ThreadSyncer::class)->run();

        Queue::assertPushed(SendFcmPushJob::class, 1);
        Queue::assertPushed(SendFcmPushJob::class, function ($job) use ($user, $thread) {
            return $job->userId === $user->id
                && $job->title === 'Build a Flutter app'
                && $job->body === 'Any update on the build?'
                && $job->data['type'] === NotificationType::MESSAGE
                && (int) $job->data['thread_id'] === $thread->id;
        });
    }

    public function test_a_batch_of_messages_produces_exactly_one_push(): void
    {
        Queue::fake();
        $this->assignedThread();

        $this->fakeFreelancer(
            [$this->flThread(9001, 777, 1700000200)],
            [
                $this->flMessage(1, self::CLIENT_FL_USER_ID, 'first', 1700000150),
                $this->flMessage(2, self::CLIENT_FL_USER_ID, 'second', 1700000160),
                $this->flMessage(3, self::CLIENT_FL_USER_ID, 'third and newest', 1700000170),
            ]
        );

        app(ThreadSyncer::class)->run();

        // One push for the batch, carrying the newest message — not three.
        Queue::assertPushed(SendFcmPushJob::class, 1);
        Queue::assertPushed(SendFcmPushJob::class, function ($job) {
            return str_contains($job->body, 'third and newest')
                && str_contains($job->body, '(+2 more)');
        });
        $this->assertSame(3, ThreadMessage::count());
    }

    public function test_our_own_messages_never_push(): void
    {
        Queue::fake();
        $this->assignedThread();

        $this->fakeFreelancer(
            [$this->flThread(9001, 777, 1700000200)],
            [$this->flMessage(1, self::OUR_FL_USER_ID, 'reply typed on freelancer.com')]
        );

        app(ThreadSyncer::class)->run();

        Queue::assertNotPushed(SendFcmPushJob::class);
    }

    public function test_blocked_thread_does_not_push(): void
    {
        Queue::fake();
        $this->assignedThread(['blocked' => true]);

        $this->fakeFreelancer(
            [$this->flThread(9001, 777, 1700000200)],
            [$this->flMessage(1, self::CLIENT_FL_USER_ID, 'message on a blocked thread')]
        );

        app(ThreadSyncer::class)->run();

        // The message is still stored — blocking suppresses notification only.
        $this->assertNotNull(ThreadMessage::where('freelancer_message_id', 1)->first());
        Queue::assertNotPushed(SendFcmPushJob::class);
    }

    public function test_unassigned_thread_does_not_push(): void
    {
        Queue::fake();
        $this->assignedThread(['assigned_user_id' => null]);

        $this->fakeFreelancer(
            [$this->flThread(9001, 777, 1700000200)],
            [$this->flMessage(1, self::CLIENT_FL_USER_ID, 'nobody owns this yet')]
        );

        app(ThreadSyncer::class)->run();

        Queue::assertNotPushed(SendFcmPushJob::class);
    }

    public function test_first_import_of_a_new_thread_does_not_push(): void
    {
        Queue::fake();
        Proposal::factory()->create(['project_id' => 777, 'title' => 'Build a Flutter app']);

        // A brand-new thread imports its whole history before an assignee
        // exists; the thread_assigned push is what notifies the user.
        $this->fakeFreelancer(
            [$this->flThread(9001, 777)],
            [
                $this->flMessage(1, self::CLIENT_FL_USER_ID, 'opening message', 1700000010),
                $this->flMessage(2, self::CLIENT_FL_USER_ID, 'and a follow up', 1700000020),
            ]
        );

        app(ThreadSyncer::class)->run();

        Queue::assertNotPushed(SendFcmPushJob::class);
        Queue::assertPushed(AssignThreadJob::class, 1);
    }

    public function test_a_second_pass_over_the_same_messages_does_not_repush(): void
    {
        Queue::fake();
        $this->assignedThread();

        $this->fakeFreelancer(
            [$this->flThread(9001, 777, 1700000200)],
            [$this->flMessage(1, self::CLIENT_FL_USER_ID, 'only once please')]
        );

        app(ThreadSyncer::class)->run();
        app(ThreadSyncer::class)->run();

        Queue::assertPushed(SendFcmPushJob::class, 1);
    }

    public function test_attachment_only_message_uses_a_readable_body(): void
    {
        Queue::fake();
        $this->assignedThread();

        $this->fakeFreelancer(
            [$this->flThread(9001, 777, 1700000200)],
            [$this->flMessage(1, self::CLIENT_FL_USER_ID, '', 1700000150, [
                ['id' => 42, 'filename' => 'spec.pdf'],
            ])]
        );

        app(ThreadSyncer::class)->run();

        Queue::assertPushed(SendFcmPushJob::class, fn ($job) => $job->body === '📎 Attachment');
    }

    public function test_empty_message_falls_back_to_a_generic_body(): void
    {
        Queue::fake();
        $this->assignedThread();

        $this->fakeFreelancer(
            [$this->flThread(9001, 777, 1700000200)],
            [$this->flMessage(1, self::CLIENT_FL_USER_ID, null)]
        );

        app(ThreadSyncer::class)->run();

        // An empty body would render as a blank line in the notification shade.
        Queue::assertPushed(SendFcmPushJob::class, fn ($job) => $job->body === 'New message');
    }

    public function test_title_falls_back_to_the_project_id_without_a_proposal_title(): void
    {
        Queue::fake();
        $proposal = Proposal::factory()->create(['project_id' => 777, 'title' => null]);
        $user = User::factory()->create(['role' => 'mobile']);
        Thread::factory()->create([
            'freelancer_thread_id' => 9001,
            'project_id' => 777,
            'proposal_id' => $proposal->id,
            'assigned_user_id' => $user->id,
            'freelancer_time_updated' => 1700000100,
        ]);

        $this->fakeFreelancer(
            [$this->flThread(9001, 777, 1700000200)],
            [$this->flMessage(1, self::CLIENT_FL_USER_ID, 'hello')]
        );

        app(ThreadSyncer::class)->run();

        Queue::assertPushed(SendFcmPushJob::class, fn ($job) => $job->title === 'Project 777');
    }

    public function test_messages_stay_out_of_the_alerts_list_by_default(): void
    {
        Queue::fake();
        $this->assignedThread();

        $this->fakeFreelancer(
            [$this->flThread(9001, 777, 1700000200)],
            [$this->flMessage(1, self::CLIENT_FL_USER_ID, 'hello')]
        );

        app(ThreadSyncer::class)->run();

        $this->assertSame(0, MobileNotification::count());
    }

    public function test_messages_appear_in_alerts_when_enabled(): void
    {
        Queue::fake();
        config(['push.messages_in_alerts' => true]);
        [, $user] = $this->assignedThread();

        $this->fakeFreelancer(
            [$this->flThread(9001, 777, 1700000200)],
            [$this->flMessage(1, self::CLIENT_FL_USER_ID, 'hello')]
        );

        app(ThreadSyncer::class)->run();

        $this->assertSame(1, MobileNotification::count());
        $row = MobileNotification::first();
        $this->assertSame($user->id, (int) $row->user_id);
        $this->assertSame(NotificationType::MESSAGE, $row->type);
    }

    public function test_a_client_reply_reopens_an_answered_thread(): void
    {
        Queue::fake();
        $this->assignedThread(['status' => 'answered']);

        $this->fakeFreelancer(
            [$this->flThread(9001, 777, 1700000200)],
            [$this->flMessage(1, self::CLIENT_FL_USER_ID, 'still waiting')]
        );

        app(ThreadSyncer::class)->run();

        // ThreadEscalator only scans 'fresh' threads, so without this an
        // ignored follow-up could never escalate.
        $this->assertSame('fresh', Thread::first()->status);
    }

    public function test_a_failed_message_fetch_does_not_advance_the_watermark(): void
    {
        Queue::fake();
        [$thread] = $this->assignedThread();

        Http::fake([
            'https://www.freelancer.com/api/messages/0.1/threads/*' => Http::response([
                'status' => 'success',
                'result' => ['threads' => [$this->flThread(9001, 777, 1700000200)]],
            ]),
            // Transient upstream failure — indistinguishable from "no new
            // messages" before fetchMessages() started returning null.
            'https://www.freelancer.com/api/messages/0.1/messages/*' => Http::response([], 503),
        ]);

        app(ThreadSyncer::class)->run();

        // If the watermark moved, the messages in this window would never be
        // imported, broadcast or pushed on any later pass — silently lost.
        $this->assertSame(1700000100, (int) $thread->fresh()->freelancer_time_updated);
        $this->assertSame(0, ThreadMessage::count());
        Queue::assertNotPushed(SendFcmPushJob::class);
    }

    public function test_messages_are_imported_after_a_failed_pass_recovers(): void
    {
        Queue::fake();
        $this->assignedThread();

        // Http::fake() MERGES stubs and the first match wins, so a second
        // fake() call cannot replace the 503 — sequence the two responses.
        Http::fake([
            'https://www.freelancer.com/api/messages/0.1/threads/*' => Http::response([
                'status' => 'success',
                'result' => ['threads' => [$this->flThread(9001, 777, 1700000200)]],
            ]),
            'https://www.freelancer.com/api/messages/0.1/messages/*' => Http::sequence()
                ->push([], 503)
                ->push([
                    'status' => 'success',
                    'result' => ['messages' => [$this->flMessage(1, self::CLIENT_FL_USER_ID, 'recovered message')]],
                ], 200),
        ]);

        app(ThreadSyncer::class)->run();  // upstream fails, watermark holds
        app(ThreadSyncer::class)->run();  // retries the same window, succeeds

        $this->assertSame(1, ThreadMessage::count());
        Queue::assertPushed(SendFcmPushJob::class, 1);
    }
}
