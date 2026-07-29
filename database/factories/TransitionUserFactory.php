<?php

namespace Database\Factories;

use App\Models\Transition;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class TransitionUserFactory extends Factory
{
    public function definition(): array
    {
        return [
            'transition_id' => Transition::factory(),
            'user_id' => User::factory(),
            'position' => 0,
        ];
    }
}
