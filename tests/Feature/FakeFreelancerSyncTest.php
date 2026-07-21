<?php

namespace Tests\Feature;

use App\Jobs\AssignThreadJob;
use App\Models\Proposal;
use App\Models\Thread;
use App\Models\ThreadMessage;
use App\Services\Fake\FakeFreelancerMessenger;
use App\Services\FreelancerMessenger;
use App\Services\ThreadSyncer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class FakeFreelancerSyncTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config(['variables.flFake' => true, 'variables.flUserId' => 55555]);
        // Re-bind now that the flag is on (provider ran before config change).
        $this->app->bind(FreelancerMessenger::class, FakeFreelancerMessenger::class);
    }

    public function test_fake_messenger_is_bound_when_flag_enabled(): void
    {
        $this->assertInstanceOf(FakeFreelancerMessenger::class, app(FreelancerMessenger::class));
    }

    public function test_sync_creates_threads_and_messages_without_any_http(): void
    {
        Queue::fake();
        Http::fake();

        $proposals = Proposal::factory()->count(3)->create();

        app(ThreadSyncer::class)->run();

        Http::assertNothingSent();
        $this->assertSame(3, Thread::count());
        $this->assertGreaterThan(0, ThreadMessage::count());

        $projectIds = Thread::pluck('project_id')->map(fn ($id) => (int) $id)->sort()->values()->all();
        $this->assertSame(
            $proposals->pluck('project_id')->map(fn ($id) => (int) $id)->sort()->values()->all(),
            $projectIds
        );

        // Every stored message is a client message.
        $this->assertSame(0, ThreadMessage::where('direction', '!=', 'received')->count());
        Queue::assertPushed(AssignThreadJob::class, 3);
    }

    public function test_repeat_sync_is_idempotent_for_unchanged_threads(): void
    {
        Queue::fake();
        Proposal::factory()->create();

        app(ThreadSyncer::class)->run();
        $countAfterFirst = ThreadMessage::count();

        app(ThreadSyncer::class)->run();

        $this->assertSame(1, Thread::count());
        $this->assertSame($countAfterFirst, ThreadMessage::count());
    }

    public function test_send_message_succeeds_without_http(): void
    {
        Http::fake();

        $result = app(FreelancerMessenger::class)->sendMessage(900001, 'hello there');

        Http::assertNothingSent();
        $this->assertIsArray($result);
        $this->assertArrayHasKey('id', $result);
    }
}
