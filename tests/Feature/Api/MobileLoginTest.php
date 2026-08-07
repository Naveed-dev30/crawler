<?php

namespace Tests\Feature\Api;

use App\Models\DeviceToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class MobileLoginTest extends TestCase
{
    use RefreshDatabase;

    private function mobileUser(array $attrs = []): User
    {
        return User::factory()->create(array_merge([
            'role' => 'mobile',
            'password' => Hash::make('secret123'),
        ], $attrs));
    }

    public function test_mobile_user_logs_in_and_the_device_is_registered(): void
    {
        $user = $this->mobileUser();

        $response = $this->postJson('/api/v1/mobile/login', [
            'email' => $user->email,
            'password' => 'secret123',
            'fcm_token' => 'fcm-abc-123',
            'device_name' => 'pixel-8',
            'platform' => 'android',
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['success', 'message', 'data' => ['token', 'user' => ['id', 'name', 'email']]]);

        $device = DeviceToken::where('token', 'fcm-abc-123')->first();
        $this->assertNotNull($device);
        $this->assertSame($user->id, (int) $device->user_id);
        $this->assertSame('android', $device->platform);
        // Linked to this session, which is what makes logout per-device.
        $this->assertNotNull($device->personal_access_token_id);
    }

    public function test_login_without_an_fcm_token_still_succeeds(): void
    {
        // A device that cannot obtain a token (push denied, or a simulator
        // without APNs) must still be able to sign in.
        $user = $this->mobileUser();

        $this->postJson('/api/v1/mobile/login', [
            'email' => $user->email,
            'password' => 'secret123',
            'device_name' => 'pixel-8',
        ])->assertOk()->assertJsonPath('success', true);

        $this->assertSame(0, DeviceToken::count());
    }

    public function test_login_with_a_placeholder_token_registers_no_device(): void
    {
        // The app sends this sentinel when it has no real token. Storing it
        // means every push makes a doomed FCM call.
        $user = $this->mobileUser();

        $this->postJson('/api/v1/mobile/login', [
            'email' => $user->email,
            'password' => 'secret123',
            'fcm_token' => 'unavailable',
            'device_name' => 'pixel-8',
        ])->assertOk();

        $this->assertSame(0, DeviceToken::count());
    }

    public function test_signing_in_again_from_the_same_device_reuses_the_row(): void
    {
        $user = $this->mobileUser();

        foreach (['first', 'second'] as $_) {
            $this->postJson('/api/v1/mobile/login', [
                'email' => $user->email,
                'password' => 'secret123',
                'fcm_token' => 'same-token',
                'device_name' => 'pixel-8',
            ])->assertOk();
        }

        $this->assertSame(1, DeviceToken::where('token', 'same-token')->count());
        // The superseded Sanctum token is revoked, so the table cannot grow
        // without bound on every sign-in.
        $this->assertSame(1, $user->fresh()->tokens()->count());
    }

    public function test_a_second_user_on_the_same_device_takes_over_the_row(): void
    {
        $first = $this->mobileUser();
        $second = $this->mobileUser();

        foreach ([$first, $second] as $user) {
            $this->postJson('/api/v1/mobile/login', [
                'email' => $user->email,
                'password' => 'secret123',
                'fcm_token' => 'shared-handset',
                'device_name' => 'pixel-8',
            ])->assertOk();
        }

        // One row, owned by whoever is signed in — otherwise the first user's
        // messages would keep pushing to a handset they no longer use.
        $this->assertSame(1, DeviceToken::count());
        $this->assertSame($second->id, (int) DeviceToken::first()->user_id);
    }

    public function test_non_mobile_role_is_rejected(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
            'password' => Hash::make('secret123'),
        ]);

        $this->postJson('/api/v1/mobile/login', [
            'email' => $admin->email,
            'password' => 'secret123',
            'fcm_token' => 'fcm-abc',
            'device_name' => 'pixel-8',
        ])->assertForbidden()->assertJsonPath('success', false);

        $this->assertSame(0, DeviceToken::count());
    }

    public function test_wrong_password_is_unauthorized(): void
    {
        $user = $this->mobileUser();

        $this->postJson('/api/v1/mobile/login', [
            'email' => $user->email,
            'password' => 'wrong-pass',
            'fcm_token' => 'fcm-abc',
            'device_name' => 'pixel-8',
        ])->assertUnauthorized()->assertJsonPath('success', false);
    }

    public function test_logout_revokes_the_session_and_drops_only_this_device(): void
    {
        $user = $this->mobileUser();

        $phone = $user->createToken('pixel-8');
        DeviceToken::factory()->create([
            'user_id' => $user->id,
            'token' => 'phone-token',
            'personal_access_token_id' => $phone->accessToken->id,
        ]);

        $tablet = $user->createToken('ipad');
        DeviceToken::factory()->create([
            'user_id' => $user->id,
            'token' => 'tablet-token',
            'personal_access_token_id' => $tablet->accessToken->id,
        ]);

        $this->postJson('/api/v1/mobile/logout', [], [
            'Authorization' => 'Bearer '.$phone->plainTextToken,
        ])->assertOk()->assertJsonPath('success', true);

        // Signing out of the phone must not silence the tablet.
        $this->assertNull(DeviceToken::where('token', 'phone-token')->first());
        $this->assertNotNull(DeviceToken::where('token', 'tablet-token')->first());
        $this->assertSame(1, $user->fresh()->tokens()->count());
    }

    public function test_logout_can_drop_a_device_by_body_token(): void
    {
        // Fallback for devices registered before the session link existed.
        $user = $this->mobileUser();
        $session = $user->createToken('pixel-8');
        DeviceToken::factory()->create([
            'user_id' => $user->id,
            'token' => 'unlinked-token',
            'personal_access_token_id' => null,
        ]);

        $this->postJson('/api/v1/mobile/logout', ['fcm_token' => 'unlinked-token'], [
            'Authorization' => 'Bearer '.$session->plainTextToken,
        ])->assertOk();

        $this->assertSame(0, DeviceToken::count());
    }

    public function test_logout_requires_auth(): void
    {
        $this->postJson('/api/v1/mobile/logout')->assertUnauthorized();
    }

    public function test_user_endpoint_returns_current_user(): void
    {
        $user = $this->mobileUser();
        $token = $user->createToken('pixel-8')->plainTextToken;

        $this->getJson('/api/v1/mobile/user', [
            'Authorization' => "Bearer {$token}",
        ])->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.id', $user->id)
            ->assertJsonPath('data.email', $user->email);
    }

    public function test_user_endpoint_rejects_non_mobile_role(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $token = $admin->createToken('x')->plainTextToken;

        $this->getJson('/api/v1/mobile/user', [
            'Authorization' => "Bearer {$token}",
        ])->assertForbidden();
    }
}
