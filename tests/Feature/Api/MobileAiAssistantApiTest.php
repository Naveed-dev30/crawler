<?php

// tests/Feature/Api/MobileAiAssistantApiTest.php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MobileAiAssistantApiTest extends TestCase
{
    use RefreshDatabase;

    private User $me;

    protected function setUp(): void
    {
        parent::setUp();
        $this->me = User::factory()->create(['role' => 'mobile', 'escalation_ladder' => 1]);
        Sanctum::actingAs($this->me);
    }

    public function test_show_defaults_to_inactive(): void
    {
        $this->getJson('/api/v1/mobile/ai-assistant')
            ->assertOk()
            ->assertJsonPath('data.active_now', false)
            ->assertJsonPath('data.schedule_enabled', false)
            ->assertJsonPath('data.window', null)
            ->assertJsonPath('data.manual_override', null);
    }

    public function test_schedule_sets_window_and_activates(): void
    {
        Carbon::setTestNow('2026-07-27 10:00:00'); // UTC inside 09-17

        $this->putJson('/api/v1/mobile/ai-assistant/schedule', [
            'enabled' => true, 'start' => '09:00', 'end' => '17:00', 'timezone' => 'UTC',
        ])->assertOk()
            ->assertJsonPath('data.schedule_enabled', true)
            ->assertJsonPath('data.window.start', '09:00')
            ->assertJsonPath('data.active_now', true);

        Carbon::setTestNow();
    }

    public function test_toggle_off_during_window_writes_expiring_override(): void
    {
        Carbon::setTestNow('2026-07-27 10:00:00');

        $this->me->forceFill([
            'ai_schedule_enabled' => true, 'ai_window_start' => '09:00:00',
            'ai_window_end' => '17:00:00', 'ai_timezone' => 'UTC',
        ])->save();

        $this->putJson('/api/v1/mobile/ai-assistant', ['enabled' => false])
            ->assertOk()
            ->assertJsonPath('data.active_now', false)
            ->assertJsonPath('data.manual_override.state', false);

        $fresh = $this->me->fresh();
        $this->assertFalse($fresh->ai_manual_state);
        $this->assertSame('2026-07-27T17:00:00+00:00', $fresh->ai_manual_until->toIso8601String());

        Carbon::setTestNow();
    }

    public function test_toggle_on_without_schedule_is_sticky(): void
    {
        $this->putJson('/api/v1/mobile/ai-assistant', ['enabled' => true])
            ->assertOk()
            ->assertJsonPath('data.active_now', true);

        $this->assertNull($this->me->fresh()->ai_manual_until);
    }

    public function test_schedule_validates_times(): void
    {
        $this->putJson('/api/v1/mobile/ai-assistant/schedule', [
            'enabled' => true, 'start' => '09:00', 'end' => '09:00', 'timezone' => 'UTC',
        ])->assertStatus(422);
    }

    public function test_globally_disabled_is_false_while_the_kill_switch_is_on(): void
    {
        $this->getJson('/api/v1/mobile/ai-assistant')
            ->assertOk()
            ->assertJsonPath('data.globally_disabled', false);
    }

    public function test_kill_switch_reports_globally_disabled_without_clearing_the_override(): void
    {
        // The user's own toggle stays ON; only the admin switch is off. The app
        // needs both facts to say "disabled by admin" instead of flipping the
        // user's own switch to off behind their back.
        $this->putJson('/api/v1/mobile/ai-assistant', ['enabled' => true])->assertOk();

        config(['variables.aiAutoReplyEnabled' => false]);

        $this->getJson('/api/v1/mobile/ai-assistant')
            ->assertOk()
            ->assertJsonPath('data.globally_disabled', true)
            ->assertJsonPath('data.active_now', false)
            ->assertJsonPath('data.manual_override.state', true);
    }

    public function test_forbidden_for_non_mobile(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $this->getJson('/api/v1/mobile/ai-assistant')->assertForbidden();
    }
}
