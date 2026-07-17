<?php

namespace Tests\Feature;

use App\Models\GamificationSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GamificationSnapshotModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_snapshot_persists_fields_and_casts_top5_to_array(): void
    {
        $snap = GamificationSnapshot::create([
            'scraped_at' => '2026-07-16T11:35:45Z',
            'self_rank' => 268,
            'self_score' => 309961,
            'self_level' => 20,
            'self_username' => 'ahmadayaz',
            'self_public_name' => 'Raja Ahmad Ayaz N.',
            'top5' => [['rank' => 1, 'public_name' => 'Chandrasekhar G.', 'score' => 4593118]],
            'raw' => '{"ok":true}',
        ]);

        $fresh = $snap->fresh();
        $this->assertSame(268, $fresh->self_rank);
        $this->assertSame(309961, $fresh->self_score);
        $this->assertIsArray($fresh->top5);
        $this->assertSame('Chandrasekhar G.', $fresh->top5[0]['public_name']);
        $this->assertInstanceOf(\Illuminate\Support\Carbon::class, $fresh->scraped_at);
    }
}
