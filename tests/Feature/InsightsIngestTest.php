<?php

namespace Tests\Feature;

use App\Models\InsightSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InsightsIngestTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'test-secret-token';

    protected function setUp(): void
    {
        parent::setUp();
        config(['variables.gamificationIngestToken' => self::TOKEN]);
    }

    private function payload(): array
    {
        return json_decode(file_get_contents(base_path('tests/Fixtures/user-stats-extracted.json')), true);
    }

    private function postWithToken(array $payload)
    {
        return $this->withHeader('Authorization', 'Bearer ' . self::TOKEN)
            ->postJson('/api/insights/ingest', $payload);
    }

    public function test_rejects_missing_token(): void
    {
        $this->postJson('/api/insights/ingest', $this->payload())->assertStatus(401);
        $this->assertSame(0, InsightSnapshot::count());
    }

    public function test_full_blob_parses_canonical_columns(): void
    {
        $this->postWithToken($this->payload())->assertOk()->assertJson(['success' => true]);

        $snap = InsightSnapshot::firstOrFail();
        $this->assertSame('363600.05', (string) $snap->earnings_total);
        $this->assertSame('0.00', (string) $snap->earnings_30d);
        $this->assertSame(203, $snap->bids_remaining);
        $this->assertSame(1297, $snap->unearned_bids);
        $this->assertSame('25%', $snap->overall_ranking);
        $this->assertCount(4, $snap->job_proficiency);
        $this->assertCount(33, $snap->rating_per_skill);
        $this->assertCount(2289, $snap->trending_skills);
        $this->assertNull($snap->bids_per_milestone['user']);
        $this->assertNotNull($snap->bids_per_milestone['marketplace']);
        $this->assertArrayHasKey('labels', $snap->profile_views_week);
        // raw retained
        $this->assertArrayHasKey('userStats', json_decode($snap->raw, true));
    }

    public function test_partial_blob_user_stats_only(): void
    {
        $p = ['userStats' => $this->payload()['userStats']];
        $this->postWithToken($p)->assertOk();

        $snap = InsightSnapshot::firstOrFail();
        $this->assertSame(203, $snap->bids_remaining);
        $this->assertNull($snap->overall_ranking);
        $this->assertNull($snap->trending_skills);
    }

    public function test_earnings_per_skill_from_live_payload_is_stored(): void
    {
        $p = [
            'scraped_at' => '2026-07-21T06:42:51.400Z',
            'userStats' => [
                'totalEarnings' => [['value' => '$363,473.34'], ['value' => '$0.00']],
                'bidSummary' => [['label' => 'Bids Remaining', 'value' => 48]],
                'jobProficiency' => [['label' => 'Completed Jobs', 'value' => '99%']],
                'earningsPerSkill' => [
                    ['name' => 'PHP', 'value' => '$264,759.91'],
                    ['name' => 'Website Design', 'value' => '$231,679.90'],
                ],
            ],
            'marketplaceStats' => null,
        ];

        $this->postWithToken($p)->assertOk();

        $snap = InsightSnapshot::firstOrFail();
        $this->assertSame('363473.34', (string) $snap->earnings_total);
        $this->assertSame(48, $snap->bids_remaining);
        $this->assertCount(2, $snap->earnings_per_skill);
        $this->assertSame('PHP', $snap->earnings_per_skill[0]['name']);
    }

    public function test_live_marketplace_stats_are_stored(): void
    {
        $p = [
            'scraped_at' => '2026-07-22T05:33:02.228Z',
            'userStats' => [
                'totalEarnings' => [['value' => '$363,266.75'], ['value' => '$0.00']],
                'bidSummary' => [['label' => 'Bids Remaining', 'value' => 1]],
            ],
            'marketplaceStats' => [
                'overallRanking' => [['value' => '25%']],
                'rankingPerSkill' => [
                    ['name' => 'JSON', 'value' => 'Top 9%'],
                    ['name' => 'Swift', 'value' => 'Top 14%'],
                ],
                'highDemandSkills' => [['name' => 'Data Entry', 'value' => '+27%']],
                'trendingSkills' => [['name' => 'Graphic Design']],
                'bidsPerMilestoneMarketplace' => '18.50',
                'profileViewCountPastWeek' => null,
                'profileViewCountPastYear' => null,
            ],
        ];

        $this->postWithToken($p)->assertOk();

        $snap = InsightSnapshot::firstOrFail();
        $this->assertSame('25%', $snap->overall_ranking);
        $this->assertSame('Top 9%', $snap->ranking_per_skill[0]['value']);
        $this->assertSame('+27%', $snap->high_demand_skills[0]['value']);
        $this->assertSame('Graphic Design', $snap->trending_skills[0]['name']);
        $this->assertSame('18.50', $snap->bids_per_milestone['marketplace']);
        $this->assertNull($snap->profile_views_week);
    }

    public function test_missing_both_sections_is_422(): void
    {
        $this->postWithToken(['foo' => 'bar'])->assertStatus(422);
        $this->assertSame(0, InsightSnapshot::count());
    }

    public function test_same_scraped_at_is_idempotent(): void
    {
        $p = $this->payload();
        $p['scraped_at'] = '2026-07-20T10:00:00Z';
        $this->postWithToken($p)->assertOk();
        $this->postWithToken($p)->assertOk();
        $this->assertSame(1, InsightSnapshot::count());
    }

    public function test_invalid_scraped_at_does_not_500(): void
    {
        $p = $this->payload();
        $p['scraped_at'] = 'not-a-date';
        $this->postWithToken($p)->assertSuccessful();
        $this->assertSame(1, InsightSnapshot::count());
    }

    public function test_malformed_section_nulls_column_but_succeeds(): void
    {
        $p = $this->payload();
        $p['userStats']['totalEarnings'] = 'garbage';
        $this->postWithToken($p)->assertOk();
        $this->assertNull(InsightSnapshot::firstOrFail()->earnings_total);
    }
}
