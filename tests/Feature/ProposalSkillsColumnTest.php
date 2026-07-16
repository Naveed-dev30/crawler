<?php

namespace Tests\Feature;

use App\Models\Proposal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProposalSkillsColumnTest extends TestCase
{
    use RefreshDatabase;

    public function test_proposal_persists_skills_array_and_exchange_rate(): void
    {
        $p = Proposal::factory()->create([
            'skills' => ['php', 'vue'],
            'exchange_rate' => 1.5,
        ]);

        $fresh = Proposal::find($p->id);

        $this->assertSame(['php', 'vue'], $fresh->skills);
        $this->assertEquals(1.5, $fresh->exchange_rate);
    }

    public function test_skills_defaults_to_empty_array_via_factory(): void
    {
        $p = Proposal::factory()->create();
        $this->assertIsArray($p->skills);
    }
}
