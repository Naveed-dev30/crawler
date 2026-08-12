<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UpworkPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_page_requires_auth(): void
    {
        $this->get('/bids/upwork')->assertRedirect();
    }

    public function test_page_renders_table_and_data_source(): void
    {
        $res = $this->actingAs(User::factory()->create())->get('/bids/upwork')->assertOk();

        $res->assertSee('Upwork Opportunities');
        $res->assertSee('bxl-upwork', false);              // brand icon in heading
        $res->assertSee('id="upwork-tbody"', false);
        $res->assertSee('/bids/upwork/data', false);       // JS data source
        $res->assertSeeInOrder(['Title', 'Budget / Rate', 'Posted', 'Skills', 'Client']);
    }

    public function test_sidebar_shows_freelancer_and_upwork_entries_with_brand_icons(): void
    {
        $res = $this->actingAs(User::factory()->create())->get('/bids')->assertOk();

        // Two "Opportunities" menu entries, distinguished only by brand icon
        $this->assertSame(2, substr_count($res->getContent(), '>Opportunities</div>'));
        // Freelancer brand glyph (CSS-masked SVG) + Upwork boxicons brand glyph
        $res->assertSee('menu-icon-freelancer', false);
        $res->assertSee('bxl-upwork', false);
        // Links point at the two pages
        $res->assertSee(url('/bids/upwork'), false);
    }
}
