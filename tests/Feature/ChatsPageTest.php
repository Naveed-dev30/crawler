<?php
// tests/Feature/ChatsPageTest.php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Thread;
use App\Models\ThreadMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ChatsPageTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    public function test_requires_auth(): void
    {
        $this->get('/chats')->assertRedirect('/login');
    }

    public function test_forbidden_for_non_admin(): void
    {
        $team = User::factory()->create(['role' => 'team']);
        $this->actingAs($team)->get('/chats')->assertForbidden();
    }

    public function test_lists_threads_with_assignee_counts_and_badges(): void
    {
        $mobile = User::factory()->create(['role' => 'mobile', 'name' => 'Sara Malik']);
        $thread = Thread::factory()->create([
            'assigned_user_id' => $mobile->id,
            'status' => 'fresh',
            'blocked' => true,
        ]);
        ThreadMessage::factory()->count(3)->create(['thread_id' => $thread->id]);
        ActivityLog::factory()->count(2)->create(['thread_id' => $thread->id, 'type' => 'escalation']);
        ActivityLog::factory()->create(['thread_id' => $thread->id, 'type' => 'manual_assign']);

        $res = $this->actingAs($this->admin())->get('/chats')->assertOk();
        $res->assertSee((string) $thread->project_id);
        $res->assertSee('Sara Malik');
        $res->assertSee('Fresh');
        $res->assertSee('Blocked');
        // 3 messages, 2 escalations (manual_assign not counted)
        $res->assertSee('<td>3</td>', false);
        $res->assertSee('<td>2</td>', false);
    }

    public function test_unassigned_thread_shows_unassigned(): void
    {
        Thread::factory()->create(['assigned_user_id' => null]);

        $this->actingAs($this->admin())->get('/chats')
            ->assertOk()
            ->assertSee('Unassigned');
    }

    public function test_empty_state(): void
    {
        $this->actingAs($this->admin())->get('/chats')
            ->assertOk()
            ->assertSee('No chat threads yet');
    }

    public function test_search_matches_project_id_title_and_assignee(): void
    {
        $sara = User::factory()->create(['role' => 'mobile', 'name' => 'Sara Malik']);
        $match = Thread::factory()->create(['project_id' => 777001, 'assigned_user_id' => $sara->id]);
        Thread::factory()->create(['project_id' => 888002]);

        $admin = $this->admin();

        $this->actingAs($admin)->get('/chats?search=777001')
            ->assertOk()->assertSee('777001')->assertDontSee('888002');

        $this->actingAs($admin)->get('/chats?search=Sara')
            ->assertOk()->assertSee('777001')->assertDontSee('888002');
    }

    public function test_status_filter(): void
    {
        Thread::factory()->create(['project_id' => 111001, 'status' => 'fresh']);
        Thread::factory()->create(['project_id' => 222002, 'status' => 'replied']);
        Thread::factory()->create(['project_id' => 333003, 'blocked' => true]);

        $admin = $this->admin();

        $this->actingAs($admin)->get('/chats?status=replied')
            ->assertOk()->assertSee('222002')->assertDontSee('111001');

        $this->actingAs($admin)->get('/chats?status=blocked')
            ->assertOk()->assertSee('333003')->assertDontSee('222002');
    }

    public function test_paginates_at_20(): void
    {
        Thread::factory()->count(25)->create();

        $this->actingAs($this->admin())->get('/chats')
            ->assertOk()
            ->assertSee('page=2');
    }

    public function test_detail_shows_messages_timeline_and_header(): void
    {
        $ali = User::factory()->create(['role' => 'mobile', 'name' => 'Ali Raza', 'escalation_ladder' => 1]);
        $sara = User::factory()->create(['role' => 'mobile', 'name' => 'Sara Malik', 'escalation_ladder' => 2]);
        $thread = Thread::factory()->create(['assigned_user_id' => $sara->id]);

        ThreadMessage::factory()->create([
            'thread_id' => $thread->id,
            'direction' => 'received',
            'message' => 'Client asks about budget',
            'message_time' => now()->subHour(),
        ]);
        ThreadMessage::factory()->create([
            'thread_id' => $thread->id,
            'direction' => 'sent',
            'sender_user_id' => $sara->id,
            'message' => 'Our reply text',
            'message_time' => now(),
        ]);
        ActivityLog::factory()->create([
            'thread_id' => $thread->id,
            'type' => 'escalation',
            'from_user_id' => $ali->id,
            'to_user_id' => $sara->id,
        ]);

        $res = $this->actingAs($this->admin())
            ->get("/chats/{$thread->id}/detail", ['X-Requested-With' => 'XMLHttpRequest'])
            ->assertOk();

        $res->assertSee('Client asks about budget');
        $res->assertSee('Our reply text');
        $res->assertSee('Escalated');
        $res->assertSeeInOrder(['Ali Raza', 'Sara Malik']);
        $res->assertSee('AI matched to Ali Raza'); // synthetic first row: earliest log's from-user
        $res->assertSee('ladder 2');
    }

    public function test_detail_without_logs_uses_current_assignee_for_ai_row(): void
    {
        $sara = User::factory()->create(['role' => 'mobile', 'name' => 'Sara Malik']);
        $thread = Thread::factory()->create(['assigned_user_id' => $sara->id]);

        $this->actingAs($this->admin())
            ->get("/chats/{$thread->id}/detail")
            ->assertOk()
            ->assertSee('AI matched to Sara Malik');
    }

    public function test_detail_unknown_thread_is_404(): void
    {
        $this->actingAs($this->admin())->get('/chats/999999/detail')->assertNotFound();
    }

    public function test_attachment_with_unsafe_url_scheme_is_not_linked(): void
    {
        $thread = Thread::factory()->create();
        $message = ThreadMessage::factory()->create(['thread_id' => $thread->id]);
        \App\Models\ThreadAttachment::factory()->create([
            'thread_message_id' => $message->id,
            'filename' => 'evil.txt',
            'url' => 'javascript:alert(1)',
        ]);
        \App\Models\ThreadAttachment::factory()->create([
            'thread_message_id' => $message->id,
            'filename' => 'safe.pdf',
            'url' => 'https://example.com/safe.pdf',
        ]);

        $res = $this->actingAs($this->admin())->get("/chats/{$thread->id}/detail")->assertOk();
        $res->assertSee('evil.txt');
        $res->assertDontSee('javascript:alert(1)', false);
        $res->assertSee('https://example.com/safe.pdf', false);
    }
}
