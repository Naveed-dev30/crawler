<?php

namespace Tests\Feature;

use App\Models\BidInsight;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InsightsBidsPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_requires_auth(): void
    {
        $this->get('/insights/bids')->assertRedirect();
    }

    public function test_empty_state(): void
    {
        $this->actingAs(User::factory()->create())->get('/insights/bids')
            ->assertOk()
            ->assertSee('No bid insights yet');
    }

    public function test_renders_bid_rows(): void
    {
        BidInsight::create([
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
            'actions_taken' => ['viewed_by_client'],
            'last_scraped_at' => '2026-07-20 10:00:00',
        ]);

        $res = $this->actingAs(User::factory()->create())->get('/insights/bids')->assertOk();
        $res->assertSee('39812345');
        $res->assertSee('1m 34s');
        $res->assertSee('250.00 USD');
        $res->assertSee('US');
        $res->assertSee('#3');
    }

    public function test_sealed_winning_bid_shows_sealed(): void
    {
        BidInsight::create([
            'project_id' => 7,
            'winning_bid_sealed' => true,
            'last_scraped_at' => '2026-07-20 10:00:00',
        ]);

        $this->actingAs(User::factory()->create())->get('/insights/bids')
            ->assertOk()
            ->assertSee('Sealed');
    }

    public function test_paginates_at_50(): void
    {
        for ($i = 1; $i <= 55; $i++) {
            BidInsight::create(['project_id' => $i, 'last_scraped_at' => now()]);
        }

        $this->actingAs(User::factory()->create())->get('/insights/bids')
            ->assertOk()
            ->assertSee('page=2');
    }
}
