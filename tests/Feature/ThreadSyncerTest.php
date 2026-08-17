<?php

namespace Tests\Feature;

use App\Jobs\AssignThreadJob;
use App\Models\BidInsight;
use App\Models\Proposal;
use App\Models\Thread;
use App\Models\ThreadMessage;
use App\Models\User;
use App\Services\ThreadSyncer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
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

    /**
     * The thread's members list is the only durable record of which Freelancer
     * user we are talking to — the projects API stops returning owner details
     * once a project closes — so capture it on sync.
     */
    public function test_captures_the_clients_freelancer_id_from_thread_members(): void
    {
        Queue::fake();
        $proposal = Proposal::factory()->create(['project_id' => 900]);
        $this->fakeFreelancer([$this->flThread(70, 900)], []);

        app(ThreadSyncer::class)->run();

        $thread = Thread::where('freelancer_thread_id', 70)->sole();
        $this->assertSame($proposal->id, (int) $thread->proposal_id);
        // 111 is the member that is not us.
        $this->assertSame(111, (int) $thread->client_user_id);
    }

    public function test_existing_thread_gets_its_client_id_filled_in_on_next_sync(): void
    {
        Queue::fake();
        $proposal = Proposal::factory()->create(['project_id' => 901]);
        $thread = Thread::factory()->create([
            'freelancer_thread_id' => 71,
            'project_id' => 901,
            'proposal_id' => $proposal->id,
            'client_user_id' => null,
            'freelancer_time_updated' => 1700000100,
        ]);

        $this->fakeFreelancer([$this->flThread(71, 901)], []);

        app(ThreadSyncer::class)->run();

        $this->assertSame(111, (int) $thread->fresh()->client_user_id);
    }

    /**
     * The whole point of capturing the client id: a sync pass should leave the
     * chat able to name the person, which needs the users endpoint because
     * projects/active never returns a name.
     */
    public function test_sync_resolves_the_client_name_from_the_users_endpoint(): void
    {
        Queue::fake();
        Cache::flush();
        $proposal = Proposal::factory()->create(['project_id' => 902]);

        Http::fake([
            'https://www.freelancer.com/api/messages/0.1/threads/*' => Http::response([
                'status' => 'success',
                'result' => ['threads' => [$this->flThread(72, 902)]],
            ]),
            'https://www.freelancer.com/api/messages/0.1/messages/*' => Http::response([
                'status' => 'success', 'result' => ['messages' => []],
            ]),
            'https://www.freelancer.com/api/users/0.1/users*' => Http::response([
                'result' => ['users' => ['111' => [
                    'id' => 111, 'username' => 'afk513', 'display_name' => 'MisterJ',
                    'avatar_cdn' => '//cdn2.f-cdn.com/ppic/a.jpg',
                ]]],
            ]),
        ]);

        app(ThreadSyncer::class)->run();

        $insight = BidInsight::where('project_id', 902)->sole();
        $this->assertSame('MisterJ', $insight->client_name);
        $this->assertSame('afk513', $insight->client_username);
        $this->assertSame('https://cdn2.f-cdn.com/ppic/a.jpg', $insight->client_avatar);
    }

    public function test_sync_does_not_re_look_up_a_client_it_already_named(): void
    {
        Queue::fake();
        Cache::flush();
        $proposal = Proposal::factory()->create(['project_id' => 903]);
        BidInsight::create([
            'project_id' => 903, 'client_name' => 'Already Known', 'last_scraped_at' => now(),
        ]);

        $this->fakeFreelancer([$this->flThread(73, 903)], []);

        app(ThreadSyncer::class)->run();

        // fakeFreelancer registers no users route: hitting it would fail the run.
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'users/0.1/users'));
        $this->assertSame('Already Known', BidInsight::where('project_id', 903)->sole()->client_name);
    }

    /**
     * Deleted accounts resolve to nothing. A pass runs every ten seconds, so
     * without a cooldown we would ask for the same dead id forever.
     */
    public function test_unresolvable_client_is_not_retried_next_pass(): void
    {
        Queue::fake();
        Cache::flush();
        Proposal::factory()->create(['project_id' => 904]);

        Http::fake([
            'https://www.freelancer.com/api/messages/0.1/threads/*' => Http::response([
                'status' => 'success',
                'result' => ['threads' => [$this->flThread(74, 904)]],
            ]),
            'https://www.freelancer.com/api/messages/0.1/messages/*' => Http::response([
                'status' => 'success', 'result' => ['messages' => []],
            ]),
            'https://www.freelancer.com/api/users/0.1/users*' => Http::response(['result' => ['users' => []]]),
        ]);

        app(ThreadSyncer::class)->run();
        $first = count(Http::recorded(fn ($r) => str_contains($r->url(), 'users/0.1/users')));

        app(ThreadSyncer::class)->run();
        $second = count(Http::recorded(fn ($r) => str_contains($r->url(), 'users/0.1/users')));

        $this->assertSame(1, $first, 'first pass should try once');
        $this->assertSame(1, $second, 'second pass should not try again');
    }

    public function test_creates_thread_for_bid_project_and_imports_received_messages(): void
    {
        Queue::fake();
        $proposal = Proposal::factory()->create(['project_id' => 777]);

        $this->fakeFreelancer(
            [$this->flThread(9001, 777)],
            [
                $this->flMessage(1, 9001, 111, 'Hello, saw your bid'),
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

    public function test_owner_message_from_freelancer_web_is_imported_as_sent(): void
    {
        Queue::fake();
        Proposal::factory()->create(['project_id' => 777]);

        $this->fakeFreelancer(
            [$this->flThread(9001, 777)],
            [
                $this->flMessage(1, 9001, 111, 'Hello, saw your bid', 1700000050),
                $this->flMessage(2, 9001, self::OUR_FL_USER_ID, 'reply typed on freelancer.com', 1700000060),
            ]
        );

        app(ThreadSyncer::class)->run();

        $this->assertSame(2, ThreadMessage::count());
        $owner = ThreadMessage::where('freelancer_message_id', 2)->first();
        $this->assertSame('sent', $owner->direction);
        $this->assertNull($owner->sender_user_id); // no app user — sent by the profile owner
        $this->assertSame(self::OUR_FL_USER_ID, (int) $owner->from_freelancer_user_id);

        // Status stays fresh: only app replies by mobile users answer a thread.
        $thread = Thread::where('freelancer_thread_id', 9001)->first();
        $this->assertSame('fresh', $thread->status);
        // Owner messages never move the client-message clock.
        $this->assertSame(1700000050, $thread->last_client_message_at->timestamp);
    }

    public function test_app_sent_message_is_not_duplicated_by_sync(): void
    {
        Queue::fake();
        $proposal = Proposal::factory()->create(['project_id' => 777]);
        $thread = Thread::factory()->create([
            'freelancer_thread_id' => 9001,
            'project_id' => 777,
            'proposal_id' => $proposal->id,
            'freelancer_time_updated' => 1700000100,
        ]);
        $appUser = User::factory()->create(['role' => 'mobile']);
        ThreadMessage::factory()->create([
            'thread_id' => $thread->id,
            'freelancer_message_id' => 88,
            'direction' => 'sent',
            'sender_user_id' => $appUser->id,
        ]);

        $this->fakeFreelancer(
            [$this->flThread(9001, 777, 1700000200)],
            [$this->flMessage(88, 9001, self::OUR_FL_USER_ID, 'sent from app earlier', 1700000150)]
        );

        app(ThreadSyncer::class)->run();

        $this->assertSame(1, ThreadMessage::count());
        // Attribution to the app user must survive the sync pass.
        $this->assertSame($appUser->id, (int) ThreadMessage::first()->sender_user_id);
    }

    public function test_is_read_is_stored_on_import(): void
    {
        Queue::fake();
        Proposal::factory()->create(['project_id' => 777]);

        $unread = $this->flMessage(1, 9001, 111, 'client message');
        $unread['is_read'] = false;
        $this->fakeFreelancer([$this->flThread(9001, 777)], [$unread]);
        app(ThreadSyncer::class)->run();

        $this->assertFalse(ThreadMessage::where('freelancer_message_id', 1)->first()->is_read);
    }

    public function test_is_read_is_updated_for_already_imported_message(): void
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
            'direction' => 'received',
            'is_read' => false,
        ]);

        $read = $this->flMessage(1, 9001, 111, 'client message');
        $read['is_read'] = true;
        $this->fakeFreelancer([$this->flThread(9001, 777, 1700000300)], [$read]);
        app(ThreadSyncer::class)->run();

        $this->assertSame(1, ThreadMessage::count());
        $this->assertTrue(ThreadMessage::where('freelancer_message_id', 1)->first()->is_read);
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
        Queue::assertNotPushed(AssignThreadJob::class); // assignment only for new threads
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
