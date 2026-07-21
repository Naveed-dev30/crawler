<?php

namespace Tests\Feature\Api;

use App\Models\ActivityLog;
use App\Models\MobileNotification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MobileMiscApiTest extends TestCase
{
    use RefreshDatabase;

    private User $me;

    protected function setUp(): void
    {
        parent::setUp();
        $this->me = User::factory()->create(['role' => 'mobile', 'escalation_ladder' => 1]);
        Sanctum::actingAs($this->me);
    }

    public function test_logs_lists_only_entries_involving_me(): void
    {
        $other = User::factory()->create(['role' => 'mobile', 'escalation_ladder' => 2]);
        $third = User::factory()->create(['role' => 'mobile', 'escalation_ladder' => 3]);

        $from = ActivityLog::factory()->create(['from_user_id' => $this->me->id, 'to_user_id' => $other->id]);
        $to = ActivityLog::factory()->create(['from_user_id' => $other->id, 'to_user_id' => $this->me->id]);
        ActivityLog::factory()->create(['from_user_id' => $other->id, 'to_user_id' => $third->id]);

        $response = $this->getJson('/api/v1/mobile/logs')->assertOk();

        $ids = collect($response->json('data'))->pluck('id')->sort()->values()->all();
        $this->assertSame([$from->id, $to->id], $ids);
    }

    public function test_notifications_are_mine_only_and_can_be_marked_read(): void
    {
        $other = User::factory()->create(['role' => 'mobile', 'escalation_ladder' => 2]);
        $mine = MobileNotification::factory()->create(['user_id' => $this->me->id]);
        MobileNotification::factory()->create(['user_id' => $other->id]);

        $response = $this->getJson('/api/v1/mobile/notifications')->assertOk();
        $this->assertSame([$mine->id], collect($response->json('data'))->pluck('id')->all());

        $this->postJson("/api/v1/mobile/notifications/{$mine->id}/read")->assertOk();
        $this->assertNotNull($mine->fresh()->read_at);
    }

    public function test_cannot_mark_foreign_notification_read(): void
    {
        $other = User::factory()->create(['role' => 'mobile', 'escalation_ladder' => 2]);
        $foreign = MobileNotification::factory()->create(['user_id' => $other->id]);

        $this->postJson("/api/v1/mobile/notifications/{$foreign->id}/read")->assertForbidden();
    }

    public function test_users_list_excludes_me_and_non_mobile_roles(): void
    {
        $other = User::factory()->create(['role' => 'mobile', 'escalation_ladder' => 2]);
        User::factory()->create(['role' => 'admin']);
        User::factory()->create(['role' => 'team']);

        $response = $this->getJson('/api/v1/mobile/users')->assertOk();

        $this->assertSame([$other->id], collect($response->json('data'))->pluck('id')->all());
    }
}
