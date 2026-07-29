<?php

namespace Tests\Feature\Api;

use App\Models\DeviceToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * POST /v1/mobile/fcm-token — device registration and the per-device
 * notification-sound preference the server stamps onto every push.
 *
 * Replaces MobileFcmTokenTest, which asserted the old single users.fcm_token
 * column: a second device overwrote the first, and logging out anywhere
 * stopped pushes everywhere.
 */
class MobileDeviceTokenTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsMobile(): User
    {
        $user = User::factory()->mobile()->create();
        Sanctum::actingAs($user);

        return $user;
    }

    public function test_registers_the_device_with_platform_and_sound_preference(): void
    {
        $user = $this->actingAsMobile();

        $this->postJson('/api/v1/mobile/fcm-token', [
            'fcm_token' => 'new-token-123',
            'platform' => 'ios',
            'device_name' => 'iphone-15',
            'sound_key' => 'chime',
            'sound_enabled' => true,
        ])->assertOk()->assertJsonPath('success', true);

        $device = DeviceToken::where('token', 'new-token-123')->first();
        $this->assertNotNull($device);
        $this->assertSame($user->id, (int) $device->user_id);
        $this->assertSame('ios', $device->platform);
        $this->assertSame('chime', $device->sound_key);
        $this->assertTrue($device->sound_enabled);
    }

    public function test_sound_key_defaults_when_not_supplied(): void
    {
        $this->actingAsMobile();

        $this->postJson('/api/v1/mobile/fcm-token', ['fcm_token' => 'tok'])->assertOk();

        $device = DeviceToken::first();
        $this->assertSame('default', $device->sound_key);
        $this->assertTrue($device->sound_enabled);
    }

    public function test_updating_the_same_token_does_not_create_a_second_row(): void
    {
        $this->actingAsMobile();

        $this->postJson('/api/v1/mobile/fcm-token', ['fcm_token' => 'tok', 'sound_key' => 'ding'])->assertOk();
        $this->postJson('/api/v1/mobile/fcm-token', ['fcm_token' => 'tok', 'sound_key' => 'pop'])->assertOk();

        $this->assertSame(1, DeviceToken::count());
        $this->assertSame('pop', DeviceToken::first()->sound_key);
    }

    public function test_a_refresh_that_omits_the_sound_keeps_the_stored_preference(): void
    {
        $this->actingAsMobile();

        $this->postJson('/api/v1/mobile/fcm-token', ['fcm_token' => 'tok', 'sound_key' => 'chime'])->assertOk();
        // Token rotation carries no sound fields; it must not reset the choice.
        $this->postJson('/api/v1/mobile/fcm-token', ['fcm_token' => 'tok'])->assertOk();

        $this->assertSame('chime', DeviceToken::first()->sound_key);
    }

    public function test_turning_the_sound_off_is_persisted(): void
    {
        $this->actingAsMobile();

        $this->postJson('/api/v1/mobile/fcm-token', [
            'fcm_token' => 'tok',
            'sound_enabled' => false,
        ])->assertOk();

        $this->assertFalse(DeviceToken::first()->sound_enabled);
    }

    public function test_a_placeholder_token_is_rejected(): void
    {
        $this->actingAsMobile();

        $this->postJson('/api/v1/mobile/fcm-token', ['fcm_token' => 'unavailable'])
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->assertSame(0, DeviceToken::count());
    }

    public function test_an_unknown_sound_key_is_rejected(): void
    {
        $this->actingAsMobile();

        $this->postJson('/api/v1/mobile/fcm-token', [
            'fcm_token' => 'tok',
            'sound_key' => 'trombone',
        ])->assertStatus(422)->assertJsonValidationErrors('sound_key');
    }

    public function test_requires_a_token(): void
    {
        $this->actingAsMobile();

        $this->postJson('/api/v1/mobile/fcm-token', [])->assertStatus(422);
    }

    public function test_forbidden_for_non_mobile(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));

        $this->postJson('/api/v1/mobile/fcm-token', ['fcm_token' => 'x'])->assertForbidden();
    }
}
