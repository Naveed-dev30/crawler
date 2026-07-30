<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Smoke test for the dashboard root.
 *
 * Was the stock Laravel scaffold asserting a 200 from `/`, which has never
 * been true here — the dashboard is auth-gated, so a guest is redirected.
 */
class ExampleTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_away_from_the_dashboard(): void
    {
        $this->get('/')->assertRedirect();
    }

    public function test_an_authenticated_admin_reaches_the_dashboard(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->get('/')->assertSuccessful();
    }
}
