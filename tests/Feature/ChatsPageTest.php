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
        $res->assertSeeInOrder(['3', '2']);
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
}
