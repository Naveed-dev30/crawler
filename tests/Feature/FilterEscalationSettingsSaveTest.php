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

    public function test_invalid_escalation_minutes_falls_back_to_30(): void
    {
        $filter = Filter::factory()->create(['escalation_minutes' => 120]);

        $this->actingAs($this->admin())->post('/updateFilters', $this->basePayload($filter) + [
            'formValidationEscalationMinutes' => 999,
        ]);

        $this->assertSame(30, (int) $filter->fresh()->escalation_minutes);
    }

    public function test_profile_match_prompt_saves(): void
    {
        $filter = Filter::factory()->create();

        $this->actingAs($this->admin())->post('/updateFilters', $this->basePayload($filter) + [
            'formValidationProfileMatchPrompt' => 'Route threads by these rules',
        ]);

        $this->assertSame('Route threads by these rules', $filter->fresh()->profile_match_prompt);
    }
}
