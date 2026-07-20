<?php

namespace Tests\Feature;

use App\Models\InsightSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InsightsIndexTest extends TestCase
{
    use RefreshDatabase;

    public function test_empty_state(): void
    {
        $this->getJson('/api/insights')
            ->assertOk()
            ->assertJson(['latest' => null, 'history' => []]);
    }

    public function test_returns_latest_and_history_without_raw(): void
    {
        InsightSnapshot::create([
            'scraped_at' => '2026-07-19 10:00:00',
            'earnings_total' => 100,
            'bids_remaining' => 210,
            'raw' => '{"secret":1}',
        ]);
        InsightSnapshot::create([
            'scraped_at' => '2026-07-20 10:00:00',
            'earnings_total' => 363600.05,
            'bids_remaining' => 203,
            'overall_ranking' => '25%',
            'raw' => '{"secret":2}',
        ]);

        $res = $this->getJson('/api/insights')->assertOk()->json();

        $this->assertSame(203, $res['latest']['bids_remaining']);
        $this->assertArrayNotHasKey('raw', $res['latest']);
        $this->assertCount(2, $res['history']);
        // history is chronological
        $this->assertSame('2026-07-19', $res['history'][0]['date']);
        $this->assertSame('2026-07-20', $res['history'][1]['date']);
        $this->assertSame('25%', $res['history'][1]['overall_ranking']);
    }
}
