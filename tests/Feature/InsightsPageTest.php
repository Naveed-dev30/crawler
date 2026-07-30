<?php

namespace Tests\Feature;

use App\Models\InsightSnapshot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InsightsPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_requires_auth(): void
    {
        $this->get('/insights')->assertRedirect();
    }

    public function test_empty_state_when_no_snapshots(): void
    {
        $this->actingAs(User::factory()->create())->get('/insights')
            ->assertOk()
            ->assertSee('No insights data yet');
    }

    public function test_renders_latest_snapshot(): void
    {
        InsightSnapshot::create([
            'scraped_at' => '2026-07-20 10:00:00',
            'earnings_total' => 363600.05,
            'earnings_30d' => 0,
            'bids_remaining' => 203,
            'unearned_bids' => 1297,
            'overall_ranking' => '25%',
            'job_proficiency' => [
                ['label' => 'Completed Jobs', 'bars' => [['rightLabel' => '99%', 'fillPercentage' => 99]]],
            ],
            'rating_per_skill' => [['label' => 'Flutter', 'value' => 5]],
            'ranking_per_skill' => [['label' => '.NET', 'value' => 44, 'displayValue' => 'Top 57%']],
            'high_demand_skills' => [['label' => 'Data Entry', 'value' => 630.1, 'displayValue' => '+29%']],
            'trending_skills' => [['label' => 'Graphic Design', 'value' => 5667.4, 'direction' => 'even']],
            'bids_per_milestone' => ['user' => null, 'marketplace' => [['value' => '21.70', 'label' => 'How many bids our best freelancers need']]],
            'profile_views_week' => ['labels' => ['14/7'], 'datasets' => [['label' => 'Views', 'data' => [35]]]],
            'profile_views_year' => ['labels' => ['Jul 26'], 'datasets' => [['label' => 'Views', 'data' => [169]]]],
            'earnings_over_time' => ['labels' => ["Feb '26"], 'datasets' => [['label' => 'Amount Earned (USD)', 'data' => ['0.00']]]],
            'bid_conversion' => ['labels' => ["Jul '26"], 'datasets' => [['label' => 'Bids Placed', 'data' => [1642]]]],
            'raw' => '{}',
        ]);

        $res = $this->actingAs(User::factory()->create())->get('/insights')->assertOk();
        $res->assertSee('363,600.05');
        $res->assertSee('Bids Remaining');
        $res->assertSee('203');
        $res->assertSee('Top 25%');
        $res->assertSee('Completed Jobs');
        $res->assertSee('Flutter');
        $res->assertSee('Data Entry');
        $res->assertSee('Graphic Design');
        $res->assertSee('21.70');
    }

    public function test_renders_live_crawler_shape(): void
    {
        InsightSnapshot::create([
            'scraped_at' => '2026-07-21 06:42:51',
            'earnings_total' => 363473.34,
            'earnings_30d' => 0,
            'bids_remaining' => 48,
            'job_proficiency' => [
                ['label' => 'Completed Jobs', 'value' => '99%'],
                ['label' => 'Rehire Rate', 'value' => '24%'],
            ],
            'earnings_per_skill' => [
                ['name' => 'PHP', 'value' => '$264,759.91'],
                ['name' => 'Website Design', 'value' => '$231,679.90'],
            ],
            'overall_ranking' => '25%',
            'ranking_per_skill' => [['name' => 'JSON', 'value' => 'Top 9%']],
            'high_demand_skills' => [['name' => 'Data Entry', 'value' => '+27%']],
            'trending_skills' => [
                ['name' => 'Graphic Design', 'direction' => 'up'],
                ['name' => 'Data Entry', 'direction' => 'down'],
                ['name' => 'After Effects', 'direction' => 'even'],
                ['name' => 'PHP'],
            ],
            'bids_per_milestone' => ['user' => null, 'marketplace' => '18.50'],
            'profile_views_week' => ['labels' => ['16/7', '17/7'], 'values' => [39, 17]],
            'profile_views_year' => ['labels' => ['Jun 26', 'Jul 26'], 'values' => [0, 221]],
            'raw' => '{}',
        ]);

        $res = $this->actingAs(User::factory()->create())->get('/insights')->assertOk();
        $res->assertSee('Completed Jobs');
        $res->assertSee('99%');
        $res->assertSee('width: 99%', false);
        $res->assertSee('Earnings per Skill');
        $res->assertSee('PHP');
        $res->assertSee('$264,759.91');
        $res->assertSee('Top 25%');
        $res->assertSee('Top 9%');
        $res->assertSee('+27%');
        $res->assertSee('Graphic Design');
        $res->assertSee('18.50');
        $res->assertSee('How many bids our best freelancers need');
        $res->assertSee('trend-up', false);
        $res->assertSee('trend-down', false);
        $res->assertSee('trend-even', false);
        $res->assertSee('Profile Views (Past Week)');
        $res->assertSee('"values":[39,17]', false);
    }

    public function test_partial_snapshot_does_not_error(): void
    {
        InsightSnapshot::create([
            'scraped_at' => '2026-07-20 10:00:00',
            'bids_remaining' => 210,
            'raw' => '{}',
        ]);

        $this->actingAs(User::factory()->create())->get('/insights')
            ->assertOk()
            ->assertSee('210');
    }
}
