<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UsersPageTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    public function test_users_page_requires_auth(): void
    {
        $this->get('/users')->assertRedirect('/login');
    }

    public function test_users_page_renders_for_admin(): void
    {
        User::factory()->create([
            'role' => 'mobile',
            'name' => 'Mobile Mike',
            'escalation_ladder' => 3,
        ]);

        $this->actingAs($this->admin())
            ->get('/users')
            ->assertOk()
            ->assertSee('Mobile Mike')
            ->assertSee('Add User');
    }

    public function test_users_page_forbidden_for_non_admin(): void
    {
        $team = User::factory()->create(['role' => 'team']);

        $this->actingAs($team)->get('/users')->assertForbidden();
    }

    public function test_admin_can_create_mobile_user(): void
    {
        $this->actingAs($this->admin())
            ->post('/users', [
                'name' => 'New Mobile',
                'email' => 'mobile@example.com',
                'password' => 'secret123',
                'profile_prompt' => 'Laravel and Vue expert',
                'escalation_ladder' => 1,
            ])
            ->assertRedirect();

        $user = User::where('email', 'mobile@example.com')->first();
        $this->assertNotNull($user);
        $this->assertSame('mobile', $user->role);
        $this->assertSame('Laravel and Vue expert', $user->profile_prompt);
        $this->assertSame(1, (int) $user->escalation_ladder);
        $this->assertNotSame('secret123', $user->password); // hashed
    }

    public function test_duplicate_escalation_ladder_rejected(): void
    {
        User::factory()->create(['role' => 'mobile', 'escalation_ladder' => 2]);

        $this->actingAs($this->admin())
            ->post('/users', [
                'name' => 'Second',
                'email' => 'second@example.com',
                'password' => 'secret123',
                'profile_prompt' => 'x',
                'escalation_ladder' => 2,
            ])
            ->assertSessionHasErrors('escalation_ladder');

        $this->assertNull(User::where('email', 'second@example.com')->first());
    }

    public function test_ladder_out_of_range_rejected(): void
    {
        $this->actingAs($this->admin())
            ->post('/users', [
                'name' => 'Bad',
                'email' => 'bad@example.com',
                'password' => 'secret123',
                'profile_prompt' => 'x',
                'escalation_ladder' => 11,
            ])
            ->assertSessionHasErrors('escalation_ladder');
    }

    public function test_used_ladder_numbers_not_offered_in_form(): void
    {
        User::factory()->create(['role' => 'mobile', 'escalation_ladder' => 1]);

        $response = $this->actingAs($this->admin())->get('/users');

        $response->assertOk();
        $response->assertDontSee('<option value="1">', false);
        $response->assertSee('<option value="2">', false);
    }
}
