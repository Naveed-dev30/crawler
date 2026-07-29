<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class TransitionFactory extends Factory
{
    public function definition(): array
    {
        return ['number' => fake()->unique()->numberBetween(1, 9999)];
    }
}
