<?php

namespace Tests\Feature;

use App\Models\Transition;
use App\Models\TransitionUser;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TransitionModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_users_are_returned_in_position_order(): void
    {
        $t = Transition::factory()->create(['number' => 5]);
        $a = User::factory()->create(['role' => 'mobile']);
        $b = User::factory()->create(['role' => 'mobile']);
        TransitionUser::factory()->create(['transition_id' => $t->id, 'user_id' => $b->id, 'position' => 1]);
        TransitionUser::factory()->create(['transition_id' => $t->id, 'user_id' => $a->id, 'position' => 0]);

        $this->assertSame([$a->id, $b->id], $t->users->pluck('user_id')->all());
    }

    public function test_user_cannot_appear_twice_in_a_transition(): void
    {
        $t = Transition::factory()->create();
        $u = User::factory()->create(['role' => 'mobile']);
        TransitionUser::factory()->create(['transition_id' => $t->id, 'user_id' => $u->id, 'position' => 0]);

        $this->expectException(QueryException::class);
        TransitionUser::factory()->create(['transition_id' => $t->id, 'user_id' => $u->id, 'position' => 1]);
    }
}
