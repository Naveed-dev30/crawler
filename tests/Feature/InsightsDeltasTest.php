<?php

namespace Tests\Feature;

use App\Models\InsightSnapshot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InsightsDeltasTest extends TestCase
{
    use RefreshDatabase;

    private function seedRange(): void
    {
        // ~30 days prior
        InsightSnapshot::create([
            'scraped_at' => '2026-06-01 10:00:00',
            'earnings_total' => 1000,
            'earnings_per_skill' => [['name' => 'PHP', 'value' => '$1,000.00']],
            'raw' => '{}',
        ]);
        // latest
        InsightSnapshot::create([
            'scraped_at' => '2026-07-01 10:00:00',
            'earnings_total' => 1500,
            'earnings_per_skill' => [['name' => 'PHP', 'value' => '$1,500.00']],
            'raw' => '{}',
        ]);
    }

    public function test_shows_refreshed_line_and_count(): void
    {
        $this->seedRange();

        $this->actingAs(User::factory()->create())->get('/insights')
            ->assertOk()
            ->assertSee('Refreshed:')
            ->assertSee('2026-07-01')
            ->assertSee('2 snapshots');
    }

    public function test_renders_up_delta_for_growing_earnings(): void
    {
        $this->seedRange();

        $res = $this->actingAs(User::factory()->create())->get('/insights')->assertOk();
        $res->assertSee('trend-up', false);
        $res->assertSee('$500');
    }

    public function test_date_range_selects_prior_snapshot_as_latest(): void
    {
        $this->seedRange();

        // Cap the range before the July row: latest-in-range is the June snapshot.
        $res = $this->actingAs(User::factory()->create())
            ->get('/insights?to=2026-06-15')->assertOk();
        $res->assertSee('1,000.00'); // June earnings_total shown in the stat card
        $res->assertDontSee('1,500.00');
    }

    public function test_graph_button_present_with_data_attrs(): void
    {
        $this->seedRange();

        $this->actingAs(User::factory()->create())->get('/insights')
            ->assertOk()
            ->assertSee('skill-graph-btn', false)
            ->assertSee('data-section="earnings_per_skill"', false)
            ->assertSee('data-label="PHP"', false);
    }
}
