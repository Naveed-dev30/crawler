<?php

namespace Tests\Feature;

use App\Models\Bid;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BidAwardedColumnTest extends TestCase
{
    use RefreshDatabase;

    public function test_bid_persists_awarded_and_price(): void
    {
        $bid = Bid::factory()->create(['awarded' => true, 'awarded_price' => 123.45]);

        $fresh = Bid::find($bid->id);

        $this->assertTrue($fresh->awarded);
        $this->assertEquals(123.45, $fresh->awarded_price);
    }

    public function test_awarded_defaults_to_false(): void
    {
        $bid = Bid::factory()->create();
        $this->assertFalse($bid->awarded);
        $this->assertNull($bid->awarded_price);
    }
}
