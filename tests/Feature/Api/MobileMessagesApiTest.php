<?php

namespace Tests\Feature\Api;

use App\Jobs\MarkThreadReadJob;
use App\Models\Thread;
use App\Models\ThreadMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MobileMessagesApiTest extends TestCase
{
    use RefreshDatabase;

    private User $me;

    private Thread $thread;

    protected function setUp(): void
    {
        parent::setUp();
        config(['variables.flBase' => 'https://www.freelancer.com', 'variables.flKey' => 'k']);
        $this->me = User::factory()->create(['role' => 'mobile', 'escalation_ladder' => 1]);
        $this->thread = Thread::factory()->create([
            'assigned_user_id' => $this->me->id,
            'freelancer_thread_id' => 9001,
            'status' => 'fresh',
        ]);
        Sanctum::actingAs($this->me);
    }

    public function test_lists_the_newest_messages_first(): void
    {
        ThreadMessage::factory()->create([
            'thread_id' => $this->thread->id,
            'message' => 'second',
            'message_time' => now(),
        ]);
        ThreadMessage::factory()->create([
            'thread_id' => $this->thread->id,
            'message' => 'first',
            'message_time' => now()->subMinute(),
        ]);

        $response = $this->getJson("/api/v1/mobile/threads/{$this->thread->id}/messages")->assertOk();

        // Page 1 is the END of the conversation; the client reverses it for
        // display and prepends older pages.
        $this->assertSame(['second', 'first'], collect($response->json('data'))->pluck('message')->all());
    }

    public function test_page_one_holds_the_most_recent_messages(): void
    {
        // The bug this replaces: with ascending order, page 1 was the OLDEST
        // 200, so a long thread opened at its beginning.
        for ($i = 1; $i <= 205; $i++) {
            ThreadMessage::factory()->create([
                'thread_id' => $this->thread->id,
                'message' => "msg {$i}",
                'message_time' => now()->subMinutes(300 - $i),
            ]);
        }

        $page1 = $this->getJson("/api/v1/mobile/threads/{$this->thread->id}/messages")->assertOk();
        $messages = collect($page1->json('data'))->pluck('message');

        $this->assertCount(200, $messages);
        $this->assertSame('msg 205', $messages->first());
        $this->assertTrue($messages->contains('msg 6'));
        $this->assertFalse($messages->contains('msg 5'), 'the oldest messages belong on page 2');

        $page2 = $this->getJson("/api/v1/mobile/threads/{$this->thread->id}/messages?page=2")->assertOk();
        $older = collect($page2->json('data'))->pluck('message');

        $this->assertSame(['msg 5', 'msg 4', 'msg 3', 'msg 2', 'msg 1'], $older->all());
    }

    public function test_messages_sharing_a_timestamp_do_not_straddle_pages(): void
    {
        // Imported messages can share a message_time; without the id tie-break
        // the sort is unstable and a row can repeat or vanish across pages.
        $shared = now()->subMinute();
        for ($i = 1; $i <= 205; $i++) {
            ThreadMessage::factory()->create([
                'thread_id' => $this->thread->id,
                'message' => "msg {$i}",
                'message_time' => $shared,
            ]);
        }

        $page1 = collect(
            $this->getJson("/api/v1/mobile/threads/{$this->thread->id}/messages")->json('data')
        )->pluck('id');
        $page2 = collect(
            $this->getJson("/api/v1/mobile/threads/{$this->thread->id}/messages?page=2")->json('data')
        )->pluck('id');

        $this->assertCount(205, $page1->merge($page2)->unique());
    }

    public function test_opening_messages_queues_mark_thread_read(): void
    {
        Queue::fake();

        $this->getJson("/api/v1/mobile/threads/{$this->thread->id}/messages")->assertOk();

        Queue::assertPushed(
            MarkThreadReadJob::class,
            fn ($job) => $job->threadId === $this->thread->id
        );
    }

    public function test_message_payload_carries_sender_and_state_tags(): void
    {
        $colleague = User::factory()->create(['role' => 'mobile', 'name' => 'Old Assignee']);
        ThreadMessage::factory()->create([
            'thread_id' => $this->thread->id,
            'direction' => 'sent',
            'sender_user_id' => $colleague->id,
            'freelancer_message_id' => 11,
            'message' => 'sent by previous assignee',
            'message_time' => now()->subMinutes(3),
        ]);
        ThreadMessage::factory()->create([
            'thread_id' => $this->thread->id,
            'direction' => 'sent',
            'sender_user_id' => null,
            'freelancer_message_id' => 12,
            'message' => 'sent from freelancer.com',
            'message_time' => now()->subMinutes(2),
        ]);
        ThreadMessage::factory()->create([
            'thread_id' => $this->thread->id,
            'direction' => 'received',
            'freelancer_message_id' => 13,
            'message' => 'client message',
            'message_time' => now()->subMinute(),
            'is_read' => false,
        ]);
        ThreadMessage::factory()->create([
            'thread_id' => $this->thread->id,
            'direction' => 'sent',
            'sender_user_id' => $this->me->id,
            'freelancer_message_id' => 14,
            'message' => 'my own message',
            'message_time' => now(),
        ]);

        $data = $this->getJson("/api/v1/mobile/threads/{$this->thread->id}/messages")
            ->assertOk()
            ->json('data');

        // The endpoint returns newest first.
        [$mineMsg, $clientMsg, $ownerMsg, $colleagueMsg] = $data;

        $this->assertTrue($mineMsg['is_mine']);
        $this->assertSame($this->me->name, $mineMsg['sender_name']);

        $this->assertSame('Old Assignee', $colleagueMsg['sender_name']);
        $this->assertFalse($colleagueMsg['is_mine']);
        $this->assertTrue($colleagueMsg['is_sent']);

        $this->assertSame('Owner', $ownerMsg['sender_name']);
        $this->assertFalse($ownerMsg['is_mine']);
        $this->assertTrue($ownerMsg['is_sent']);

        $this->assertNull($clientMsg['sender_name']);
        $this->assertFalse($clientMsg['is_mine']);
        $this->assertFalse($clientMsg['is_read']);
    }

    public function test_send_message_relays_to_freelancer_and_marks_answered(): void
    {
        Http::fake([
            'https://www.freelancer.com/api/messages/0.1/threads/9001/messages/*' => Http::response([
                'status' => 'success',
                'result' => ['id' => 777],
            ]),
        ]);

        $this->postJson("/api/v1/mobile/threads/{$this->thread->id}/messages", [
            'message' => 'On it, will deliver Friday',
        ])->assertCreated()->assertJsonPath('success', true);

        $stored = ThreadMessage::where('direction', 'sent')->first();
        $this->assertNotNull($stored);
        $this->assertSame('On it, will deliver Friday', $stored->message);
        $this->assertSame($this->me->id, (int) $stored->sender_user_id);
        $this->assertSame(777, (int) $stored->freelancer_message_id);
        $this->assertSame('answered', $this->thread->fresh()->status);
    }

    public function test_cannot_send_on_a_blocked_thread(): void
    {
        Http::fake(); // fail loudly if any outbound send is attempted
        $this->thread->update(['blocked' => true, 'block_reason' => 'Spam']);

        $this->postJson("/api/v1/mobile/threads/{$this->thread->id}/messages", [
            'message' => 'should not go out',
        ])->assertStatus(409)->assertJsonPath('success', false);

        $this->assertSame(0, ThreadMessage::where('direction', 'sent')->count());
        Http::assertNothingSent();
    }

    public function test_freelancer_failure_returns_502_and_stores_nothing(): void
    {
        Http::fake([
            'https://www.freelancer.com/*' => Http::response('nope', 500),
        ]);

        $this->postJson("/api/v1/mobile/threads/{$this->thread->id}/messages", [
            'message' => 'hello',
        ])->assertStatus(502)->assertJsonPath('success', false);

        $this->assertSame(0, ThreadMessage::count());
        $this->assertSame('fresh', $this->thread->fresh()->status);
    }

    public function test_message_or_attachment_required(): void
    {
        $this->postJson("/api/v1/mobile/threads/{$this->thread->id}/messages", [])
            ->assertUnprocessable();
    }

    public function test_send_attachment_stores_metadata(): void
    {
        Http::fake([
            'https://www.freelancer.com/api/messages/0.1/threads/9001/messages/*' => Http::response([
                'status' => 'success',
                'result' => ['id' => 778],
            ]),
        ]);

        $this->post("/api/v1/mobile/threads/{$this->thread->id}/messages", [
            'attachments' => [UploadedFile::fake()->create('spec.pdf', 100, 'application/pdf')],
        ], ['Accept' => 'application/json'])->assertCreated();

        $stored = ThreadMessage::where('direction', 'sent')->first();
        $this->assertNotNull($stored);
        $this->assertSame(1, $stored->attachments()->count());
        $this->assertSame('spec.pdf', $stored->attachments->first()->filename);
    }
}
