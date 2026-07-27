<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\FcmPusher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Kreait\Firebase\Contract\Messaging;
use Mockery;
use Tests\TestCase;

class FcmPusherTest extends TestCase
{
    use RefreshDatabase;

    private function messagingMock(): Messaging
    {
        return Mockery::mock(Messaging::class);
    }

    public function test_skips_when_user_has_no_token(): void
    {
        $user = User::factory()->create(['role' => 'mobile', 'fcm_token' => null]);
        $messaging = $this->messagingMock();
        $messaging->shouldNotReceive('send');

        $pusher = new FcmPusher($messaging);

        $this->assertFalse($pusher->sendToUser($user, 'T', 'B'));
    }

    public function test_sends_when_user_has_token(): void
    {
        $user = User::factory()->create(['role' => 'mobile', 'fcm_token' => 'tok-123']);
        $messaging = $this->messagingMock();
        $messaging->shouldReceive('send')->once()->andReturn([]);

        $pusher = new FcmPusher($messaging);

        $this->assertTrue($pusher->sendToUser($user, 'T', 'B', ['thread_id' => 9]));
    }

    public function test_clears_token_on_dead_token_error(): void
    {
        $user = User::factory()->create(['role' => 'mobile', 'fcm_token' => 'dead-tok']);
        $messaging = $this->messagingMock();
        $messaging->shouldReceive('send')->once()
            ->andThrow(new \RuntimeException('The registration token is not a valid FCM registration token'));

        $pusher = new FcmPusher($messaging);

        $this->assertFalse($pusher->sendToUser($user, 'T', 'B'));
        $this->assertNull($user->fresh()->fcm_token, 'dead token should be cleared');
    }

    public function test_keeps_token_on_senderid_mismatch(): void
    {
        // SenderId mismatch is a project-config problem, not a dead token —
        // the token must survive so it still works once config is fixed.
        $user = User::factory()->create(['role' => 'mobile', 'fcm_token' => 'good-tok']);
        $messaging = $this->messagingMock();
        $messaging->shouldReceive('send')->once()
            ->andThrow(new \RuntimeException('SenderId mismatch'));

        $pusher = new FcmPusher($messaging);

        $this->assertFalse($pusher->sendToUser($user, 'T', 'B'));
        $this->assertSame('good-tok', $user->fresh()->fcm_token, 'token must not be cleared on config errors');
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
