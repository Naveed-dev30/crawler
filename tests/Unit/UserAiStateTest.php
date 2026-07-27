<?php

// tests/Unit/UserAiStateTest.php

namespace Tests\Unit;

use App\Models\User;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\TestCase;

class UserAiStateTest extends TestCase
{
    private function user(array $attrs): User
    {
        $u = new User;
        $u->forceFill(array_merge([
            'ai_schedule_enabled' => false,
            'ai_window_start' => null,
            'ai_window_end' => null,
            'ai_timezone' => 'UTC',
            'ai_manual_state' => null,
            'ai_manual_until' => null,
        ], $attrs));

        return $u;
    }

    private function at(string $utc): Carbon
    {
        return Carbon::parse($utc, 'UTC');
    }

    public function test_within_same_day_window(): void
    {
        $u = $this->user(['ai_schedule_enabled' => true, 'ai_window_start' => '09:00:00', 'ai_window_end' => '17:00:00']);
        $this->assertTrue($u->withinWindow($this->at('2026-07-27 10:00:00')));
        $this->assertFalse($u->withinWindow($this->at('2026-07-27 08:00:00')));
        $this->assertFalse($u->withinWindow($this->at('2026-07-27 17:00:00'))); // end exclusive
    }

    public function test_within_overnight_window(): void
    {
        $u = $this->user(['ai_schedule_enabled' => true, 'ai_window_start' => '22:00:00', 'ai_window_end' => '06:00:00']);
        $this->assertTrue($u->withinWindow($this->at('2026-07-27 23:00:00')));
        $this->assertTrue($u->withinWindow($this->at('2026-07-27 02:00:00')));
        $this->assertFalse($u->withinWindow($this->at('2026-07-27 12:00:00')));
    }

    public function test_within_window_respects_timezone(): void
    {
        // 00:00-17:00 Karachi (UTC+5). 20:00 UTC = 01:00 Karachi next day => inside.
        $u = $this->user(['ai_schedule_enabled' => true, 'ai_window_start' => '00:00:00', 'ai_window_end' => '17:00:00', 'ai_timezone' => 'Asia/Karachi']);
        $this->assertTrue($u->withinWindow($this->at('2026-07-27 20:00:00')));
        // 13:00 UTC = 18:00 Karachi => outside.
        $this->assertFalse($u->withinWindow($this->at('2026-07-27 13:00:00')));
    }

    public function test_active_now_schedule_only(): void
    {
        $u = $this->user(['ai_schedule_enabled' => true, 'ai_window_start' => '09:00:00', 'ai_window_end' => '17:00:00']);
        $this->assertTrue($u->aiActiveNow($this->at('2026-07-27 10:00:00')));
        $this->assertFalse($u->aiActiveNow($this->at('2026-07-27 20:00:00')));
    }

    public function test_live_override_beats_schedule(): void
    {
        $u = $this->user([
            'ai_schedule_enabled' => true, 'ai_window_start' => '00:00:00', 'ai_window_end' => '17:00:00',
            'ai_manual_state' => false, 'ai_manual_until' => $this->at('2026-07-27 17:00:00'),
        ]);
        // Inside window but override says off and not yet expired.
        $this->assertFalse($u->aiActiveNow($this->at('2026-07-27 02:00:00')));
    }

    public function test_expired_override_falls_back_to_schedule(): void
    {
        $u = $this->user([
            'ai_schedule_enabled' => true, 'ai_window_start' => '00:00:00', 'ai_window_end' => '17:00:00',
            'ai_manual_state' => false, 'ai_manual_until' => $this->at('2026-07-27 17:00:00'),
        ]);
        // At expiry the schedule is out-of-window anyway => false; before next start still schedule.
        $this->assertFalse($u->aiActiveNow($this->at('2026-07-27 18:00:00')));
        // Next day inside window, override expired => schedule true.
        $this->assertTrue($u->aiActiveNow($this->at('2026-07-28 02:00:00')));
    }

    public function test_sticky_override_without_schedule(): void
    {
        $u = $this->user(['ai_manual_state' => true, 'ai_manual_until' => null]);
        $this->assertTrue($u->aiActiveNow($this->at('2030-01-01 00:00:00')));
    }

    public function test_next_boundary_same_day(): void
    {
        $u = $this->user(['ai_schedule_enabled' => true, 'ai_window_start' => '09:00:00', 'ai_window_end' => '17:00:00']);
        $this->assertSame('2026-07-27T17:00:00+00:00', $u->nextBoundaryAfter($this->at('2026-07-27 10:00:00'))->toIso8601String());
        $this->assertSame('2026-07-27T09:00:00+00:00', $u->nextBoundaryAfter($this->at('2026-07-27 08:00:00'))->toIso8601String());
        $this->assertSame('2026-07-28T09:00:00+00:00', $u->nextBoundaryAfter($this->at('2026-07-27 18:00:00'))->toIso8601String());
    }

    public function test_next_boundary_null_when_no_schedule(): void
    {
        $u = $this->user(['ai_schedule_enabled' => false]);
        $this->assertNull($u->nextBoundaryAfter($this->at('2026-07-27 10:00:00')));
    }
}
