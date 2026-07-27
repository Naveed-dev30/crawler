<?php
// tests/Feature/GenerateAiReplyJobTest.php
namespace Tests\Feature;

use App\Jobs\GenerateAiReplyJob;
use App\Models\Thread;
use App\Models\ThreadMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GenerateAiReplyJobTest extends TestCase
{
    use RefreshDatabase;

    private User $me;
    private Thread $thread;
    private ThreadMessage $client;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'variables.flBase' => 'https://www.freelancer.com',
            'variables.flKey' => 'k',
            'variables.openAIKey' => 'sk-test',
        ]);
        Carbon::setTestNow('2026-07-27 10:00:00');
        $this->me = User::factory()->create([
            'role' => 'mobile', 'escalation_ladder' => 1, 'profile_prompt' => 'You are a helpful Laravel dev.',
            'ai_schedule_enabled' => true, 'ai_window_start' => '09:00:00',
            'ai_window_end' => '17:00:00', 'ai_timezone' => 'UTC',
        ]);
        $this->thread = Thread::factory()->create([
            'assigned_user_id' => $this->me->id, 'freelancer_thread_id' => 9001, 'status' => 'fresh',
        ]);
        $this->client = ThreadMessage::factory()->create([
            'thread_id' => $this->thread->id, 'direction' => 'received',
            'message' => 'Can you start today?', 'message_time' => now()->subMinute(),
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function fakeOpenAi(string $content): void
    {
        Http::fake([
            'https://api.openai.com/*' => Http::response(
                ['choices' => [['message' => ['content' => $content]]]], 200
            ),
            'https://www.freelancer.com/api/messages/0.1/threads/*/messages/*' => Http::response(
                ['result' => ['id' => 555]], 200
            ),
        ]);
    }

    public function test_generates_and_sends_ai_reply(): void
    {
        $this->fakeOpenAi('Yes, I can start today.');

        (new GenerateAiReplyJob($this->thread->id, $this->client->id))->handle(app(\App\Services\SendThreadMessage::class), app(\App\Services\AiReplyGenerator::class));

        $sent = ThreadMessage::where('thread_id', $this->thread->id)->where('direction', 'sent')->first();
        $this->assertNotNull($sent);
        $this->assertTrue($sent->sent_by_ai);
        $this->assertSame('Yes, I can start today.', $sent->message);
    }

    public function test_skips_when_ai_inactive(): void
    {
        $this->fakeOpenAi('should not send');
        $this->me->forceFill(['ai_schedule_enabled' => false])->save();

        (new GenerateAiReplyJob($this->thread->id, $this->client->id))->handle(app(\App\Services\SendThreadMessage::class), app(\App\Services\AiReplyGenerator::class));

        $this->assertSame(0, ThreadMessage::where('direction', 'sent')->count());
    }

    public function test_skips_when_human_already_replied(): void
    {
        $this->fakeOpenAi('late reply');
        ThreadMessage::factory()->create([
            'thread_id' => $this->thread->id, 'direction' => 'sent',
            'sender_user_id' => $this->me->id, 'message_time' => now(), // after client message
        ]);

        (new GenerateAiReplyJob($this->thread->id, $this->client->id))->handle(app(\App\Services\SendThreadMessage::class), app(\App\Services\AiReplyGenerator::class));

        $this->assertSame(0, ThreadMessage::where('direction', 'sent')->where('sent_by_ai', true)->count());
    }

    public function test_skips_when_blocked(): void
    {
        $this->fakeOpenAi('blocked reply');
        $this->thread->forceFill(['blocked' => true])->save();

        (new GenerateAiReplyJob($this->thread->id, $this->client->id))->handle(app(\App\Services\SendThreadMessage::class), app(\App\Services\AiReplyGenerator::class));

        $this->assertSame(0, ThreadMessage::where('sent_by_ai', true)->count());
    }
}
