<?php
// tests/Feature/MarkThreadReadJobTest.php

namespace Tests\Feature;

use App\Jobs\MarkThreadReadJob;
use App\Models\Thread;
use App\Models\ThreadMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MarkThreadReadJobTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['variables.flBase' => 'https://www.freelancer.com', 'variables.flKey' => 'k']);
    }

    public function test_marks_thread_read_on_freelancer_and_locally(): void
    {
        Http::fake([
            'https://www.freelancer.com/api/messages/0.1/threads/9001/*' => Http::response(['status' => 'success']),
        ]);

        $thread = Thread::factory()->create(['freelancer_thread_id' => 9001]);
        $unreadClient = ThreadMessage::factory()->create([
            'thread_id' => $thread->id,
            'direction' => 'received',
            'is_read' => false,
        ]);
        $sent = ThreadMessage::factory()->create([
            'thread_id' => $thread->id,
            'direction' => 'sent',
            'is_read' => null,
        ]);

        (new MarkThreadReadJob($thread->id))->handle(app(\App\Services\FreelancerMessenger::class));

        Http::assertSent(fn ($request) => $request->method() === 'PUT'
            && str_contains($request->url(), '/messages/0.1/threads/9001/'));

        $this->assertTrue($unreadClient->fresh()->is_read);
        $this->assertNull($sent->fresh()->is_read); // outbound untouched
    }

    public function test_freelancer_failure_leaves_local_state_untouched(): void
    {
        Http::fake([
            'https://www.freelancer.com/api/messages/0.1/threads/9001/*' => Http::response([], 500),
        ]);

        $thread = Thread::factory()->create(['freelancer_thread_id' => 9001]);
        $unread = ThreadMessage::factory()->create([
            'thread_id' => $thread->id,
            'direction' => 'received',
            'is_read' => false,
        ]);

        (new MarkThreadReadJob($thread->id))->handle(app(\App\Services\FreelancerMessenger::class));

        $this->assertFalse($unread->fresh()->is_read);
    }

    public function test_missing_thread_is_a_noop(): void
    {
        Http::fake();
        (new MarkThreadReadJob(999999))->handle(app(\App\Services\FreelancerMessenger::class));
        Http::assertNothingSent();
    }
}
