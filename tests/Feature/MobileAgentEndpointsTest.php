<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MobileAgentEndpointsTest extends TestCase
{
    use RefreshDatabase;

    public function test_rows_endpoint_returns_agent_rows(): void
    {
        $viewer = User::factory()->create(['role' => 'admin']);
        User::factory()->create(['role' => 'mobile', 'name' => 'Ana']);

        $this->actingAs($viewer)->getJson('/stats/mobile-agents?from=2026-07-01&to=2026-07-31')
            ->assertOk()
            ->assertJsonStructure(['rows' => [['user_id', 'name', 'assigned', 'responded', 'blocked', 'reassigned', 'avg_response_seconds']]])
            ->assertJsonFragment(['name' => 'Ana']);
    }

    public function test_activity_endpoint_returns_items(): void
    {
        $viewer = User::factory()->create(['role' => 'admin']);
        $agent = User::factory()->create(['role' => 'mobile']);

        $this->actingAs($viewer)->getJson("/stats/mobile-agents/{$agent->id}/activity")
            ->assertOk()
            ->assertJsonStructure(['items']);
    }

    public function test_activity_endpoint_403_for_non_mobile(): void
    {
        $viewer = User::factory()->create(['role' => 'admin']);
        $notAgent = User::factory()->create(['role' => 'team']);

        $this->actingAs($viewer)->getJson("/stats/mobile-agents/{$notAgent->id}/activity")
            ->assertStatus(403);
    }

    public function test_requires_auth(): void
    {
        $this->getJson('/stats/mobile-agents')->assertStatus(401);
    }
}
