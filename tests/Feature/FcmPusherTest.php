<?php

namespace Tests\Feature;

use App\Models\DeviceToken;
use App\Models\User;
use App\Services\FcmPusher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Kreait\Firebase\Contract\Messaging;
use Kreait\Firebase\Messaging\Message;
use Mockery;
use Tests\TestCase;

class FcmPusherTest extends TestCase
{
    use RefreshDatabase;

    private function messagingMock(): Messaging
    {
        return Mockery::mock(Messaging::class);
    }

    private function mobileUser(): User
    {
        return User::factory()->mobile()->create();
    }

    /**
     * Captures the serialized FCM message so the per-device Android/APNs
     * stamping can be asserted on the actual payload rather than a mock call.
     */
    private function captureSent(Messaging $messaging, array &$sink, int $times = 1): void
    {
        $messaging->shouldReceive('send')
            ->times($times)
            ->with(Mockery::on(function ($message) use (&$sink) {
                $sink[] = $message instanceof Message
                    ? json_decode(json_encode($message), true)
                    : $message;

                return true;
            }))
            ->andReturn([]);
    }

    public function test_skips_when_the_user_has_no_devices(): void
    {
        $user = $this->mobileUser();
        $messaging = $this->messagingMock();
        $messaging->shouldNotReceive('send');

        $this->assertSame(0, (new FcmPusher($messaging))->sendToUser($user, 'T', 'B'));
    }

    public function test_sends_one_message_per_device(): void
    {
        $user = $this->mobileUser();
        DeviceToken::factory()->count(3)->create(['user_id' => $user->id]);

        $messaging = $this->messagingMock();
        $messaging->shouldReceive('send')->times(3)->andReturn([]);

        $this->assertSame(3, (new FcmPusher($messaging))->sendToUser($user, 'T', 'B', ['thread_id' => 9]));
    }

    public function test_default_sound_uses_the_base_channel_and_default_apns_sound(): void
    {
        $user = $this->mobileUser();
        DeviceToken::factory()->create(['user_id' => $user->id, 'sound_key' => 'default']);

        $messaging = $this->messagingMock();
        $sent = [];
        $this->captureSent($messaging, $sent);

        (new FcmPusher($messaging))->sendToUser($user, 'T', 'B');

        $this->assertSame('alladin_notifications', $sent[0]['android']['notification']['channel_id']);
        $this->assertSame('default', $sent[0]['apns']['payload']['aps']['sound']);
    }

    public function test_chime_sound_stamps_the_chime_channel_and_wav(): void
    {
        $user = $this->mobileUser();
        DeviceToken::factory()->chime()->create(['user_id' => $user->id]);

        $messaging = $this->messagingMock();
        $sent = [];
        $this->captureSent($messaging, $sent);

        (new FcmPusher($messaging))->sendToUser($user, 'T', 'B');

        $this->assertSame('alladin_notifications_chime', $sent[0]['android']['notification']['channel_id']);
        $this->assertSame('chime.wav', $sent[0]['apns']['payload']['aps']['sound']);
    }

    public function test_sound_disabled_uses_the_silent_channel_and_omits_apns_sound(): void
    {
        $user = $this->mobileUser();
        DeviceToken::factory()->silent()->create(['user_id' => $user->id]);

        $messaging = $this->messagingMock();
        $sent = [];
        $this->captureSent($messaging, $sent);

        (new FcmPusher($messaging))->sendToUser($user, 'T', 'B');

        $this->assertSame('alladin_notifications_silent', $sent[0]['android']['notification']['channel_id']);
        // There is no silent sound file on iOS — the key must be absent.
        $this->assertArrayNotHasKey('sound', $sent[0]['apns']['payload']['aps'] ?? []);
    }

    public function test_unknown_sound_key_falls_back_to_the_default_channel(): void
    {
        $user = $this->mobileUser();
        DeviceToken::factory()->create(['user_id' => $user->id, 'sound_key' => 'trombone']);

        $messaging = $this->messagingMock();
        $sent = [];
        $this->captureSent($messaging, $sent);

        (new FcmPusher($messaging))->sendToUser($user, 'T', 'B');

        // Naming a channel the app never created would drop the notification.
        $this->assertSame('alladin_notifications', $sent[0]['android']['notification']['channel_id']);
        $this->assertSame('default', $sent[0]['apns']['payload']['aps']['sound']);
    }

    public function test_each_device_is_stamped_with_its_own_sound(): void
    {
        $user = $this->mobileUser();
        DeviceToken::factory()->create(['user_id' => $user->id, 'sound_key' => 'default']);
        DeviceToken::factory()->chime()->create(['user_id' => $user->id]);

        $messaging = $this->messagingMock();
        $sent = [];
        $this->captureSent($messaging, $sent, 2);

        (new FcmPusher($messaging))->sendToUser($user, 'T', 'B');

        $channels = array_column(array_column($sent, 'android'), 'notification');
        $this->assertEqualsCanonicalizing(
            ['alladin_notifications', 'alladin_notifications_chime'],
            array_column($channels, 'channel_id'),
        );
    }

    public function test_a_dead_token_drops_only_its_own_device(): void
    {
        $user = $this->mobileUser();
        $dead = DeviceToken::factory()->create(['user_id' => $user->id, 'token' => 'dead-tok']);
        $alive = DeviceToken::factory()->create(['user_id' => $user->id, 'token' => 'good-tok']);

        $messaging = $this->messagingMock();
        $messaging->shouldReceive('send')->twice()->andReturnUsing(function ($message) {
            $token = json_decode(json_encode($message), true)['token'] ?? null;
            if ($token === 'dead-tok') {
                throw new \RuntimeException('The registration token is not a valid FCM registration token');
            }

            return [];
        });

        $this->assertSame(1, (new FcmPusher($messaging))->sendToUser($user, 'T', 'B'));

        $this->assertNull(DeviceToken::find($dead->id), 'dead device should be removed');
        $this->assertNotNull(DeviceToken::find($alive->id), 'the other device must survive');
    }

    public function test_senderid_mismatch_does_not_drop_the_device(): void
    {
        // SenderId mismatch is a project-config problem, not a dead token —
        // the device must survive so it still works once config is fixed.
        $user = $this->mobileUser();
        $device = DeviceToken::factory()->create(['user_id' => $user->id]);

        $messaging = $this->messagingMock();
        $messaging->shouldReceive('send')->once()
            ->andThrow(new \RuntimeException('SenderId mismatch'));

        $this->assertSame(0, (new FcmPusher($messaging))->sendToUser($user, 'T', 'B'));
        $this->assertNotNull(DeviceToken::find($device->id));
    }

    public function test_transient_errors_are_rethrown_so_the_job_retries(): void
    {
        // Swallowing these made SendFcmPushJob's $tries/$backoff dead config.
        $user = $this->mobileUser();
        DeviceToken::factory()->create(['user_id' => $user->id]);

        $messaging = $this->messagingMock();
        $messaging->shouldReceive('send')->once()
            ->andThrow(new \RuntimeException('503 Service Unavailable'));

        $this->expectException(\RuntimeException::class);
        (new FcmPusher($messaging))->sendToUser($user, 'T', 'B');
    }

    public function test_null_data_values_are_dropped_rather_than_stringified(): void
    {
        $user = $this->mobileUser();
        DeviceToken::factory()->create(['user_id' => $user->id]);

        $messaging = $this->messagingMock();
        $sent = [];
        $this->captureSent($messaging, $sent);

        (new FcmPusher($messaging))->sendToUser($user, 'T', 'B', [
            'type' => 'message',
            'thread_id' => 9,
            'notification_id' => null,
        ]);

        $data = $sent[0]['data'];
        $this->assertSame(['type' => 'message', 'thread_id' => '9'], $data);
        // An empty string would reach the client as a real value to parse.
        $this->assertArrayNotHasKey('notification_id', $data);
    }

    public function test_one_users_dead_device_does_not_stop_another_users_push(): void
    {
        $a = $this->mobileUser();
        $b = $this->mobileUser();
        DeviceToken::factory()->create(['user_id' => $a->id, 'token' => 'dead-tok']);
        DeviceToken::factory()->create(['user_id' => $b->id, 'token' => 'good-tok']);

        $messaging = $this->messagingMock();
        $messaging->shouldReceive('send')->twice()->andReturnUsing(function ($message) {
            $token = json_decode(json_encode($message), true)['token'] ?? null;
            if ($token === 'dead-tok') {
                throw new \RuntimeException('Requested entity was not found');
            }

            return [];
        });

        $pusher = new FcmPusher($messaging);
        $this->assertSame(0, $pusher->sendToUser($a, 'T', 'B'));
        $this->assertSame(1, $pusher->sendToUser($b, 'T', 'B'));
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
