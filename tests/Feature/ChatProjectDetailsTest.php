<?php

// tests/Feature/ChatProjectDetailsTest.php

namespace Tests\Feature;

use App\Models\Bid;
use App\Models\BidInsight;
use App\Models\Proposal;
use App\Models\Thread;
use App\Models\ThreadMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ChatProjectDetailsTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    public function test_panel_shows_the_listing_our_bid_and_the_client(): void
    {
        $proposal = Proposal::factory()->create([
            'project_id' => 40651285,
            'title' => 'Boost site loading speed',
            'description' => 'The homepage takes eleven seconds to paint.',
            'min_budget' => 250,
            'max_budget' => 750,
            'currency_symbol' => '$',
            'currency_name' => 'USD',
            'country' => 'Germany',
            'type' => 'fixed',
            'skills' => ['Laravel', 'Performance'],
            'qualified' => true,
            'qualify_reason' => 'Matches our stack.',
        ]);
        $bid = Bid::factory()->create([
            'proposal_id' => $proposal->id,
            'price' => 480,
            'bid_status' => 'completed',
            'cover_letter' => 'We have shipped this exact fix before.',
        ]);
        BidInsight::create([
            'project_id' => 40651285,
            'bid_id' => $bid->id,
            'client_name' => 'Hans Richter',
            'client_country' => 'Germany',
            'client_rating' => 4.8,
            'client_reviews' => 37,
            'bid_rank' => 3,
            'last_scraped_at' => now(),
        ]);

        $thread = Thread::factory()->create([
            'project_id' => 40651285,
            'proposal_id' => $proposal->id,
        ]);

        $res = $this->actingAs($this->admin())->get("/chats/{$thread->id}/detail")->assertOk();

        $res->assertSee('Project Details');
        // Listing
        $res->assertSee('$250 – $750', false);
        $res->assertSee('Fixed');
        $res->assertSee('Germany');
        $res->assertSee('Laravel');
        $res->assertSee('Performance');
        // Our bid
        $res->assertSee('$480', false);
        $res->assertSee('Completed');
        // Client, from bid insights
        $res->assertSee('Hans Richter');
        $res->assertSee('4.8');
        $res->assertSee('37 reviews');
        $res->assertSee('#3');
        // Long text is present but behind the toggle
        $res->assertSee('The homepage takes eleven seconds to paint.');
        $res->assertSee('We have shipped this exact fix before.');
        $res->assertSee('Matches our stack.');
        $res->assertSee('id="chat-project-more"', false);
    }

    /**
     * threads.proposal_id is NOT NULL, so there is always a proposal — but a
     * freshly synced one can be almost entirely empty. It must render as blanks
     * rather than "0" budgets or a crash, and offer no empty text toggle.
     */
    public function test_sparse_proposal_renders_blanks_not_zeroes(): void
    {
        $proposal = Proposal::factory()->create([
            'project_id' => 777123,
            'title' => 'Untitled sync',
            'description' => null,
            'min_budget' => null,
            'max_budget' => null,
            'country' => null,
            'type' => null,
        ]);
        $thread = Thread::factory()->create(['project_id' => 777123, 'proposal_id' => $proposal->id]);

        $this->actingAs($this->admin())->get("/chats/{$thread->id}/detail")
            ->assertOk()
            ->assertSee('Project Details')
            ->assertDontSee('$0', false)
            ->assertDontSee('id="chat-project-more"', false);
    }

    public function test_project_with_no_bid_or_insight_still_renders(): void
    {
        $proposal = Proposal::factory()->create([
            'project_id' => 777124,
            'min_budget' => 100,
            'max_budget' => 200,
            'type' => 'hourly',
        ]);
        $thread = Thread::factory()->create(['project_id' => 777124, 'proposal_id' => $proposal->id]);

        $res = $this->actingAs($this->admin())->get("/chats/{$thread->id}/detail")->assertOk();

        $res->assertSee('Hourly');
        $res->assertDontSee('Our bid');
        $res->assertDontSee('Client rating');
    }

    public function test_failed_bid_surfaces_its_error(): void
    {
        $proposal = Proposal::factory()->create(['project_id' => 777125]);
        Bid::factory()->create([
            'proposal_id' => $proposal->id,
            'bid_status' => 'failed',
            'error_message' => 'You have used all of your bids',
        ]);
        $thread = Thread::factory()->create(['project_id' => 777125, 'proposal_id' => $proposal->id]);

        $this->actingAs($this->admin())->get("/chats/{$thread->id}/detail")
            ->assertOk()
            ->assertSee('Bid error')
            ->assertSee('You have used all of your bids');
    }

    public function test_panel_links_out_to_the_project_on_freelancer(): void
    {
        config(['variables.flBase' => 'https://www.freelancer.com']);
        $thread = Thread::factory()->create(['project_id' => 40651285]);

        $this->actingAs($this->admin())->get("/chats/{$thread->id}/detail")
            ->assertOk()
            ->assertSee('https://www.freelancer.com/projects/40651285', false);
    }

    public function test_project_owner_profile_is_shown(): void
    {
        $proposal = Proposal::factory()->create([
            'project_id' => 777130,
            'project_owner' => 8812345,
        ]);
        BidInsight::create([
            'project_id' => 777130,
            'client_name' => 'Hans Richter',
            'client_avatar' => 'https://cdn.f-cdn.com/avatars/hans.jpg',
            'client_country' => 'Germany',
            'client_country_flag' => '//cdn2.f-cdn.com/img/flags/png/de.png',
            'client_rating' => 4.8,
            'client_reviews' => 37,
            'client_member_since' => '2019-04-01 00:00:00',
            'client_verification' => [
                'identity_verified' => true,
                'payment_verified' => true,
                'phone_verified' => false,
            ],
            'client_engagement' => ['completed' => 12, 'invited' => 3],
            'last_scraped_at' => now(),
        ]);
        $thread = Thread::factory()->create(['project_id' => 777130, 'proposal_id' => $proposal->id]);

        $res = $this->actingAs($this->admin())->get("/chats/{$thread->id}/detail")->assertOk();

        $res->assertSee('Posted by');
        $res->assertSee('Hans Richter');
        $res->assertSee('8812345');
        $res->assertSee('https://cdn.f-cdn.com/avatars/hans.jpg', false);
        // Protocol-relative CDN urls are normalised, not dropped.
        $res->assertSee('https://cdn2.f-cdn.com/img/flags/png/de.png', false);
        $res->assertSee('Apr 2019');
        // Only the badges that are actually true.
        $res->assertSee('Identity');
        $res->assertSee('Payment');
        $res->assertDontSee('Phone');
        $res->assertSee('Completed: 12');
        $res->assertSee('Invited: 3');
    }

    /**
     * Avatar and flag come from Freelancer's CDN and are rendered as src
     * attributes, so a hostile scheme must never survive into the markup.
     */
    public function test_unsafe_avatar_url_is_dropped(): void
    {
        $proposal = Proposal::factory()->create(['project_id' => 777131]);
        BidInsight::create([
            'project_id' => 777131,
            'client_name' => 'Mallory',
            'client_avatar' => 'javascript:alert(1)',
            'last_scraped_at' => now(),
        ]);
        $thread = Thread::factory()->create(['project_id' => 777131, 'proposal_id' => $proposal->id]);

        $this->actingAs($this->admin())->get("/chats/{$thread->id}/detail")
            ->assertOk()
            ->assertSee('Mallory')
            ->assertDontSee('javascript:alert(1)', false);
    }

    public function test_conversation_names_the_client_instead_of_saying_client(): void
    {
        $proposal = Proposal::factory()->create(['project_id' => 777132]);
        BidInsight::create([
            'project_id' => 777132,
            'client_name' => 'Hans Richter',
            'last_scraped_at' => now(),
        ]);
        $thread = Thread::factory()->create(['project_id' => 777132, 'proposal_id' => $proposal->id]);
        ThreadMessage::factory()->create([
            'thread_id' => $thread->id,
            'direction' => 'received',
            'message' => 'When can you start?',
        ]);

        $this->actingAs($this->admin())->get("/chats/{$thread->id}/detail")
            ->assertOk()
            ->assertSee('Conversation with Hans Richter')
            ->assertSee('When can you start?');
    }

    public function test_conversation_falls_back_to_client_when_owner_is_unknown(): void
    {
        $thread = Thread::factory()->create(['project_id' => 777133]);
        ThreadMessage::factory()->create([
            'thread_id' => $thread->id,
            'direction' => 'received',
        ]);

        $this->actingAs($this->admin())->get("/chats/{$thread->id}/detail")
            ->assertOk()
            ->assertSee('Conversation with Client');
    }

    /**
     * A mobile agent works their own threads from here, so they get the same
     * project context an admin does.
     */
    public function test_mobile_agent_sees_the_panel_on_their_own_thread(): void
    {
        $agent = User::factory()->create(['role' => 'mobile']);
        $proposal = Proposal::factory()->create(['project_id' => 777126, 'country' => 'Norway']);
        $thread = Thread::factory()->create([
            'project_id' => 777126,
            'proposal_id' => $proposal->id,
            'assigned_user_id' => $agent->id,
        ]);

        $this->actingAs($agent)->get("/chats/{$thread->id}/detail")
            ->assertOk()
            ->assertSee('Project Details')
            ->assertSee('Norway');
    }
}
