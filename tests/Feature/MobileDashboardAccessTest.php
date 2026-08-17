<?php

// tests/Feature/MobileDashboardAccessTest.php

namespace Tests\Feature;

use App\Models\Filter;
use App\Models\Thread;
use App\Models\ThreadMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MobileDashboardAccessTest extends TestCase
{
    use RefreshDatabase;

    private function agent(string $name = 'Sara Malik'): User
    {
        return User::factory()->create(['role' => 'mobile', 'name' => $name]);
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    public function test_mobile_agent_can_sign_in_and_lands_on_chats(): void
    {
        $agent = $this->agent();

        $this->post('/auth', ['email' => $agent->email, 'password' => 'password'])
            ->assertRedirect('/');

        $this->assertAuthenticatedAs($agent);

        // "/" is the post-login target for everyone; a chat-only session is
        // carried on to the single screen it can actually use.
        $this->actingAs($agent)->get('/')->assertRedirect('/chats');
        $this->actingAs($agent)->get('/chats')->assertOk();
    }

    /**
     * @dataProvider offLimitsPages
     */
    public function test_mobile_agent_is_bounced_off_every_other_page(string $url): void
    {
        $this->actingAs($this->agent())->get($url)->assertRedirect('/chats');
    }

    public static function offLimitsPages(): array
    {
        return [
            'dashboard' => ['/'],
            'bids' => ['/bids'],
            'upwork' => ['/bids/upwork'],
            'leaderboard' => ['/leaderboard'],
            'profile insights' => ['/insights'],
            'bid insights' => ['/insights/bids'],
            'review' => ['/review'],
            'settings' => ['/filters'],
            'users' => ['/users'],
        ];
    }

    /**
     * A redirect is the right answer for a navigation, but a write has no page
     * to land on — it must be refused outright.
     */
    public function test_mobile_agent_cannot_post_to_an_off_limits_route(): void
    {
        $this->actingAs($this->agent())->post('/updateFilters', [])->assertForbidden();
        $this->actingAs($this->agent())->post('/profiles/sync')->assertForbidden();
    }

    public function test_admin_is_unaffected(): void
    {
        Filter::factory()->create(['id' => 1]); // the settings page needs its singleton row
        $admin = $this->admin();

        $this->actingAs($admin)->get('/')->assertOk();
        $this->actingAs($admin)->get('/filters')->assertOk();
        $this->actingAs($admin)->get('/chats')->assertOk();
    }

    public function test_team_role_still_has_no_chats_access(): void
    {
        $team = User::factory()->create(['role' => 'team']);

        $this->actingAs($team)->get('/chats')->assertForbidden();
        // …and is not swept up by the mobile restriction either.
        $this->actingAs($team)->get('/bids')->assertOk();
    }

    public function test_chats_list_shows_only_the_agents_own_threads(): void
    {
        $agent = $this->agent();
        $other = $this->agent('Ali Raza');

        $mine = Thread::factory()->create(['project_id' => 555001, 'assigned_user_id' => $agent->id]);
        Thread::factory()->create(['project_id' => 555002, 'assigned_user_id' => $other->id]);
        Thread::factory()->create(['project_id' => 555003, 'assigned_user_id' => null]);

        $res = $this->actingAs($agent)->get('/chats')->assertOk();
        $res->assertSee((string) $mine->project_id);
        $res->assertDontSee('555002');
        $res->assertDontSee('555003');

        // The polled rows endpoint has to be scoped too, or the list would
        // repopulate with everyone's threads a few seconds after load.
        $rows = $this->actingAs($agent)->get('/chats/rows')->assertOk();
        $rows->assertSee('555001');
        $rows->assertDontSee('555002');
    }

    public function test_admin_still_sees_every_thread(): void
    {
        $agent = $this->agent();
        Thread::factory()->create(['project_id' => 666001, 'assigned_user_id' => $agent->id]);
        Thread::factory()->create(['project_id' => 666002, 'assigned_user_id' => null]);

        $res = $this->actingAs($this->admin())->get('/chats')->assertOk();
        $res->assertSee('666001');
        $res->assertSee('666002');
    }

    public function test_agent_cannot_open_a_colleagues_thread(): void
    {
        $agent = $this->agent();
        $other = $this->agent('Ali Raza');
        $theirs = Thread::factory()->create(['assigned_user_id' => $other->id]);

        $this->actingAs($agent)->get("/chats/{$theirs->id}/detail")->assertForbidden();
    }

    public function test_agent_can_open_and_reply_to_their_own_thread(): void
    {
        config(['variables.flBase' => 'https://www.freelancer.com', 'variables.flKey' => 'k']);
        Http::fake([
            'https://www.freelancer.com/api/messages/0.1/threads/*/messages/*' => Http::response(
                ['result' => ['id' => 1]], 200
            ),
        ]);

        $agent = $this->agent();
        $mine = Thread::factory()->create(['assigned_user_id' => $agent->id]);

        $this->actingAs($agent)->get("/chats/{$mine->id}/detail")->assertOk()->assertSee('chat-reply-form', false);

        $this->actingAs($agent)->postJson("/chats/{$mine->id}/message", ['message' => 'on it'])
            ->assertOk();

        $this->assertSame($agent->id, (int) ThreadMessage::sole()->sender_user_id);
    }

    public function test_agent_cannot_reply_to_or_reassign_a_colleagues_thread(): void
    {
        Http::fake();
        $agent = $this->agent();
        $other = $this->agent('Ali Raza');
        $theirs = Thread::factory()->create(['assigned_user_id' => $other->id]);

        $this->actingAs($agent)->postJson("/chats/{$theirs->id}/message", ['message' => 'nosy'])
            ->assertForbidden();
        $this->actingAs($agent)->postJson("/chats/{$theirs->id}/assign", ['user_id' => $agent->id])
            ->assertForbidden();
        $this->actingAs($agent)->postJson("/chats/{$theirs->id}/unblock")
            ->assertForbidden();

        $this->assertSame(0, ThreadMessage::count());
        Http::assertNothingSent();
    }

    public function test_sidebar_offers_chats_only_to_a_mobile_agent(): void
    {
        $res = $this->actingAs($this->agent())->get('/chats')->assertOk();

        $res->assertSee('href="'.url('/chats').'"', false);
        foreach (['/bids', '/leaderboard', '/insights', '/filters', '/users'] as $hidden) {
            $res->assertDontSee('href="'.url($hidden).'"', false);
        }
    }

    public function test_sidebar_is_unchanged_for_an_admin(): void
    {
        $res = $this->actingAs($this->admin())->get('/chats')->assertOk();

        foreach (['/chats', '/bids', '/leaderboard', '/filters', '/users'] as $shown) {
            $res->assertSee('href="'.url($shown).'"', false);
        }
    }
}
