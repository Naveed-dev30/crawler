<?php

namespace Tests\Feature;

use App\Models\Filter;
use App\Models\MobileNotification;
use App\Models\Proposal;
use App\Models\Thread;
use App\Models\User;
use App\Services\ThreadEscalator;
use App\Support\NotificationType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Regression cover for the mobile-path bugs fixed alongside the push work.
 */
class MobileApiFixesTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsMobile(): User
    {
        $user = User::factory()->mobile()->create();
        Sanctum::actingAs($user);

        return $user;
    }

    public function test_a_newly_assigned_thread_sorts_to_the_top(): void
    {
        $user = $this->actingAsMobile();
        $proposal = Proposal::factory()->create(['project_id' => 1]);

        // An older conversation that has seen client traffic...
        Thread::factory()->create([
            'assigned_user_id' => $user->id,
            'proposal_id' => $proposal->id,
            'last_client_message_at' => Carbon::parse('2026-07-01 10:00:00'),
            'created_at' => Carbon::parse('2026-07-01 09:00:00'),
        ]);
        // ...and a brand-new assignment with no client message yet.
        $fresh = Thread::factory()->create([
            'assigned_user_id' => $user->id,
            'proposal_id' => $proposal->id,
            'last_client_message_at' => null,
            'created_at' => Carbon::parse('2026-07-28 10:00:00'),
        ]);

        $ids = collect($this->getJson('/api/v1/mobile/threads')->assertOk()->json('data'))
            ->pluck('id')
            ->all();

        // NULL sorts last in a DESC order, so the new project used to land at
        // the BOTTOM of the list — the opposite of what the user expects.
        $this->assertSame($fresh->id, $ids[0]);
    }

    public function test_notifications_meta_reports_unread_across_every_page(): void
    {
        $user = $this->actingAsMobile();

        MobileNotification::factory()->count(60)->create([
            'user_id' => $user->id,
            'read_at' => null,
        ]);
        MobileNotification::factory()->count(5)->create([
            'user_id' => $user->id,
            'read_at' => now(),
        ]);

        $res = $this->getJson('/api/v1/mobile/notifications')->assertOk();

        // The page holds 50, but the badge must reflect all 60 unread — this
        // was counted client-side over the loaded page only.
        $this->assertCount(50, $res->json('data'));
        $this->assertSame(60, $res->json('meta.unread_count'));
    }

    public function test_notifications_expose_their_type(): void
    {
        $user = $this->actingAsMobile();
        MobileNotification::factory()->create([
            'user_id' => $user->id,
            'type' => NotificationType::THREAD_ESCALATED,
        ]);

        $this->getJson('/api/v1/mobile/notifications')
            ->assertOk()
            ->assertJsonPath('data.0.type', NotificationType::THREAD_ESCALATED);
    }

    public function test_unread_count_ignores_other_users(): void
    {
        $user = $this->actingAsMobile();
        MobileNotification::factory()->count(2)->create(['user_id' => $user->id, 'read_at' => null]);
        MobileNotification::factory()->count(7)->create([
            'user_id' => User::factory()->mobile()->create()->id,
            'read_at' => null,
        ]);

        $this->getJson('/api/v1/mobile/notifications')
            ->assertOk()
            ->assertJsonPath('meta.unread_count', 2);
    }

    public function test_a_missing_thread_does_not_leak_the_model_class(): void
    {
        $this->actingAsMobile();

        $res = $this->getJson('/api/v1/mobile/threads/999999')->assertNotFound();

        $this->assertSame('Not found.', $res->json('message'));
        $this->assertStringNotContainsString('App\\Models', (string) $res->json('message'));
    }

    public function test_escalation_skips_over_a_gap_in_the_ladder(): void
    {
        Queue::fake();
        Filter::factory()->create(['id' => 1, 'escalation_minutes' => 30]);

        $first = User::factory()->mobile()->create(['escalation_ladder' => 1]);
        // No user at rung 2 — escalation used to stop dead here.
        $third = User::factory()->mobile()->create(['escalation_ladder' => 4]);

        $thread = Thread::factory()->create([
            'status' => 'fresh',
            'blocked' => false,
            'assigned_user_id' => $first->id,
            'last_client_message_at' => now()->subHours(2),
        ]);

        app(ThreadEscalator::class)->run();

        $this->assertSame($third->id, (int) $thread->fresh()->assigned_user_id);
    }

    public function test_a_future_dated_client_message_does_not_escalate_immediately(): void
    {
        Queue::fake();
        Filter::factory()->create(['id' => 1, 'escalation_minutes' => 30]);

        $first = User::factory()->mobile()->create(['escalation_ladder' => 1]);
        User::factory()->mobile()->create(['escalation_ladder' => 2]);

        // Freelancer clock skew. diffInMinutes() is ABSOLUTE in Carbon 2, so a
        // timestamp in the future read as long overdue.
        $thread = Thread::factory()->create([
            'status' => 'fresh',
            'blocked' => false,
            'assigned_user_id' => $first->id,
            'last_client_message_at' => now()->addHours(2),
        ]);

        app(ThreadEscalator::class)->run();

        $this->assertSame($first->id, (int) $thread->fresh()->assigned_user_id);
    }

    public function test_a_thread_still_inside_the_window_is_not_escalated(): void
    {
        Queue::fake();
        Filter::factory()->create(['id' => 1, 'escalation_minutes' => 30]);

        $first = User::factory()->mobile()->create(['escalation_ladder' => 1]);
        User::factory()->mobile()->create(['escalation_ladder' => 2]);

        $thread = Thread::factory()->create([
            'status' => 'fresh',
            'blocked' => false,
            'assigned_user_id' => $first->id,
            'last_client_message_at' => now()->subMinutes(5),
        ]);

        app(ThreadEscalator::class)->run();

        $this->assertSame($first->id, (int) $thread->fresh()->assigned_user_id);
    }
}
