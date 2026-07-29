<?php

namespace Tests\Feature;

use App\Models\Filter;
use App\Models\Transition;
use App\Models\TransitionUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AllocationConfigSaveTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function base(array $override = []): array
    {
        return array_merge([
            'formValidationEscalationMinutes' => 30,
            'allocation_prompt' => 'Route projects by skill',
            'transitions_payload' => '[]',
        ], $override);
    }

    public function test_profile_match_prompt_field_is_gone(): void
    {
        Filter::factory()->create(['id' => 1]);
        $this->actingAs($this->admin())->get('/filters')
            ->assertOk()
            ->assertDontSee('Step 4 - Profile Match Prompt')
            ->assertDontSee('formValidationProfileMatchPrompt');
    }

    public function test_allocation_prompt_and_transitions_are_saved(): void
    {
        Filter::factory()->create(['id' => 1]);
        $abid = User::factory()->create(['role' => 'mobile']);
        $irfan = User::factory()->create(['role' => 'mobile']);

        $payload = json_encode([
            ['number' => 7, 'user_ids' => [$abid->id, $irfan->id]],
        ]);

        $this->actingAs($this->admin())
            ->post('/updateFilters', $this->base(['transitions_payload' => $payload]))
            ->assertRedirect('/filters');

        $this->assertSame('Route projects by skill', Filter::find(1)->allocation_prompt);
        $t = Transition::where('number', 7)->first();
        $this->assertNotNull($t);
        $this->assertSame(
            [$abid->id, $irfan->id],
            TransitionUser::where('transition_id', $t->id)->orderBy('position')->pluck('user_id')->all()
        );
    }

    public function test_saving_replaces_previous_transitions(): void
    {
        Filter::factory()->create(['id' => 1]);
        $u = User::factory()->create(['role' => 'mobile']);
        $stale = Transition::factory()->create(['number' => 99]);
        TransitionUser::factory()->create(['transition_id' => $stale->id, 'user_id' => $u->id, 'position' => 0]);

        $payload = json_encode([['number' => 1, 'user_ids' => [$u->id]]]);
        $this->actingAs($this->admin())->post('/updateFilters', $this->base(['transitions_payload' => $payload]));

        $this->assertNull(Transition::where('number', 99)->first());
        $this->assertNotNull(Transition::where('number', 1)->first());
    }
}
