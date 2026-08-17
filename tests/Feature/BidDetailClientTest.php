<?php

// tests/Feature/BidDetailClientTest.php

namespace Tests\Feature;

use App\Models\Bid;
use App\Models\BidInsight;
use App\Models\Proposal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BidDetailClientTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['variables.flBase' => 'https://www.freelancer.com']);
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    public function test_bid_detail_shows_the_client_who_posted_the_project(): void
    {
        $proposal = Proposal::factory()->create([
            'project_id' => 40651285,
            'project_owner' => 94080053,
            'country' => 'Germany',
        ]);
        $bid = Bid::factory()->create(['proposal_id' => $proposal->id]);
        BidInsight::create([
            'project_id' => 40651285,
            'client_name' => 'Hans Richter',
            'client_username' => 'hansr',
            'client_avatar' => 'https://cdn.f-cdn.com/avatars/hans.jpg',
            'client_country' => 'Germany',
            'client_rating' => 4.8,
            'client_reviews' => 37,
            'client_member_since' => '2019-04-01 00:00:00',
            'client_verification' => ['identity_verified' => true, 'phone_verified' => false],
            'client_engagement' => ['completed' => 12],
            'last_scraped_at' => now(),
        ]);

        $res = $this->actingAs($this->admin())->get("/bids/{$bid->id}/detail")->assertOk();

        $res->assertSee('Posted by');
        $res->assertSee('Hans Richter');
        $res->assertSee('94080053');
        $res->assertSee('https://cdn.f-cdn.com/avatars/hans.jpg', false);
        // Username drives the profile link.
        $res->assertSee('https://www.freelancer.com/u/hansr', false);
        $res->assertSee('Apr 2019');
        $res->assertSee('Identity');
        $res->assertDontSee('Phone');
        $res->assertSee('Completed: 12');
    }

    public function test_bid_detail_says_so_when_the_client_was_never_captured(): void
    {
        $proposal = Proposal::factory()->create(['project_id' => 777200, 'project_owner' => null]);
        $bid = Bid::factory()->create(['proposal_id' => $proposal->id]);

        $this->actingAs($this->admin())->get("/bids/{$bid->id}/detail")
            ->assertOk()
            ->assertSee('We never captured who posted this project')
            ->assertDontSee('Posted by');
    }

    /**
     * Older projects kept an owner id but no profile. The block should still
     * render the id rather than vanish entirely.
     */
    public function test_owner_id_alone_still_renders_the_block(): void
    {
        $proposal = Proposal::factory()->create(['project_id' => 777201, 'project_owner' => 42]);
        $bid = Bid::factory()->create(['proposal_id' => $proposal->id]);

        $this->actingAs($this->admin())->get("/bids/{$bid->id}/detail")
            ->assertOk()
            ->assertSee('Posted by')
            ->assertSee('Unknown client')
            ->assertSee('42');
    }

    public function test_unsafe_avatar_never_reaches_the_bid_detail_markup(): void
    {
        $proposal = Proposal::factory()->create(['project_id' => 777202, 'project_owner' => 7]);
        $bid = Bid::factory()->create(['proposal_id' => $proposal->id]);
        BidInsight::create([
            'project_id' => 777202,
            'client_name' => 'Mallory',
            'client_avatar' => 'javascript:alert(1)',
            'last_scraped_at' => now(),
        ]);

        $this->actingAs($this->admin())->get("/bids/{$bid->id}/detail")
            ->assertOk()
            ->assertSee('Mallory')
            ->assertDontSee('javascript:alert(1)', false);
    }
}
