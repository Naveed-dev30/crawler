<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AuthApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_with_valid_credentials_returns_token(): void
    {
        $user = User::factory()->create(['password' => bcrypt('secret123')]);

        $res = $this->postJson('/api/v1/login', [
            'email' => $user->email,
            'password' => 'secret123',
        ]);

        $res->assertOk()
            ->assertJsonStructure(['token', 'user' => ['id', 'name', 'email', 'role']]);
        $this->assertNotEmpty($res->json('token'));
        $this->assertDatabaseCount('personal_access_tokens', 1);
    }

    public function test_login_with_wrong_password_returns_401(): void
    {
        $user = User::factory()->create(['password' => bcrypt('secret123')]);

        $this->postJson('/api/v1/login', [
            'email' => $user->email,
            'password' => 'wrong',
        ])->assertStatus(401)->assertJson(['message' => 'Invalid credentials']);

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_login_validation_error_returns_422(): void
    {
        $this->postJson('/api/v1/login', ['email' => 'not-an-email'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['email', 'password']);
    }

    public function test_user_endpoint_requires_token(): void
    {
        $this->getJson('/api/v1/user')->assertStatus(401);
    }

    public function test_api_returns_json_even_without_accept_header(): void
    {
        // Plain post() does NOT set "Accept: application/json"; the API must
        // still return a JSON validation error, never an HTML login redirect.
        $res = $this->post('/api/v1/login', []);

        $res->assertStatus(422)
            ->assertHeader('content-type', 'application/json')
            ->assertJsonValidationErrors(['email', 'password']);
    }

    public function test_user_endpoint_returns_current_user_without_password(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $res = $this->getJson('/api/v1/user')->assertOk();
        $res->assertJsonPath('user.email', $user->email);
        $this->assertArrayNotHasKey('password', $res->json('user'));
    }

    public function test_logout_revokes_current_token_only(): void
    {
        $user = User::factory()->create();
        $tokenA = $user->createToken('deviceA')->plainTextToken;
        $tokenB = $user->createToken('deviceB')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer ' . $tokenA)
            ->postJson('/api/v1/logout')->assertNoContent();

        $this->assertDatabaseCount('personal_access_tokens', 1);

        $this->withHeader('Authorization', 'Bearer ' . $tokenB)
            ->getJson('/api/v1/user')->assertOk();
    }
}
