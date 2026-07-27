<?php
// tests/Feature/Api/MobileFcmTokenTest.php
namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MobileFcmTokenTest extends TestCase
{
    use RefreshDatabase;

    public function test_updates_token_for_mobile_user(): void
    {
        $user = User::factory()->create(['role' => 'mobile', 'fcm_token' => 'old']);
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/mobile/fcm-token', ['fcm_token' => 'new-token-123'])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSame('new-token-123', $user->fresh()->fcm_token);
    }

    public function test_requires_token(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'mobile']));
        $this->postJson('/api/v1/mobile/fcm-token', [])->assertStatus(422);
    }

    public function test_forbidden_for_non_mobile(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $this->postJson('/api/v1/mobile/fcm-token', ['fcm_token' => 'x'])->assertForbidden();
    }
}
