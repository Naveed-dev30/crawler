<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * "Last Login" on the users page is the last API call the mobile app made,
 * not the last time a session was created.
 */
class UserLastLoginTest extends TestCase
{
    use RefreshDatabase;

    private function mobileUser(array $attrs = []): User
    {
        return User::factory()->create(array_merge([
            'role' => 'mobile',
            'password' => Hash::make('secret123'),
        ], $attrs));
    }

    public function test_login_stamps_last_api_activity(): void
    {
        $user = $this->mobileUser(['last_api_activity_at' => null]);

        Carbon::setTestNow('2026-08-17 09:30:00');

        $this->postJson('/api/v1/mobile/login', [
            'email' => $user->email,
            'password' => 'secret123',
        ])->assertOk();

        $this->assertSame('2026-08-17 09:30:00', $user->fresh()->last_api_activity_at->format('Y-m-d H:i:s'));

        Carbon::setTestNow();
    }

    public function test_any_authenticated_mobile_call_advances_the_stamp(): void
    {
        $user = $this->mobileUser(['last_api_activity_at' => '2026-08-10 08:00:00']);
        Sanctum::actingAs($user);

        Carbon::setTestNow('2026-08-17 14:05:00');

        $this->getJson('/api/v1/mobile/threads')->assertOk();

        $this->assertSame('2026-08-17 14:05:00', $user->fresh()->last_api_activity_at->format('Y-m-d H:i:s'));

        Carbon::setTestNow();
    }

    public function test_repeat_calls_within_a_minute_do_not_rewrite_the_stamp(): void
    {
        $user = $this->mobileUser(['last_api_activity_at' => null]);
        Sanctum::actingAs($user);

        Carbon::setTestNow('2026-08-17 14:05:00');
        $this->getJson('/api/v1/mobile/threads')->assertOk();

        // Throttled: a chatty client must not add an UPDATE to every request.
        Carbon::setTestNow('2026-08-17 14:05:30');
        $this->getJson('/api/v1/mobile/threads')->assertOk();
        $this->assertSame('2026-08-17 14:05:00', $user->fresh()->last_api_activity_at->format('Y-m-d H:i:s'));

        Carbon::setTestNow('2026-08-17 14:06:30');
        $this->getJson('/api/v1/mobile/threads')->assertOk();
        $this->assertSame('2026-08-17 14:06:30', $user->fresh()->last_api_activity_at->format('Y-m-d H:i:s'));

        Carbon::setTestNow();
    }

    public function test_activity_survives_logout(): void
    {
        $user = $this->mobileUser(['last_api_activity_at' => null]);

        $token = $this->postJson('/api/v1/mobile/login', [
            'email' => $user->email,
            'password' => 'secret123',
        ])->json('data.token');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/mobile/logout')
            ->assertOk();

        // The access token is gone, so personal_access_tokens.last_used_at is
        // no longer available — the users page must still show the visit.
        $this->assertSame(0, $user->tokens()->count());
        $this->assertNotNull($user->fresh()->last_api_activity_at);
    }

    public function test_users_page_shows_last_login_column(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->mobileUser(['name' => 'Mobile Person', 'last_api_activity_at' => '2026-08-15 11:00:00']);
        User::factory()->create(['role' => 'team', 'name' => 'Never Called', 'last_api_activity_at' => null]);

        $res = $this->actingAs($admin)->get('/users')->assertOk();

        $res->assertSee('Last Login');
        $res->assertSee('Aug 15, 2026');
        $res->assertSee('Aug 15, 2026 11:00 AM');
    }
}
