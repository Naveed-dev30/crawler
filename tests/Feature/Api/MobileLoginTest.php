<?php

namespace Tests\Feature\Api;

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

    public function test_mobile_user_logs_in_and_fcm_token_is_stored(): void
    {
        $user = $this->mobileUser();

        $response = $this->postJson('/api/v1/mobile/login', [
            'email' => $user->email,
            'password' => 'secret123',
            'fcm_token' => 'fcm-abc-123',
            'device_name' => 'pixel-8',
        ]);

        $response->assertOk()->assertJsonStructure(['token', 'user' => ['id', 'name', 'email']]);
        $this->assertSame('fcm-abc-123', $user->fresh()->fcm_token);
    }

    public function test_login_updates_existing_fcm_token(): void
    {
        $user = $this->mobileUser(['fcm_token' => 'old-token']);

        $this->postJson('/api/v1/mobile/login', [
            'email' => $user->email,
            'password' => 'secret123',
            'fcm_token' => 'new-token',
            'device_name' => 'pixel-8',
        ])->assertOk();

        $this->assertSame('new-token', $user->fresh()->fcm_token);
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
        ])->assertForbidden();

        $this->assertNull($admin->fresh()->fcm_token);
    }

    public function test_wrong_password_is_unauthorized(): void
    {
        $user = $this->mobileUser();

        $this->postJson('/api/v1/mobile/login', [
            'email' => $user->email,
            'password' => 'wrong-pass',
            'fcm_token' => 'fcm-abc',
            'device_name' => 'pixel-8',
        ])->assertUnauthorized();
    }

    public function test_fcm_token_is_required(): void
    {
        $user = $this->mobileUser();

        $this->postJson('/api/v1/mobile/login', [
            'email' => $user->email,
            'password' => 'secret123',
            'device_name' => 'pixel-8',
        ])->assertUnprocessable()->assertJsonValidationErrors('fcm_token');
    }
}
