<?php

namespace Tests\Feature;

use App\Models\Proposal;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StatisticsCountriesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-07-15 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_countries_top_list_respects_date_range(): void
    {
        Proposal::factory()->count(3)->create(['country' => 'India', 'created_at' => '2026-07-10 10:00:00']);
        Proposal::factory()->count(1)->create(['country' => 'USA', 'created_at' => '2026-07-10 10:00:00']);
        // Outside range → excluded
        Proposal::factory()->create(['country' => 'India', 'created_at' => '2026-01-01 10:00:00']);

        $res = $this->actingAs(User::factory()->create())
            ->getJson('/stats/countries?from=2026-07-01&to=2026-07-31')
            ->assertOk();

        $json = $res->json();
        $this->assertEquals('India', $json[0]['country']);
        $this->assertEquals(3, $json[0]['count']);
        $this->assertEquals('USA', $json[1]['country']);
        $this->assertEquals(1, $json[1]['count']);
    }
}
