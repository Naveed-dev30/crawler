<?php

namespace Tests\Feature;

use App\Models\Filter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NegativePromptColumnTest extends TestCase
{
    use RefreshDatabase;

    public function test_negative_prompt_is_persisted_and_nullable(): void
    {
        $filter = Filter::factory()->create();
        $this->assertNull($filter->fresh()->negative_prompt);

        $filter->negative_prompt = 'no crypto projects';
        $filter->save();
        $this->assertSame('no crypto projects', $filter->fresh()->negative_prompt);

        $filter->negative_prompt = '';
        $filter->save();
        $this->assertSame('', $filter->fresh()->negative_prompt);
    }
}
