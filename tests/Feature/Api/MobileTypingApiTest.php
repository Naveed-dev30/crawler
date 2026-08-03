<?php

namespace Tests\Feature\Api;

use App\Models\Thread;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MobileTypingApiTest extends TestCase
{
    use RefreshDatabase;

    private User $me;

    private Thread $thread;

    protected function setUp(): void
    {
        parent::setUp();
        config(['variables.flBase' => 'https://www.freelancer.com', 'variables.flKey' => 'k']);
        $this->me = User::factory()->create(['role' => 'mobile']);
        $this->thread = Thread::factory()->create([
            'assigned_user_id' => $this->me->id,
            'freelancer_thread_id' => 9001,
        ]);
        Sanctum::actingAs($this->me);
    }

    public function test_typing_relays_the_signal_to_freelancer(): void
    {
        Http::fake([
            'https://www.freelancer.com/api/messages/0.1/threads/9001/typing/' => Http::response(['status' => 'success']),
        ]);

        $this->postJson("/api/v1/mobile/threads/{$this->thread->id}/typing")
            ->assertOk()
            ->assertJsonPath('success', true);

        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && $request->url() === 'https://www.freelancer.com/api/messages/0.1/threads/9001/typing/');
    }

    public function test_typing_on_a_freelancer_failure_still_succeeds(): void
    {
        // Typing is ephemeral and fire-and-forget: a Freelancer hiccup must not
        // surface as an error the client has to handle mid-compose.
        Http::fake([
            'https://www.freelancer.com/*' => Http::response('nope', 500),
        ]);

        $this->postJson("/api/v1/mobile/threads/{$this->thread->id}/typing")
            ->assertOk()
            ->assertJsonPath('success', true);
    }

    public function test_typing_requires_thread_ownership(): void
    {
        Http::fake(); // fail loudly if any outbound call is attempted
        $stranger = User::factory()->create(['role' => 'mobile']);
        Sanctum::actingAs($stranger);

        $this->postJson("/api/v1/mobile/threads/{$this->thread->id}/typing")
            ->assertForbidden();

        Http::assertNothingSent();
    }
}
