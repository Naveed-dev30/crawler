<?php

namespace Tests\Feature;

use App\Models\Filter;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FilterSummaryPromptSaveTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    public function test_update_persists_summary_prompt(): void
    {
        Filter::factory()->create(['id' => 1]);

        $this->actingAs($this->admin())
            ->post('/updateFilters', ['formValidationSummaryPrompt' => 'Summarize the reason in two lines.'])
            ->assertRedirect('/filters');

        $this->assertSame('Summarize the reason in two lines.', Filter::find(1)->summary_prompt);
    }

    public function test_update_can_clear_summary_prompt(): void
    {
        Filter::factory()->create(['id' => 1, 'summary_prompt' => 'old value']);

        $this->actingAs($this->admin())
            ->post('/updateFilters', ['formValidationSummaryPrompt' => ''])
            ->assertRedirect('/filters');

        $this->assertSame('', Filter::find(1)->fresh()->summary_prompt);
    }
}
