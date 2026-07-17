<?php

namespace Tests\Feature;

use App\Models\GamificationSnapshot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LeaderboardPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_requires_auth(): void
    {
        $this->get('/leaderboard')->assertRedirect();
    }

    public function test_renders_latest_snapshot(): void
    {
        GamificationSnapshot::create([
            'scraped_at' => '2026-07-16T11:35:45Z',
            'self_rank' => 268,
            'self_score' => 309961,
            'self_level' => 20,
            'self_username' => 'ahmadayaz',
            'self_public_name' => 'Raja Ahmad Ayaz N.',
            'top5' => [
                ['rank' => 1, 'user_id' => 7480467, 'username' => 'cgullapalli', 'public_name' => 'Chandrasekhar G.', 'level' => 20, 'score' => 4593118, 'is_current_user' => false],
            ],
            'raw' => '{}',
        ]);

        $res = $this->actingAs(User::factory()->create())->get('/leaderboard')->assertOk();
        $res->assertSee('268');
        $res->assertSee('Chandrasekhar G.');
    }

    public function test_empty_state_when_no_snapshots(): void
    {
        $this->actingAs(User::factory()->create())->get('/leaderboard')
            ->assertOk()
            ->assertSee('No leaderboard data yet');
    }
}
