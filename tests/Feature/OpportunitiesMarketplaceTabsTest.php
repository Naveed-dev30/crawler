<?php

namespace Tests\Feature;

use App\Models\User;
use Tests\TestCase;

class OpportunitiesMarketplaceTabsTest extends TestCase
{
    use \Illuminate\Foundation\Testing\RefreshDatabase;

    public function test_page_shows_both_marketplace_tabs_and_upwork_pane(): void
    {
        $res = $this->actingAs(User::factory()->create())->get('/bids')->assertOk();

        $res->assertSee('data-market="freelancer"', false);
        $res->assertSee('data-market="upwork"', false);
        // Upwork pane table header columns
        $res->assertSeeInOrder(['id="market-pane-upwork"'], false);
        $res->assertSee('/bids/upwork/data', false);
    }
}
