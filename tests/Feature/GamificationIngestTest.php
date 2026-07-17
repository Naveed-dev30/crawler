<?php

namespace Tests\Feature;

use App\Models\GamificationSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GamificationIngestTest extends TestCase
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
        return json_decode(file_get_contents(base_path('tests/Fixtures/gamification-sample.json')), true);
    }

    public function test_rejects_missing_token(): void
    {
        $this->postJson('/api/gamification/ingest', $this->payload())
            ->assertStatus(401);
        $this->assertSame(0, GamificationSnapshot::count());
    }

    public function test_rejects_wrong_token(): void
    {
        $this->withHeader('Authorization', 'Bearer nope')
            ->postJson('/api/gamification/ingest', $this->payload())
            ->assertStatus(401);
        $this->assertSame(0, GamificationSnapshot::count());
    }

    public function test_valid_token_stores_extracted_snapshot(): void
    {
        $this->withHeader('Authorization', 'Bearer ' . self::TOKEN)
            ->postJson('/api/gamification/ingest', $this->payload())
            ->assertOk()
            ->assertJson(['success' => true]);

        $snap = GamificationSnapshot::firstOrFail();
        $this->assertSame(268, $snap->self_rank);
        $this->assertSame(309961, $snap->self_score);
        $this->assertSame(20, $snap->self_level);
        $this->assertSame('Raja Ahmad Ayaz N.', $snap->self_public_name);
        $this->assertCount(5, $snap->top5);
        $this->assertSame('Chandrasekhar G.', $snap->top5[0]['public_name']);
        $this->assertTrue($snap->top5[1]['is_current_user'] === false);
        // raw retained and decodes back to the payload
        $this->assertSame(7032685, json_decode($snap->raw, true)['user']['id']);
    }

    public function test_accepts_x_ingest_token_header(): void
    {
        $this->withHeader('X-Ingest-Token', self::TOKEN)
            ->postJson('/api/gamification/ingest', $this->payload())
            ->assertOk();
        $this->assertSame(1, GamificationSnapshot::count());
    }

    public function test_rejects_payload_without_leaderboard_top(): void
    {
        $this->withHeader('Authorization', 'Bearer ' . self::TOKEN)
            ->postJson('/api/gamification/ingest', ['source' => ['scraped_at' => '2026-07-16T11:35:45Z']])
            ->assertStatus(422);
        $this->assertSame(0, GamificationSnapshot::count());
    }

    public function test_reposting_same_scraped_at_is_idempotent(): void
    {
        $p = $this->payload();
        $this->withHeader('Authorization', 'Bearer ' . self::TOKEN)->postJson('/api/gamification/ingest', $p)->assertOk();
        $this->withHeader('Authorization', 'Bearer ' . self::TOKEN)->postJson('/api/gamification/ingest', $p)->assertOk();
        $this->assertSame(1, GamificationSnapshot::count());
    }

    public function test_invalid_scraped_at_does_not_500(): void
    {
        $p = $this->payload();
        $p['source']['scraped_at'] = 'not-a-date';
        $this->withHeader('Authorization', 'Bearer ' . self::TOKEN)
            ->postJson('/api/gamification/ingest', $p)
            ->assertSuccessful();
        $this->assertSame(1, GamificationSnapshot::count());
    }
}
