<?php

// tests/Feature/ChatProjectLinkTest.php

namespace Tests\Feature;

use App\Models\Bid;
use App\Models\Proposal;
use App\Models\Thread;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ChatProjectLinkTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    public function test_chat_row_links_to_the_projects_detail_on_opportunities(): void
    {
        $thread = Thread::factory()->create(['project_id' => 39218841]);

        $this->actingAs($this->admin())->get('/chats')
            ->assertOk()
            // New tab, filtered to the project, panel popped on arrival.
            ->assertSee('href="'.url('/bids').'?q=39218841&amp;open=1"', false)
            ->assertSee('target="_blank"', false)
            ->assertSee('Open project details');
    }

    public function test_mobile_agent_does_not_get_a_link_they_cannot_follow(): void
    {
        $agent = User::factory()->create(['role' => 'mobile']);
        Thread::factory()->create(['project_id' => 39218841, 'assigned_user_id' => $agent->id]);

        $this->actingAs($agent)->get('/chats')
            ->assertOk()
            ->assertSee('39218841')
            ->assertDontSee('Open project details');
    }

    public function test_open_flag_resolves_the_bid_panel_for_the_project(): void
    {
        $proposal = Proposal::factory()->create(['project_id' => 39218841]);
        $bid = Bid::factory()->create(['proposal_id' => $proposal->id]);

        $this->actingAs($this->admin())->get('/bids?q=39218841&open=1')
            ->assertOk()
            ->assertSee(json_encode(url("/bids/{$bid->id}/detail")), false);
    }

    public function test_open_flag_falls_back_to_the_proposal_panel_when_we_never_bid(): void
    {
        $proposal = Proposal::factory()->create(['project_id' => 55501, 'qualified' => false]);

        $this->actingAs($this->admin())->get('/bids?q=55501&open=1')
            ->assertOk()
            ->assertSee(json_encode(url("/proposals/{$proposal->id}/nq-detail")), false);
    }

    public function test_unknown_project_opens_nothing(): void
    {
        $this->actingAs($this->admin())->get('/bids?q=99999999&open=1')
            ->assertOk()
            ->assertDontSee('openDeepLinkedProject', false);
    }

    /**
     * Bid Insights already deep-links with a bare ?q=. That must keep filtering
     * only — popping a panel there would be a behaviour change nobody asked for.
     */
    public function test_plain_q_deep_link_still_does_not_open_a_panel(): void
    {
        $proposal = Proposal::factory()->create(['project_id' => 39218841]);
        Bid::factory()->create(['proposal_id' => $proposal->id]);

        $this->actingAs($this->admin())->get('/bids?q=39218841')
            ->assertOk()
            ->assertDontSee('openDeepLinkedProject', false);
    }
}
