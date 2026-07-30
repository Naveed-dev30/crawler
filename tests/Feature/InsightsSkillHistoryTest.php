<?php

namespace Tests\Feature;

use App\Models\InsightSnapshot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InsightsSkillHistoryTest extends TestCase
{
    use RefreshDatabase;

    private function seedTwo(): void
    {
        InsightSnapshot::create([
            'scraped_at' => '2026-06-01 10:00:00',
            'earnings_per_skill' => [['name' => 'PHP', 'value' => '$1,000.00']],
            'ranking_per_skill' => [['name' => 'PHP', 'displayValue' => 'Top 20%']],
            'raw' => '{}',
        ]);
        InsightSnapshot::create([
            'scraped_at' => '2026-07-01 10:00:00',
            'earnings_per_skill' => [['name' => 'PHP', 'value' => '$1,500.00']],
            'ranking_per_skill' => [['name' => 'PHP', 'displayValue' => 'Top 12%']],
            'raw' => '{}',
        ]);
    }

    public function test_returns_series_for_section_and_label(): void
    {
        $this->seedTwo();

        $this->actingAs(User::factory()->create())
            ->getJson('/insights/skill-history?section=earnings_per_skill&label=PHP')
            ->assertOk()
            ->assertJson([
                'labels' => ['2026-06-01', '2026-07-01'],
                'values' => [1000, 1500],
                'higherIsBetter' => true,
                'label' => 'PHP',
            ]);
    }

    public function test_ranking_reports_lower_is_better(): void
    {
        $this->seedTwo();

        $this->actingAs(User::factory()->create())
            ->getJson('/insights/skill-history?section=ranking_per_skill&label=PHP')
            ->assertOk()
            ->assertJson(['values' => [20, 12], 'higherIsBetter' => false]);
    }

    public function test_missing_skill_yields_null_point(): void
    {
        $this->seedTwo();

        $this->actingAs(User::factory()->create())
            ->getJson('/insights/skill-history?section=earnings_per_skill&label=Nope')
            ->assertOk()
            ->assertJson(['values' => [null, null]]);
    }

    public function test_unknown_section_is_422(): void
    {
        $this->actingAs(User::factory()->create())
            ->getJson('/insights/skill-history?section=bogus&label=PHP')
            ->assertStatus(422);
    }

    public function test_requires_auth(): void
    {
        $this->getJson('/insights/skill-history?section=earnings_per_skill&label=PHP')
            ->assertStatus(401);
    }
}
