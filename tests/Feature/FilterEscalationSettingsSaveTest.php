<?php

namespace Tests\Feature;

use App\Models\Filter;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FilterEscalationSettingsSaveTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function basePayload(Filter $filter): array
    {
        return [
            'formValidationPrompt' => $filter->prompt ?? 'p',
            'formValidationMinHourly' => 10,
            'formValidationMinFixed' => 100,
            'allocation_prompt' => '',
            'transitions_payload' => '[]',
        ];
    }

    public function test_escalation_minutes_saves_whitelisted_value(): void
    {
        $filter = Filter::factory()->create(['escalation_minutes' => 30]);

        $this->actingAs($this->admin())->post('/updateFilters', $this->basePayload($filter) + [
            'formValidationEscalationMinutes' => 480,
        ]);

        $this->assertSame(480, (int) $filter->fresh()->escalation_minutes);
    }

    public function test_escalation_minutes_accepts_any_positive_value(): void
    {
        // The fixed dropdown became a free numeric input, so large windows are
        // legitimate. Only values below 1 fall back.
        $filter = Filter::factory()->create(['escalation_minutes' => 120]);

        $this->actingAs($this->admin())->post('/updateFilters', $this->basePayload($filter) + [
            'formValidationEscalationMinutes' => 999,
        ]);

        // 999 >= 1 so it is saved as-is (whitelist was removed)
        $this->assertSame(999, (int) $filter->fresh()->escalation_minutes);
    }

    public function test_escalation_minutes_below_1_falls_back_to_30(): void
    {
        $filter = Filter::factory()->create(['escalation_minutes' => 120]);

        $this->actingAs($this->admin())->post('/updateFilters', $this->basePayload($filter) + [
            'formValidationEscalationMinutes' => 0,
        ]);

        $this->assertSame(30, (int) $filter->fresh()->escalation_minutes);
    }
}
