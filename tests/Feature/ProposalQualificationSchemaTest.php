<?php

namespace Tests\Feature;

use App\Models\Proposal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProposalQualificationSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_qualified_is_cast_to_boolean(): void
    {
        $p = Proposal::factory()->create(['qualified' => false, 'qualify_reason' => 'matched crypto']);
        $this->assertIsBool($p->fresh()->qualified);
        $this->assertFalse($p->fresh()->qualified);
        $this->assertSame('matched crypto', $p->fresh()->qualify_reason);
    }

    public function test_not_qualified_scope_returns_only_false_not_null(): void
    {
        Proposal::factory()->create(['qualified' => false]);
        Proposal::factory()->create(['qualified' => true]);
        Proposal::factory()->create(['qualified' => null]);

        $ids = Proposal::notQualified()->pluck('qualified')->all();
        $this->assertCount(1, $ids);
        $this->assertFalse((bool) $ids[0]);
    }
}
