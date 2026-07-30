<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserFormRoutingFieldsRemovedTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    public function test_user_page_has_no_routing_fields(): void
    {
        $this->actingAs($this->admin())->get('/users')
            ->assertOk()
            ->assertDontSee('Profile Prompt')
            ->assertDontSee('Escalation Ladder')
            ->assertDontSee('name="profile_prompt"', false)
            ->assertDontSee('name="escalation_ladder"', false);
    }

    public function test_mobile_user_creates_without_routing_fields(): void
    {
        $this->actingAs($this->admin())->post('/users', [
            'name' => 'New Mobile',
            'email' => 'new.mobile@example.com',
            'password' => 'password123',
            'role' => 'mobile',
        ])->assertRedirect(route('users'));

        $this->assertDatabaseHas('users', ['email' => 'new.mobile@example.com', 'role' => 'mobile']);
    }
}
