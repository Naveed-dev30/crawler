<?php

namespace Tests\Feature\Api;

use App\Models\Thread;
use App\Models\ThreadMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
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

    public function test_lists_messages_in_chronological_order(): void
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

        $this->assertSame(['first', 'second'], collect($response->json('data'))->pluck('message')->all());
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

        [$colleagueMsg, $ownerMsg, $clientMsg, $mineMsg] = $data;

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
