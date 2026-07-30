<?php

namespace Tests\Feature;

use App\Models\InsightSnapshot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InsightsProfileViewsWeekTest extends TestCase
{
    use RefreshDatabase;

    private function seedData(): void
    {
        InsightSnapshot::create([
            'scraped_at' => '2026-06-01 09:00:00',
            'profile_views_week' => ['labels' => ['a', 'b'], 'values' => [1, 2]],
            'raw' => '{}',
        ]);
        InsightSnapshot::create([
            'scraped_at' => '2026-07-01 09:00:00',
            'profile_views_week' => ['labels' => ['x', 'y'], 'values' => [7, 8]],
            'raw' => '{}',
        ]);
    }

    public function test_returns_requested_date_snapshot(): void
    {
        $this->seedData();
        $this->actingAs(User::factory()->create())
            ->getJson('/insights/profile-views-week?date=2026-06-15')
            ->assertOk()
            ->assertJson(['date' => '2026-06-01', 'labels' => ['a', 'b'], 'values' => [1, 2]]);
    }

    public function test_blank_date_returns_latest(): void
    {
        $this->seedData();
        $this->actingAs(User::factory()->create())
            ->getJson('/insights/profile-views-week')
            ->assertOk()
            ->assertJson(['date' => '2026-07-01', 'values' => [7, 8]]);
    }

    public function test_invalid_date_returns_latest(): void
    {
        $this->seedData();
        $this->actingAs(User::factory()->create())
            ->getJson('/insights/profile-views-week?date=not-a-date')
            ->assertOk()
            ->assertJson(['date' => '2026-07-01', 'values' => [7, 8]]);
    }

    public function test_requires_auth(): void
    {
        $this->getJson('/insights/profile-views-week')->assertStatus(401);
    }
}
