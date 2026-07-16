<?php

namespace Tests\Feature;

use App\Models\Filter;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FilterNegativePromptSaveTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        // Explicitly set role => 'admin' to satisfy the EnsureAdmin middleware.
        return User::factory()->create(['role' => 'admin']);
    }

    public function test_update_persists_negative_prompt(): void
    {
        Filter::factory()->create(['id' => 1]);

        $this->actingAs($this->admin())
            ->post('/updateFilters', ['formValidationNegativePrompt' => 'no crypto or gambling'])
            ->assertRedirect('/filters');

        $this->assertSame('no crypto or gambling', Filter::find(1)->negative_prompt);
    }

    public function test_update_can_clear_negative_prompt(): void
    {
        $filter = Filter::factory()->create(['id' => 1, 'negative_prompt' => 'old value']);

        $this->actingAs($this->admin())
            ->post('/updateFilters', ['formValidationNegativePrompt' => ''])
            ->assertRedirect('/filters');

        $this->assertSame('', $filter->fresh()->negative_prompt);
    }
}
