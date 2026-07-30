<?php

// tests/Feature/SendThreadMessageTest.php

namespace Tests\Feature;

use App\Events\ThreadMessageCreated;
use App\Models\Thread;
use App\Models\User;
use App\Services\SendThreadMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SendThreadMessageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['variables.flBase' => 'https://www.freelancer.com', 'variables.flKey' => 'k']);
    }

    private function fakeSendOk(int $id = 777): void
    {
        Http::fake([
            'https://www.freelancer.com/api/messages/0.1/threads/*/messages/*' => Http::response(
                ['result' => ['id' => $id]], 200
            ),
        ]);
    }

    public function test_ai_message_stored_with_flag_and_status_flips(): void
    {
        Event::fake([ThreadMessageCreated::class]);
        $this->fakeSendOk();
        $thread = Thread::factory()->create(['freelancer_thread_id' => 9001, 'status' => 'fresh']);

        $msg = app(SendThreadMessage::class)->send($thread, 'hi from AI', [], null, true);

        $this->assertNotNull($msg);
        $this->assertTrue($msg->sent_by_ai);
        $this->assertSame('sent', $msg->direction);
        $this->assertSame('answered', $thread->fresh()->status);
        Event::assertDispatched(ThreadMessageCreated::class);
    }

    public function test_human_message_defaults_flag_false(): void
    {
        $this->fakeSendOk();
        $user = User::factory()->create(['role' => 'mobile']);
        $thread = Thread::factory()->create(['freelancer_thread_id' => 9002]);

        $msg = app(SendThreadMessage::class)->send($thread, 'manual', [], $user->id, false);

        $this->assertFalse($msg->sent_by_ai);
        $this->assertSame($user->id, (int) $msg->sender_user_id);
    }

    public function test_returns_null_when_messenger_rejects(): void
    {
        Http::fake([
            'https://www.freelancer.com/api/messages/0.1/threads/*/messages/*' => Http::response([], 500),
        ]);
        $thread = Thread::factory()->create(['freelancer_thread_id' => 9003]);

        $this->assertNull(app(SendThreadMessage::class)->send($thread, 'x', [], null, true));
    }
}
