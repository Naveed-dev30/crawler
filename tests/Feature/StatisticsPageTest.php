<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StatisticsPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_stats_page_requires_auth(): void
    {
        $this->get('/stats')->assertRedirect('/login');
    }

    public function test_stats_page_renders_for_authenticated_user(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/stats')
            ->assertOk()
            ->assertSee('id="granularity-group"', false);
    }
}
