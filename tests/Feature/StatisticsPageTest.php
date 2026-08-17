<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StatisticsPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_stats_page_requires_auth(): void
    {
        $this->get('/stats')->assertRedirect('/login');
    }

    public function test_stats_page_renders_for_authenticated_user(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/stats')
            ->assertOk()
            ->assertSee('id="granularity-group"', false);
    }

    public function test_the_date_filter_sits_above_every_other_section(): void
    {
        $html = $this->actingAs(User::factory()->create())->get('/stats')->assertOk()->getContent();

        $this->assertLessThan(
            strpos($html, 'id="ov-placed"'),
            strpos($html, 'id="date-range"'),
            'the date range filter should come before the overview card',
        );
        $this->assertLessThan(strpos($html, 'id="chart-winrate"'), strpos($html, 'id="date-range"'));
    }

    public function test_presets_cover_today_and_all_time(): void
    {
        $html = $this->actingAs(User::factory()->create())->get('/stats')->assertOk()->getContent();

        $this->assertStringContainsString('data-preset="0"', $html);
        $this->assertStringContainsString('data-preset="all"', $html);
    }

    /** Nothing on the page advertises a window of its own any more. */
    public function test_no_section_is_labelled_with_a_fixed_window(): void
    {
        $html = $this->actingAs(User::factory()->create())->get('/stats')->assertOk()->getContent();

        $this->assertStringNotContainsString('(24h', $html);
        $this->assertStringNotContainsString('24h)', $html);
    }
}
