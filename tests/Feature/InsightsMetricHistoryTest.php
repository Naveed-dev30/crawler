<?php

namespace Tests\Feature;

use App\Models\InsightSnapshot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InsightsMetricHistoryTest extends TestCase
{
    use RefreshDatabase;

    public function seedSnapshots(): void
    {
        InsightSnapshot::create([
            'scraped_at' => '2026-06-01 09:00:00',
            'earnings_total' => 1000, 'bids_remaining' => 200,
            'overall_ranking' => '30%',
            'bids_per_milestone' => ['user' => null, 'marketplace' => '21.50'],
            'raw' => '{}',
        ]);
        InsightSnapshot::create([
            'scraped_at' => '2026-07-01 09:00:00',
            'earnings_total' => 1500, 'bids_remaining' => 180,
            'overall_ranking' => '25%',
            'bids_per_milestone' => ['user' => null, 'marketplace' => '19.93'],
            'raw' => '{}',
        ]);
    }

    public function getMetricHistory(string $metric)
    {
        return $this->actingAs(User::factory()->create())
            ->getJson("/insights/metric-history?metric={$metric}");
    }

    public function test_earnings_series(): void
    {
        $this->seedSnapshots();
        $this->getMetricHistory('earnings_total')->assertOk()
            ->assertJson(['labels' => ['2026-06-01', '2026-07-01'], 'values' => [1000, 1500]]);
    }

    public function test_bids_remaining_series(): void
    {
        $this->seedSnapshots();
        $this->getMetricHistory('bids_remaining')->assertOk()->assertJson(['values' => [200, 180]]);
    }

    public function test_ranking_parsed_to_int(): void
    {
        $this->seedSnapshots();
        $this->getMetricHistory('overall_ranking')->assertOk()->assertJson(['values' => [30, 25]]);
    }

    public function test_milestone_parsed_to_float(): void
    {
        $this->seedSnapshots();
        $this->getMetricHistory('bids_per_milestone')->assertOk()->assertJson(['values' => [21.5, 19.93]]);
    }

    public function test_unknown_metric_422(): void
    {
        $this->actingAs(User::factory()->create())
            ->getJson('/insights/metric-history?metric=bogus')->assertStatus(422);
    }

    public function test_requires_auth(): void
    {
        $this->getJson('/insights/metric-history?metric=earnings_total')->assertStatus(401);
    }
}
