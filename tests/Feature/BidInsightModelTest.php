<?php

namespace Tests\Feature;

use App\Models\BidInsight;
use App\Models\BidInsightChange;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BidInsightModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_creates_bid_insight_with_casts(): void
    {
        $bid = BidInsight::create([
            'project_id' => 39812345,
            'bid_amount' => 250,
            'client_country' => 'US',
            'winning_bid_sealed' => false,
            'actions_taken' => ['viewed_by_client'],
            'client_engagement' => ['viewed' => true],
            'last_scraped_at' => '2026-07-20 10:00:00',
            'raw' => ['project_id' => 39812345],
        ]);

        $bid->refresh();
        $this->assertSame(39812345, $bid->project_id);
        $this->assertFalse($bid->winning_bid_sealed);
        $this->assertSame(['viewed_by_client'], $bid->actions_taken);
        $this->assertTrue($bid->client_engagement['viewed']);
        $this->assertIsArray($bid->raw);
    }

    public function test_project_id_unique(): void
    {
        BidInsight::create(['project_id' => 1, 'last_scraped_at' => now()]);
        $this->expectException(\Illuminate\Database\QueryException::class);
        BidInsight::create(['project_id' => 1, 'last_scraped_at' => now()]);
    }

    public function test_changes_relation(): void
    {
        $bid = BidInsight::create(['project_id' => 2, 'last_scraped_at' => now()]);
        $bid->changes()->create([
            'field' => 'bid_rank',
            'old_value' => '5',
            'new_value' => '3',
            'observed_at' => now(),
        ]);

        $this->assertSame(1, $bid->changes()->count());
        $this->assertSame('bid_rank', BidInsightChange::first()->field);
        $this->assertSame($bid->id, BidInsightChange::first()->bidInsight->id);
    }

    public function test_field_constants(): void
    {
        $this->assertContains('client_country', BidInsight::ONE_TIME_FIELDS);
        $this->assertContains('bid_rank', BidInsight::RECURRING_FIELDS);
        $this->assertEmpty(array_intersect(BidInsight::ONE_TIME_FIELDS, BidInsight::RECURRING_FIELDS));
    }
}
