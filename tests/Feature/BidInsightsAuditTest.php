<?php

namespace Tests\Feature;

use App\Models\BidInsight;
use App\Models\BidInsightChange;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BidInsightsAuditTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'test-secret-token';

    protected function setUp(): void
    {
        parent::setUp();
        config(['variables.gamificationIngestToken' => self::TOKEN]);
    }

    private function postWithToken(array $payload)
    {
        return $this->withHeader('Authorization', 'Bearer ' . self::TOKEN)
            ->postJson('/api/insights/bids/ingest', $payload);
    }

    private function bidItem(array $overrides = []): array
    {
        return array_merge([
            'project_id' => 39812345,
            'bid_amount' => 250,
            'client_country' => 'US',
            'bid_rank' => 5,
            'winning_bid_amount' => 220,
            'winning_bid_sealed' => false,
            'actions_taken' => ['viewed_by_client'],
            'client_engagement' => ['viewed' => true, 'replied' => false],
        ], $overrides);
    }

    private function seedBid(): void
    {
        $this->postWithToken([
            'scraped_at' => '2026-07-20T10:00:00Z',
            'crawl_type' => 'initial',
            'bids' => [$this->bidItem()],
        ])->assertOk();
    }

    public function test_changed_recurring_fields_write_audit_rows(): void
    {
        $this->seedBid();

        $this->postWithToken([
            'scraped_at' => '2026-07-20T11:00:00Z',
            'bids' => [$this->bidItem([
                'bid_rank' => 3,
                'winning_bid_amount' => 230,
            ])],
        ])->assertOk()->assertJson(['updated' => 1, 'changes' => 2]);

        $bid = BidInsight::firstOrFail();
        $this->assertSame(3, $bid->bid_rank);
        $this->assertSame('230.00', (string) $bid->winning_bid_amount);

        $rankChange = BidInsightChange::where('field', 'bid_rank')->firstOrFail();
        $this->assertSame('5', $rankChange->old_value);
        $this->assertSame('3', $rankChange->new_value);
        $this->assertSame('2026-07-20 11:00:00', $rankChange->observed_at->format('Y-m-d H:i:s'));
    }

    public function test_identical_values_write_no_audit_rows(): void
    {
        $this->seedBid();

        $this->postWithToken([
            'scraped_at' => '2026-07-20T11:00:00Z',
            'bids' => [$this->bidItem()],
        ])->assertOk()->assertJson(['updated' => 1, 'changes' => 0]);

        $this->assertSame(0, BidInsightChange::count());
    }

    public function test_json_field_change_is_detected(): void
    {
        $this->seedBid();

        $this->postWithToken([
            'scraped_at' => '2026-07-20T11:00:00Z',
            'bids' => [$this->bidItem([
                'client_engagement' => ['viewed' => true, 'replied' => true],
            ])],
        ])->assertOk()->assertJson(['changes' => 1]);

        $change = BidInsightChange::where('field', 'client_engagement')->firstOrFail();
        $this->assertStringContainsString('"replied":true', $change->new_value);
    }

    public function test_one_time_field_not_overwritten(): void
    {
        $this->seedBid();

        $this->postWithToken([
            'scraped_at' => '2026-07-20T11:00:00Z',
            'bids' => [$this->bidItem(['client_country' => 'DE', 'bid_amount' => 999])],
        ])->assertOk()->assertJson(['changes' => 0]);

        $bid = BidInsight::firstOrFail();
        $this->assertSame('US', $bid->client_country);
        $this->assertSame('250.00', (string) $bid->bid_amount);
    }

    public function test_one_time_field_filled_in_when_null(): void
    {
        $item = $this->bidItem();
        unset($item['client_country']);
        $this->postWithToken(['scraped_at' => '2026-07-20T10:00:00Z', 'bids' => [$item]])->assertOk();

        $this->postWithToken([
            'scraped_at' => '2026-07-20T11:00:00Z',
            'bids' => [$this->bidItem()],
        ])->assertOk();

        $this->assertSame('US', BidInsight::firstOrFail()->client_country);
    }

    public function test_absent_recurring_field_is_not_a_change(): void
    {
        $this->seedBid();

        $item = $this->bidItem();
        unset($item['bid_rank']);
        $this->postWithToken(['scraped_at' => '2026-07-20T11:00:00Z', 'bids' => [$item]])
            ->assertOk()->assertJson(['changes' => 0]);

        $this->assertSame(5, BidInsight::firstOrFail()->bid_rank);
    }
}
