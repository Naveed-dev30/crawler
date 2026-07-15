<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Proposal>
 */
class ProposalFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition()
    {
        return [
            'project_id' => $this->faker->numberBetween(1000, 9999),
            'title' => $this->faker->sentence(4),
            'description' => $this->faker->paragraph(),
            'min_budget' => 100,
            'max_budget' => 500,
            'type' => 'fixed',
            'country' => $this->faker->country(),
            'currency_name' => 'USD',
            'exchange_rate' => 1,
            'skills' => [],
        ];
    }
}
