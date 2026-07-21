<?php

namespace Tests\Feature;

use App\Jobs\AssignThreadJob;
use App\Models\Proposal;
use App\Models\Thread;
use App\Models\ThreadMessage;
use App\Services\ThreadSyncer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ThreadSyncerTest extends TestCase
{
    use RefreshDatabase;

    private const OUR_FL_USER_ID = 55555;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'variables.flUserId' => self::OUR_FL_USER_ID,
            'variables.flBase' => 'https://www.freelancer.com',
            'variables.flKey' => 'test-key',
        ]);
    }

    /**
     * Shape copied from GET /api/messages/0.1/threads/ (result.threads[]).
     */
    private function flThread(int $id, int $projectId, int $timeUpdated = 1700000100): array
    {
        return [
            'id' => $id,
            'thread' => [
                'members' => [self::OUR_FL_USER_ID, 111],
                'thread_type' => 'private_chat',
                'context' => ['type' => 'project', 'id' => $projectId],
                'time_created' => 1700000000,
            ],
            'time_updated' => $timeUpdated,
        ];
    }

    /**
     * Shape copied from GET /api/messages/0.1/messages/ (result.messages[]).
     */
    private function flMessage(int $id, int $threadId, int $fromUser, string $text, int $time = 1700000050, array $attachments = []): array
    {
        return [
            'id' => $id,
            'thread_id' => $threadId,
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

    public function test_creates_thread_for_bid_project_and_imports_received_messages(): void
    {
        Queue::fake();
        $proposal = Proposal::factory()->create(['project_id' => 777]);

        $this->fakeFreelancer(
            [$this->flThread(9001, 777)],
            [
                $this->flMessage(1, 9001, 111, 'Hello, saw your bid'),
                $this->flMessage(2, 9001, self::OUR_FL_USER_ID, 'our own reply must be skipped'),
            ]
        );

        app(ThreadSyncer::class)->run();

        $thread = Thread::where('freelancer_thread_id', 9001)->first();
        $this->assertNotNull($thread);
        $this->assertSame(777, (int) $thread->project_id);
        $this->assertSame($proposal->id, (int) $thread->proposal_id);
        $this->assertSame('fresh', $thread->status);

        $this->assertSame(1, ThreadMessage::count());
        $msg = ThreadMessage::first();
        $this->assertSame('received', $msg->direction);
        $this->assertSame('Hello, saw your bid', $msg->message);
        $this->assertSame(111, (int) $msg->from_freelancer_user_id);

        Queue::assertPushed(AssignThreadJob::class, 1);
    }

    public function test_ignores_threads_for_projects_we_did_not_bid_on(): void
    {
        Queue::fake();
        $this->fakeFreelancer([$this->flThread(9002, 12345)], []);

        app(ThreadSyncer::class)->run();

        $this->assertSame(0, Thread::count());
        Queue::assertNothingPushed();
    }

    public function test_known_thread_is_not_recreated_and_new_messages_are_imported(): void
    {
        Queue::fake();
        $proposal = Proposal::factory()->create(['project_id' => 777]);
        $thread = Thread::factory()->create([
            'freelancer_thread_id' => 9001,
            'project_id' => 777,
            'proposal_id' => $proposal->id,
            'freelancer_time_updated' => 1700000100,
        ]);
        ThreadMessage::factory()->create([
            'thread_id' => $thread->id,
            'freelancer_message_id' => 1,
        ]);

        $this->fakeFreelancer(
            [$this->flThread(9001, 777, 1700000200)],
            [
                $this->flMessage(1, 9001, 111, 'already imported'),
                $this->flMessage(3, 9001, 111, 'new client message', 1700000150),
            ]
        );

        app(ThreadSyncer::class)->run();

        $this->assertSame(1, Thread::count());
        $this->assertSame(2, ThreadMessage::count());
        $this->assertNotNull(ThreadMessage::where('freelancer_message_id', 3)->first());
        $this->assertSame(1700000200, (int) $thread->fresh()->freelancer_time_updated);
        Queue::assertNothingPushed(); // assignment only for new threads
    }

    public function test_unchanged_known_thread_skips_message_fetch(): void
    {
        Queue::fake();
        $proposal = Proposal::factory()->create(['project_id' => 777]);
        Thread::factory()->create([
            'freelancer_thread_id' => 9001,
            'project_id' => 777,
            'proposal_id' => $proposal->id,
            'freelancer_time_updated' => 1700000100,
        ]);

        $this->fakeFreelancer([$this->flThread(9001, 777, 1700000100)], []);

        app(ThreadSyncer::class)->run();

        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/messages/0.1/messages'));
    }

    public function test_attachment_metadata_is_stored_with_freelancer_url(): void
    {
        Queue::fake();
        Proposal::factory()->create(['project_id' => 777]);

        $this->fakeFreelancer(
            [$this->flThread(9001, 777)],
            [
                $this->flMessage(5, 9001, 111, 'see attached', 1700000050, [
                    ['id' => 42, 'filename' => 'spec.pdf'],
                ]),
            ]
        );

        app(ThreadSyncer::class)->run();

        $msg = ThreadMessage::where('freelancer_message_id', 5)->first();
        $this->assertNotNull($msg);
        $this->assertSame(1, $msg->attachments()->count());
        $attachment = $msg->attachments->first();
        $this->assertSame('spec.pdf', $attachment->filename);
        $this->assertStringContainsString('spec.pdf', $attachment->url);
    }

    public function test_blocked_thread_still_receives_new_messages(): void
    {
        Queue::fake();
        $proposal = Proposal::factory()->create(['project_id' => 777]);
        Thread::factory()->create([
            'freelancer_thread_id' => 9001,
            'project_id' => 777,
            'proposal_id' => $proposal->id,
            'blocked' => true,
            'freelancer_time_updated' => 1700000100,
        ]);

        $this->fakeFreelancer(
            [$this->flThread(9001, 777, 1700000200)],
            [$this->flMessage(7, 9001, 111, 'message on blocked thread', 1700000150)]
        );

        app(ThreadSyncer::class)->run();

        $this->assertNotNull(ThreadMessage::where('freelancer_message_id', 7)->first());
    }
}
