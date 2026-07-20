<?php

namespace Tests\Feature;

use App\Models\InsightSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InsightSnapshotModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_creates_snapshot_with_casts(): void
    {
        $snap = InsightSnapshot::create([
            'scraped_at' => '2026-07-20 10:00:00',
            'earnings_total' => 363600.05,
            'earnings_30d' => 0,
            'bids_remaining' => 203,
            'unearned_bids' => 1297,
            'overall_ranking' => '25%',
            'job_proficiency' => [['label' => 'Completed Jobs']],
            'trending_skills' => [['label' => 'PHP']],
            'raw' => '{}',
        ]);

        $snap->refresh();
        $this->assertSame(203, $snap->bids_remaining);
        $this->assertSame('25%', $snap->overall_ranking);
        $this->assertIsArray($snap->job_proficiency);
        $this->assertSame('PHP', $snap->trending_skills[0]['label']);
        $this->assertInstanceOf(\Carbon\Carbon::class, $snap->scraped_at);
    }

    public function test_scraped_at_is_unique(): void
    {
        InsightSnapshot::create(['scraped_at' => '2026-07-20 10:00:00', 'raw' => '{}']);
        $this->expectException(\Illuminate\Database\QueryException::class);
        InsightSnapshot::create(['scraped_at' => '2026-07-20 10:00:00', 'raw' => '{}']);
    }
}
