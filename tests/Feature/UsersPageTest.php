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
                'role' => 'mobile',
            ])
            ->assertRedirect();

        $user = User::where('email', 'mobile@example.com')->first();
        $this->assertNotNull($user);
        $this->assertSame('mobile', $user->role);
        $this->assertNotSame('secret123', $user->password); // hashed
    }

    public function test_users_table_is_paginated_twenty_per_page(): void
    {
        User::factory()->count(24)->create(['role' => 'team']); // + admin = 25
        $admin = $this->admin();

        $page1 = $this->actingAs($admin)->get('/users');
        $page1->assertOk();
        $this->assertCount(20, $page1->viewData('users'));

        $page2 = $this->actingAs($admin)->get('/users?page=2');
        $page2->assertOk();
        $this->assertCount(5, $page2->viewData('users'));
    }

    public function test_admin_can_create_team_user(): void
    {
        $this->actingAs($this->admin())
            ->post('/users', [
                'name' => 'Team Member',
                'email' => 'team-new@example.com',
                'password' => 'secret123',
                'role' => 'team',
            ])
            ->assertRedirect();

        $user = User::where('email', 'team-new@example.com')->first();
        $this->assertNotNull($user);
        $this->assertSame('team', $user->role);
    }

    public function test_admin_role_cannot_be_created(): void
    {
        $this->actingAs($this->admin())
            ->post('/users', [
                'name' => 'Sneaky Admin',
                'email' => 'sneak@example.com',
                'password' => 'secret123',
                'role' => 'admin',
            ])
            ->assertSessionHasErrors('role');

        $this->assertNull(User::where('email', 'sneak@example.com')->first());
    }

    public function test_search_filters_by_name_or_email(): void
    {
        User::factory()->create(['role' => 'team', 'name' => 'Zulfiqar Khan', 'email' => 'zk@example.com']);
        User::factory()->create(['role' => 'team', 'name' => 'Other Person', 'email' => 'findme@example.com']);
        User::factory()->create(['role' => 'team', 'name' => 'Nobody', 'email' => 'nobody@example.com']);

        $byName = $this->actingAs($this->admin())->get('/users?search=Zulfiqar');
        $this->assertCount(1, $byName->viewData('users'));
        $this->assertSame('Zulfiqar Khan', $byName->viewData('users')->first()->name);

        $byEmail = $this->actingAs(User::where('role', 'admin')->first())->get('/users?search=findme');
        $this->assertCount(1, $byEmail->viewData('users'));
        $this->assertSame('findme@example.com', $byEmail->viewData('users')->first()->email);
    }

    public function test_role_filter_limits_results(): void
    {
        User::factory()->create(['role' => 'team', 'name' => 'Team Guy']);
        User::factory()->create(['role' => 'mobile', 'name' => 'Mobile Guy']);

        $response = $this->actingAs($this->admin())->get('/users?role=mobile');

        $this->assertCount(1, $response->viewData('users'));
        $this->assertSame('Mobile Guy', $response->viewData('users')->first()->name);
    }

    public function test_filters_survive_pagination_links(): void
    {
        User::factory()->count(22)->create(['role' => 'team']);

        $response = $this->actingAs($this->admin())->get('/users?role=team&page=2');

        $response->assertOk();
        $this->assertCount(2, $response->viewData('users'));
    }
}
