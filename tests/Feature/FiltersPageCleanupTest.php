<?php

namespace Tests\Feature;

use App\Models\Filter;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FiltersPageCleanupTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    public function test_qualifier_prompt_shown_before_prompt(): void
    {
        Filter::factory()->create(['id' => 1]);

        $this->actingAs($this->admin())->get('/filters')
            ->assertOk()
            ->assertSeeInOrder(['Qualifier Prompt', 'Prompt', 'Summary Prompt']);
    }

    public function test_keyword_inputs_removed(): void
    {
        Filter::factory()->create(['id' => 1]);

        $res = $this->actingAs($this->admin())->get('/filters')->assertOk();
        $res->assertDontSee('Negative Keywords');
        $res->assertDontSee('TagifyBasic');
        $res->assertDontSee('tagifyNegativeKeywords');
        $res->assertDontSee('formValidationKeywords');
        $res->assertDontSee('name="usekeywords"', false);
    }

    public function test_prompt_labels_have_info_popovers(): void
    {
        Filter::factory()->create(['id' => 1]);

        $res = $this->actingAs($this->admin())->get('/filters')->assertOk();
        $res->assertSee('data-bs-toggle="popover"', false);
        $res->assertSee('Runs first, when the crawler saves a new project');
        $res->assertSee('Used as the AI system message to write the bid cover letter');
        $res->assertSee('produce a short project summary');
    }

    public function test_update_without_keyword_fields_saves_prompts(): void
    {
        Filter::factory()->create(['id' => 1]);

        $this->actingAs($this->admin())
            ->post('/updateFilters', [
                'formValidationPrompt' => 'write a great proposal',
                'formValidationNegativePrompt' => 'skip crypto',
                'formValidationSummaryPrompt' => 'summarize briefly',
            ])
            ->assertRedirect('/filters');

        $filter = Filter::find(1);
        $this->assertSame('write a great proposal', $filter->prompt);
        $this->assertSame('skip crypto', $filter->negative_prompt);
        $this->assertSame('summarize briefly', $filter->summary_prompt);
    }

    public function test_sectioned_layout_with_inline_switches(): void
    {
        Filter::factory()->create(['id' => 1]);

        $res = $this->actingAs($this->admin())->get('/filters')->assertOk();
        $res->assertSeeInOrder(['Project Criteria', 'AI Prompts']);
        $res->assertSee('name="useCountries"', false);
        $res->assertSee('name="useminhour"', false);
        $res->assertSee('name="useminfix"', false);
        $res->assertSee('name="formValidationCrawler"', false);
        $res->assertSee('Crawler Enabled');
    }
}
