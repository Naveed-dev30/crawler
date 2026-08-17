<?php

// tests/Feature/ChatReplyTest.php

namespace Tests\Feature;

use App\Models\Thread;
use App\Models\ThreadMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ChatReplyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['variables.flBase' => 'https://www.freelancer.com', 'variables.flKey' => 'k']);
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin', 'name' => 'Ops Admin']);
    }

    private function fakeSendOk(int $id = 555): void
    {
        Http::fake([
            'https://www.freelancer.com/api/messages/0.1/threads/*/messages/*' => Http::response(
                ['result' => ['id' => $id]], 200
            ),
        ]);
    }

    public function test_requires_auth(): void
    {
        $thread = Thread::factory()->create();

        $this->post("/chats/{$thread->id}/message", ['message' => 'hi'])->assertRedirect('/login');
    }

    public function test_forbidden_for_non_admin(): void
    {
        $thread = Thread::factory()->create();
        $team = User::factory()->create(['role' => 'team']);

        $this->actingAs($team)->postJson("/chats/{$thread->id}/message", ['message' => 'hi'])
            ->assertForbidden();

        $this->assertSame(0, ThreadMessage::count());
    }

    public function test_admin_reply_is_sent_and_stored_against_the_admin(): void
    {
        $this->fakeSendOk();
        $admin = $this->admin();
        $thread = Thread::factory()->create(['freelancer_thread_id' => 4242, 'status' => 'fresh']);

        $this->actingAs($admin)->postJson("/chats/{$thread->id}/message", ['message' => 'Reply from the dashboard'])
            ->assertOk()
            ->assertJson(['success' => true]);

        $message = ThreadMessage::sole();
        $this->assertSame('sent', $message->direction);
        $this->assertSame('Reply from the dashboard', $message->message);
        $this->assertSame($admin->id, (int) $message->sender_user_id);
        // A human typed it, so the AI badge must not show on the thread.
        $this->assertFalse((bool) $message->sent_by_ai);

        // Same side effects the mobile send produces.
        $this->assertSame('answered', $thread->fresh()->status);
        $this->assertNotNull($thread->fresh()->last_message_at);

        Http::assertSent(fn ($request) => str_contains($request->url(), '/threads/4242/messages/'));
    }

    /**
     * The mobile endpoint only lets the assigned agent reply. The dashboard is
     * admin-only and exists precisely so an admin can cover someone else's
     * thread, so assignment must not block the send.
     */
    public function test_admin_can_reply_to_a_thread_assigned_to_someone_else(): void
    {
        $this->fakeSendOk();
        $agent = User::factory()->create(['role' => 'mobile']);
        $thread = Thread::factory()->create(['assigned_user_id' => $agent->id]);

        $this->actingAs($this->admin())->postJson("/chats/{$thread->id}/message", ['message' => 'covering for you'])
            ->assertOk();

        $this->assertSame(1, ThreadMessage::count());
    }

    public function test_blocked_thread_is_rejected_and_nothing_is_sent(): void
    {
        Http::fake();
        $thread = Thread::factory()->create(['blocked' => true, 'block_reason' => 'Abusive client']);

        $this->actingAs($this->admin())->postJson("/chats/{$thread->id}/message", ['message' => 'hi'])
            ->assertStatus(409)
            ->assertJson(['success' => false]);

        $this->assertSame(0, ThreadMessage::count());
        Http::assertNothingSent();
    }

    public function test_empty_reply_is_rejected(): void
    {
        Http::fake();
        $thread = Thread::factory()->create();

        $this->actingAs($this->admin())->postJson("/chats/{$thread->id}/message", ['message' => ''])
            ->assertStatus(422)
            ->assertJsonValidationErrors('message');

        Http::assertNothingSent();
    }

    public function test_attachment_only_reply_is_accepted(): void
    {
        Storage::fake('public');
        $this->fakeSendOk();
        $thread = Thread::factory()->create();

        // Blank message, as the composer posts it when only files are picked.
        $this->actingAs($this->admin())->post("/chats/{$thread->id}/message", [
            'message' => '',
            'attachments' => [UploadedFile::fake()->create('scope.pdf', 12)],
        ])->assertOk();

        $message = ThreadMessage::sole();
        $this->assertNull($message->message);
        $this->assertSame('scope.pdf', $message->attachments->sole()->filename);
    }

    public function test_more_than_five_attachments_is_rejected(): void
    {
        Http::fake();
        $thread = Thread::factory()->create();

        $this->actingAs($this->admin())->postJson("/chats/{$thread->id}/message", [
            'message' => 'too many',
            'attachments' => array_fill(0, 6, UploadedFile::fake()->create('a.pdf', 1)),
        ])->assertStatus(422)->assertJsonValidationErrors('attachments');

        Http::assertNothingSent();
    }

    public function test_freelancer_rejection_surfaces_as_502_and_stores_nothing(): void
    {
        Http::fake([
            'https://www.freelancer.com/api/messages/0.1/threads/*/messages/*' => Http::response([], 500),
        ]);
        $thread = Thread::factory()->create();

        $this->actingAs($this->admin())->postJson("/chats/{$thread->id}/message", ['message' => 'hi'])
            ->assertStatus(502)
            ->assertJson(['success' => false]);

        $this->assertSame(0, ThreadMessage::count());
    }

    public function test_detail_panel_offers_the_reply_box(): void
    {
        $thread = Thread::factory()->create();

        $this->actingAs($this->admin())->get("/chats/{$thread->id}/detail")
            ->assertOk()
            ->assertSee('chat-reply-form', false)
            ->assertSee('Reply as Ops Admin');
    }

    public function test_detail_panel_hides_the_reply_box_while_blocked(): void
    {
        $thread = Thread::factory()->create(['blocked' => true]);

        $this->actingAs($this->admin())->get("/chats/{$thread->id}/detail")
            ->assertOk()
            ->assertDontSee('chat-reply-form', false)
            ->assertSee('Unblock this thread to reply.');
    }
}
