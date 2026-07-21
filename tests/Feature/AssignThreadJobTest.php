<?php

namespace Tests\Feature;

use App\Jobs\AssignThreadJob;
use App\Jobs\SendFcmPushJob;
use App\Models\MobileNotification;
use App\Models\Thread;
use App\Models\ThreadMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class AssignThreadJobTest extends TestCase
{
    use RefreshDatabase;

    private function mobileUser(int $ladder, string $prompt = 'generalist'): User
    {
        return User::factory()->create([
            'role' => 'mobile',
            'escalation_ladder' => $ladder,
            'profile_prompt' => $prompt,
            'fcm_token' => "token-{$ladder}",
        ]);
    }

    private function threadWithMessage(): Thread
    {
        $thread = Thread::factory()->create();
        ThreadMessage::factory()->create([
            'thread_id' => $thread->id,
            'direction' => 'received',
            'message' => 'Hi, is this something you can do?',
        ]);

        return $thread;
    }

    public function test_matched_user_is_assigned_notified_and_pushed(): void
    {
        Queue::fake();
        $flutterDev = $this->mobileUser(2, 'Flutter expert');
        $this->mobileUser(1, 'Laravel expert');

        Http::fake([
            'https://api.openai.com/*' => Http::response([
                'choices' => [['message' => ['content' => '{"user_id": ' . $flutterDev->id . '}']]],
            ]),
        ]);

        $thread = $this->threadWithMessage();

        app()->call([new AssignThreadJob($thread->id), 'handle']);

        $this->assertSame($flutterDev->id, (int) $thread->fresh()->assigned_user_id);

        $notification = MobileNotification::where('user_id', $flutterDev->id)->first();
        $this->assertNotNull($notification);
        $this->assertSame($thread->id, (int) $notification->thread_id);

        Queue::assertPushed(SendFcmPushJob::class, fn ($job) => $job->userId === $flutterDev->id);
    }

    public function test_matcher_failure_falls_back_to_ladder_one_user(): void
    {
        Queue::fake();
        $first = $this->mobileUser(1);
        $this->mobileUser(2);

        Http::fake(['https://api.openai.com/*' => Http::response('boom', 500)]);

        $thread = $this->threadWithMessage();

        app()->call([new AssignThreadJob($thread->id), 'handle']);

        $this->assertSame($first->id, (int) $thread->fresh()->assigned_user_id);
        Queue::assertPushed(SendFcmPushJob::class, 1);
    }

    public function test_no_mobile_users_leaves_thread_unassigned(): void
    {
        Queue::fake();
        Http::fake();

        $thread = $this->threadWithMessage();

        app()->call([new AssignThreadJob($thread->id), 'handle']);

        $this->assertNull($thread->fresh()->assigned_user_id);
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }
}
