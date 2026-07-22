<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class UserUpdateTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function mobileUser(array $overrides = []): User
    {
        return User::factory()->create(array_merge([
            'role' => 'mobile',
            'profile_prompt' => 'Laravel expert',
            'escalation_ladder' => 2,
        ], $overrides));
    }

    public function test_requires_admin(): void
    {
        $user = $this->mobileUser();

        $this->put("/users/{$user->id}", ['name' => 'X'])->assertRedirect('/login');

        $team = User::factory()->create(['role' => 'team']);
        $this->actingAs($team)->put("/users/{$user->id}", ['name' => 'X'])->assertForbidden();
    }

    public function test_admin_can_update_mobile_user(): void
    {
        $user = $this->mobileUser();

        $this->actingAs($this->admin())
            ->put("/users/{$user->id}", [
                'name' => 'Updated Name',
                'email' => 'updated@example.com',
                'role' => 'mobile',
                'profile_prompt' => 'Vue expert now',
                'escalation_ladder' => 5,
            ])
            ->assertRedirect(route('users'));

        $user->refresh();
        $this->assertSame('Updated Name', $user->name);
        $this->assertSame('updated@example.com', $user->email);
        $this->assertSame('Vue expert now', $user->profile_prompt);
        $this->assertSame(5, (int) $user->escalation_ladder);
    }

    public function test_blank_password_keeps_current_one(): void
    {
        $user = $this->mobileUser(['password' => Hash::make('original-pass')]);

        $this->actingAs($this->admin())->put("/users/{$user->id}", [
            'name' => $user->name,
            'email' => $user->email,
            'password' => '',
            'role' => 'mobile',
            'profile_prompt' => $user->profile_prompt,
            'escalation_ladder' => $user->escalation_ladder,
        ])->assertRedirect(route('users'));

        $this->assertTrue(Hash::check('original-pass', $user->refresh()->password));
    }

    public function test_new_password_is_hashed_and_saved(): void
    {
        $user = $this->mobileUser();

        $this->actingAs($this->admin())->put("/users/{$user->id}", [
            'name' => $user->name,
            'email' => $user->email,
            'password' => 'brand-new-pass',
            'role' => 'mobile',
            'profile_prompt' => $user->profile_prompt,
            'escalation_ladder' => $user->escalation_ladder,
        ])->assertRedirect(route('users'));

        $this->assertTrue(Hash::check('brand-new-pass', $user->refresh()->password));
    }

    public function test_switching_to_team_clears_routing_fields(): void
    {
        $user = $this->mobileUser();

        $this->actingAs($this->admin())->put("/users/{$user->id}", [
            'name' => $user->name,
            'email' => $user->email,
            'role' => 'team',
        ])->assertRedirect(route('users'));

        $user->refresh();
        $this->assertSame('team', $user->role);
        $this->assertNull($user->profile_prompt);
        $this->assertNull($user->escalation_ladder);
    }

    public function test_own_email_does_not_trip_unique_rule(): void
    {
        $user = $this->mobileUser(['email' => 'same@example.com']);

        $this->actingAs($this->admin())->put("/users/{$user->id}", [
            'name' => 'New Name',
            'email' => 'same@example.com',
            'role' => 'mobile',
            'profile_prompt' => $user->profile_prompt,
            'escalation_ladder' => $user->escalation_ladder,
        ])->assertRedirect(route('users'))->assertSessionHasNoErrors();
    }

    public function test_taken_ladder_is_rejected(): void
    {
        $this->mobileUser(['escalation_ladder' => 3]);
        $user = $this->mobileUser(['escalation_ladder' => 4]);

        $this->actingAs($this->admin())->put("/users/{$user->id}", [
            'name' => $user->name,
            'email' => $user->email,
            'role' => 'mobile',
            'profile_prompt' => $user->profile_prompt,
            'escalation_ladder' => 3,
        ])->assertSessionHasErrors('escalation_ladder');
    }

    public function test_admin_account_update_ignores_role_and_routing_fields(): void
    {
        $target = User::factory()->create(['role' => 'admin', 'name' => 'Old Admin']);

        $this->actingAs($this->admin())->put("/users/{$target->id}", [
            'name' => 'New Admin Name',
            'email' => $target->email,
            'role' => 'team',
            'escalation_ladder' => 7,
        ])->assertRedirect(route('users'));

        $target->refresh();
        $this->assertSame('New Admin Name', $target->name);
        $this->assertSame('admin', $target->role);
        $this->assertNull($target->escalation_ladder);
    }

    public function test_users_page_shows_action_buttons(): void
    {
        $this->mobileUser();

        $this->actingAs($this->admin())->get('/users')
            ->assertOk()
            ->assertSee('js-view-user', false)
            ->assertSee('js-edit-user', false)
            ->assertSee('viewUserModal', false)
            ->assertSee('editUserModal', false);
    }
}
