<?php

namespace Tests\Feature;

use App\Models\UpworkJob;
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

    public function test_rows_have_view_button(): void
    {
        UpworkJob::factory()->create(['job_id' => '~v1', 'title' => 'Some Upwork Job']);

        $html = $this->actingAs(User::factory()->create())
            ->getJson('/bids/upwork/data')->assertOk()->json('rowsHtml');

        $this->assertStringContainsString('upwork-view-btn', $html);
        $this->assertStringContainsString('data-upwork-id', $html);
    }

    public function test_detail_panel_is_read_only(): void
    {
        $job = UpworkJob::factory()->create([
            'job_id' => '~d1', 'title' => 'Detailed Upwork Job',
            'url' => 'https://www.upwork.com/jobs/~d1',
            'skills' => ['PHP', 'Laravel'], 'client_country' => 'United States',
        ]);

        $res = $this->actingAs(User::factory()->create())
            ->get('/bids/upwork/'.$job->id.'/detail')->assertOk();

        $res->assertSee('Detailed Upwork Job');
        $res->assertSee('View on Upwork');
        $res->assertSee('https://www.upwork.com/jobs/~d1', false);
        // Read-only: no write/save controls (mirror of Freelancer's check buttons)
        $res->assertDontSee('upwork-check-btn', false);
        $res->assertDontSee('data-check=', false);
    }

    public function test_detail_requires_auth(): void
    {
        $job = UpworkJob::factory()->create(['job_id' => '~a1']);
        $this->get('/bids/upwork/'.$job->id.'/detail')->assertRedirect();
    }
}
