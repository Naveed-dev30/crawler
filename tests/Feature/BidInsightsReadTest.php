<?php

namespace Tests\Feature;

use App\Models\BidInsight;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BidInsightsReadTest extends TestCase
{
    use RefreshDatabase;

    public function test_lists_bids_ordered_by_last_scraped_desc_without_raw(): void
    {
        BidInsight::create(['project_id' => 1, 'bid_rank' => 9, 'last_scraped_at' => '2026-07-19 10:00:00', 'raw' => ['x' => 1]]);
        BidInsight::create(['project_id' => 2, 'bid_rank' => 4, 'last_scraped_at' => '2026-07-20 10:00:00', 'raw' => ['x' => 2]]);

        $res = $this->getJson('/api/insights/bids')->assertOk()->json();

        $this->assertSame(2, $res['total']);
        $this->assertSame(2, $res['data'][0]['project_id']);
        $this->assertSame(1, $res['data'][1]['project_id']);
        $this->assertArrayNotHasKey('raw', $res['data'][0]);
    }

    public function test_pagination_50_per_page(): void
    {
        for ($i = 1; $i <= 55; $i++) {
            BidInsight::create(['project_id' => $i, 'last_scraped_at' => now()]);
        }

        $res = $this->getJson('/api/insights/bids')->assertOk()->json();
        $this->assertCount(50, $res['data']);
        $this->assertSame(2, $res['last_page']);
    }

    public function test_changes_trail_for_one_bid(): void
    {
        $bid = BidInsight::create(['project_id' => 1, 'last_scraped_at' => now()]);
        $other = BidInsight::create(['project_id' => 2, 'last_scraped_at' => now()]);

        $bid->changes()->create(['field' => 'bid_rank', 'old_value' => '5', 'new_value' => '3', 'observed_at' => '2026-07-20 10:00:00']);
        $bid->changes()->create(['field' => 'bid_rank', 'old_value' => '3', 'new_value' => '2', 'observed_at' => '2026-07-20 11:00:00']);
        $other->changes()->create(['field' => 'bid_rank', 'old_value' => '8', 'new_value' => '7', 'observed_at' => '2026-07-20 12:00:00']);

        $res = $this->getJson("/api/insights/bids/{$bid->id}/changes")->assertOk()->json();

        $this->assertSame(2, $res['total']);
        $this->assertSame('2', $res['data'][0]['new_value']); // newest first
        $this->assertSame('3', $res['data'][1]['new_value']);
    }

    public function test_changes_for_unknown_bid_is_404(): void
    {
        $this->getJson('/api/insights/bids/999/changes')->assertStatus(404);
    }
}
