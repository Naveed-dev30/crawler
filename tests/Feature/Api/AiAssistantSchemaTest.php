<?php

// tests/Feature/Api/AiAssistantSchemaTest.php

namespace Tests\Feature\Api;

use App\Models\Thread;
use App\Models\ThreadMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class AiAssistantSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_persists_ai_columns_with_casts(): void
    {
        $user = User::factory()->create([
            'role' => 'mobile',
            'ai_schedule_enabled' => true,
            'ai_window_start' => '00:00:00',
            'ai_window_end' => '17:00:00',
            'ai_timezone' => 'Asia/Karachi',
            'ai_manual_state' => false,
            'ai_manual_until' => now()->addHours(3),
        ])->fresh();

        $this->assertTrue($user->ai_schedule_enabled);
        $this->assertFalse($user->ai_manual_state);
        $this->assertInstanceOf(Carbon::class, $user->ai_manual_until);
        $this->assertSame('Asia/Karachi', $user->ai_timezone);
    }

    public function test_thread_message_has_sent_by_ai_flag(): void
    {
        $thread = Thread::factory()->create();
        $msg = ThreadMessage::factory()->create([
            'thread_id' => $thread->id,
            'sent_by_ai' => true,
        ])->fresh();

        $this->assertTrue($msg->sent_by_ai);
    }

    public function test_sent_by_ai_defaults_false(): void
    {
        $thread = Thread::factory()->create();
        $msg = ThreadMessage::factory()->create(['thread_id' => $thread->id])->fresh();

        $this->assertFalse($msg->sent_by_ai);
    }
}
