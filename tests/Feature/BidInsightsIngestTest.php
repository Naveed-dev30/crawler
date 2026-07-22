<?php

namespace Tests\Feature;

use App\Models\BidInsight;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BidInsightsIngestTest extends TestCase
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
            'project_url' => 'https://www.freelancer.com/projects/php/some-project',
            'time_to_bid_seconds' => 94,
            'bid_amount' => 250,
            'bid_currency' => 'USD',
            'client_country' => 'US',
            'client_rating' => 4.8,
            'client_reviews' => 132,
            'bid_rank' => 3,
            'winning_bid_amount' => 220,
            'winning_bid_sealed' => false,
            'winning_bid_text' => 'I will build this.',
            'actions_taken' => ['viewed_by_client'],
            'client_engagement' => ['viewed' => true, 'replied' => false],
        ], $overrides);
    }

    public function test_rejects_missing_token(): void
    {
        $this->postJson('/api/insights/bids/ingest', ['bids' => [$this->bidItem()]])
            ->assertStatus(401);
        $this->assertSame(0, BidInsight::count());
    }

    public function test_missing_bids_array_is_422(): void
    {
        $this->postWithToken(['foo' => 'bar'])->assertStatus(422);
        $this->postWithToken(['bids' => 'nope'])->assertStatus(422);
        $this->assertSame(0, BidInsight::count());
    }

    public function test_initial_ingest_creates_rows_without_changes(): void
    {
        $res = $this->postWithToken([
            'scraped_at' => '2026-07-20T10:00:00Z',
            'crawl_type' => 'initial',
            'bids' => [$this->bidItem(), $this->bidItem(['project_id' => 39812346])],
        ]);

        $res->assertOk()->assertJson([
            'success' => true,
            'created' => 2,
            'updated' => 0,
            'changes' => 0,
            'skipped' => 0,
        ]);

        $bid = BidInsight::where('project_id', 39812345)->firstOrFail();
        $this->assertSame(94, $bid->time_to_bid_seconds);
        $this->assertSame('US', $bid->client_country);
        $this->assertSame(3, $bid->bid_rank);
        $this->assertFalse($bid->winning_bid_sealed);
        $this->assertSame(['viewed_by_client'], $bid->actions_taken);
        $this->assertSame('2026-07-20 10:00:00', $bid->last_scraped_at->format('Y-m-d H:i:s'));
        $this->assertSame(0, $bid->changes()->count());
        $this->assertSame(39812345, $bid->raw['project_id']);
    }

    private function liveBidItem(array $overrides = []): array
    {
        return array_merge([
            'id' => 490511112,
            'project_id' => 40595109,
            'amount' => 675,
            'description' => 'Enhancing user experience through geo-targeting…',
            'rank' => 27,
            'rating' => 0,
            'time_submitted' => 1784616134,
            'project_chats_initiated' => 1,
            'project_invites' => 0,
            'action_taken' => ['client_saw_your_bid' => false, 'client_saw_your_profile' => false],
            'upgrades' => ['highlighted' => true, 'sealed' => false, 'sponsored' => false],
        ], $overrides);
    }

    public function test_live_crawler_payload_keys_are_mapped(): void
    {
        $this->postWithToken([
            'scraped_at' => '2026-07-21T06:42:54.273Z',
            'crawl_type' => 'initial',
            'bids' => [$this->liveBidItem()],
        ])->assertOk()->assertJson(['created' => 1, 'skipped' => 0]);

        $bid = BidInsight::where('project_id', 40595109)->firstOrFail();
        $this->assertSame(490511112, $bid->bid_id);
        $this->assertSame('675.00', (string) $bid->bid_amount);
        $this->assertSame(27, $bid->bid_rank);
        $this->assertSame('2026-07-21 06:42:14', $bid->time_submitted->format('Y-m-d H:i:s'));
        $this->assertStringStartsWith('Enhancing user experience', $bid->description);
        $this->assertSame(
            ['client_saw_your_bid' => false, 'client_saw_your_profile' => false],
            $bid->actions_taken
        );
        $this->assertSame(
            ['project_chats_initiated' => 1, 'project_invites' => 0],
            $bid->client_engagement
        );
        $this->assertSame(
            ['highlighted' => true, 'sealed' => false, 'sponsored' => false],
            $bid->upgrades
        );
    }

    public function test_live_payload_recurring_changes_are_audited(): void
    {
        $this->postWithToken(['bids' => [$this->liveBidItem()]])->assertOk();

        $this->postWithToken([
            'bids' => [$this->liveBidItem([
                'rank' => 31,
                'action_taken' => ['client_saw_your_bid' => true, 'client_saw_your_profile' => false],
            ])],
        ])->assertOk()->assertJson(['updated' => 1, 'changes' => 2]);

        $bid = BidInsight::where('project_id', 40595109)->firstOrFail();
        $this->assertSame(31, $bid->bid_rank);
        $this->assertSame(
            ['actions_taken', 'bid_rank'],
            $bid->changes()->pluck('field')->sort()->values()->all()
        );
    }

    public function test_same_content_different_key_order_is_not_a_change(): void
    {
        $this->postWithToken(['bids' => [$this->liveBidItem([
            'action_taken' => ['client_saw_your_bid' => false, 'client_saw_your_profile' => false],
        ])]])->assertOk();

        // Same values, reversed key order — must not create audit rows.
        $this->postWithToken(['bids' => [$this->liveBidItem([
            'action_taken' => ['client_saw_your_profile' => false, 'client_saw_your_bid' => false],
        ])]])->assertOk()->assertJson(['updated' => 1, 'changes' => 0]);

        $bid = BidInsight::where('project_id', 40595109)->firstOrFail();
        $this->assertSame(0, $bid->changes()->count());
    }

    public function test_item_without_project_id_is_skipped(): void
    {
        $item = $this->bidItem();
        unset($item['project_id']);

        $this->postWithToken(['bids' => [$item, $this->bidItem(['project_id' => 7])]])
            ->assertOk()
            ->assertJson(['created' => 1, 'skipped' => 1]);
        $this->assertSame(1, BidInsight::count());
    }

    public function test_float_project_id_is_skipped(): void
    {
        $this->postWithToken([
            'bids' => [$this->bidItem(['project_id' => '39812345.9'])],
        ])
            ->assertOk()
            ->assertJson(['created' => 0, 'skipped' => 1]);
        $this->assertSame(0, BidInsight::count());
    }
}
