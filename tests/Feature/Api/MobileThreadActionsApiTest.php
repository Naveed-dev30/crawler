<?php

namespace Tests\Feature\Api;

use App\Models\ActivityLog;
use App\Models\MobileNotification;
use App\Models\Thread;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MobileThreadActionsApiTest extends TestCase
{
    use RefreshDatabase;

    private User $me;
    private Thread $thread;

    protected function setUp(): void
    {
        parent::setUp();
        $this->me = User::factory()->create(['role' => 'mobile', 'escalation_ladder' => 1]);
        $this->thread = Thread::factory()->create(['assigned_user_id' => $this->me->id]);
        Sanctum::actingAs($this->me);
    }

    public function test_block_and_unblock(): void
    {
        $this->postJson("/api/v1/mobile/threads/{$this->thread->id}/block")->assertOk();
        $this->assertTrue($this->thread->fresh()->blocked);

        $this->postJson("/api/v1/mobile/threads/{$this->thread->id}/unblock")->assertOk();
        $this->assertFalse($this->thread->fresh()->blocked);
    }

    public function test_assign_to_another_mobile_user_logs_and_notifies(): void
    {
        Queue::fake();
        $target = User::factory()->create(['role' => 'mobile', 'escalation_ladder' => 2]);

        $this->postJson("/api/v1/mobile/threads/{$this->thread->id}/assign", [
            'user_id' => $target->id,
        ])->assertOk();

        $this->assertSame($target->id, (int) $this->thread->fresh()->assigned_user_id);

        $log = ActivityLog::first();
        $this->assertNotNull($log);
        $this->assertSame('manual_assign', $log->type);

        $this->assertSame(2, MobileNotification::count()); // both parties
    }

    public function test_assign_to_non_mobile_user_rejected(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->postJson("/api/v1/mobile/threads/{$this->thread->id}/assign", [
            'user_id' => $admin->id,
        ])->assertUnprocessable();
    }

    public function test_actions_on_foreign_thread_forbidden(): void
    {
        $other = User::factory()->create(['role' => 'mobile', 'escalation_ladder' => 2]);
        $foreign = Thread::factory()->create(['assigned_user_id' => $other->id]);

        $this->postJson("/api/v1/mobile/threads/{$foreign->id}/block")->assertForbidden();
        $this->postJson("/api/v1/mobile/threads/{$foreign->id}/assign", ['user_id' => $other->id])->assertForbidden();
    }
}
