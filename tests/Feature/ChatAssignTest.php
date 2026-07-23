<?php
// tests/Feature/ChatAssignTest.php

namespace Tests\Feature;

use App\Jobs\SendFcmPushJob;
use App\Models\ActivityLog;
use App\Models\MobileNotification;
use App\Models\Thread;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ChatAssignTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function mobile(array $attrs = []): User
    {
        return User::factory()->create(array_merge(['role' => 'mobile'], $attrs));
    }

    public function test_requires_auth(): void
    {
        $thread = Thread::factory()->create();
        $this->post("/chats/{$thread->id}/assign", ['user_id' => 1])
            ->assertRedirect('/login');
    }

    public function test_forbidden_for_non_admin(): void
    {
        $team = User::factory()->create(['role' => 'team']);
        $thread = Thread::factory()->create();
        $this->actingAs($team)
            ->post("/chats/{$thread->id}/assign", ['user_id' => 1])
            ->assertForbidden();
    }

    public function test_admin_assigns_thread_and_notifies_new_assignee(): void
    {
        Queue::fake();
        $from = $this->mobile(['name' => 'Old Guy']);
        $to = $this->mobile(['name' => 'New Guy']);
        $thread = Thread::factory()->create(['assigned_user_id' => $from->id]);

        $this->actingAs($this->admin())
            ->postJson("/chats/{$thread->id}/assign", ['user_id' => $to->id])
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->assertSame($to->id, $thread->fresh()->assigned_user_id);
        $this->assertDatabaseHas('activity_logs', [
            'thread_id' => $thread->id,
            'from_user_id' => $from->id,
            'to_user_id' => $to->id,
            'type' => 'manual_assign',
        ]);
        $this->assertDatabaseHas('mobile_notifications', [
            'user_id' => $to->id,
            'thread_id' => $thread->id,
        ]);
        Queue::assertPushed(SendFcmPushJob::class, fn ($job) => $job->userId === $to->id);
    }

    public function test_assigning_unassigned_thread_works(): void
    {
        Queue::fake();
        $to = $this->mobile();
        $thread = Thread::factory()->create(['assigned_user_id' => null]);

        $this->actingAs($this->admin())
            ->postJson("/chats/{$thread->id}/assign", ['user_id' => $to->id])
            ->assertOk();

        $this->assertSame($to->id, $thread->fresh()->assigned_user_id);
    }

    public function test_same_user_is_noop(): void
    {
        Queue::fake();
        $current = $this->mobile();
        $thread = Thread::factory()->create(['assigned_user_id' => $current->id]);

        $this->actingAs($this->admin())
            ->postJson("/chats/{$thread->id}/assign", ['user_id' => $current->id])
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->assertSame(0, ActivityLog::count());
        $this->assertSame(0, MobileNotification::count());
        Queue::assertNothingPushed();
    }

    public function test_non_mobile_user_is_rejected(): void
    {
        $team = User::factory()->create(['role' => 'team']);
        $thread = Thread::factory()->create();

        $this->actingAs($this->admin())
            ->postJson("/chats/{$thread->id}/assign", ['user_id' => $team->id])
            ->assertStatus(422);

        $this->assertNull($thread->fresh()->assigned_user_id);
    }

    public function test_unknown_user_is_rejected(): void
    {
        $thread = Thread::factory()->create();

        $this->actingAs($this->admin())
            ->postJson("/chats/{$thread->id}/assign", ['user_id' => 999999])
            ->assertStatus(422);
    }

    public function test_detail_shows_assign_control_with_mobile_users(): void
    {
        $this->mobile(['name' => 'Assignable Dev']);
        $thread = Thread::factory()->create();

        $this->actingAs($this->admin())
            ->get("/chats/{$thread->id}/detail")
            ->assertOk()
            ->assertSee('Assignable Dev')
            ->assertSee('chat-assign-user', false);
    }
}
