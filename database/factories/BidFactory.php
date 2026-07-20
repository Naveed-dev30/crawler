<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Bid>
 */
class BidFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition()
    {
        return [
            'proposal_id' => \App\Models\Proposal::factory(),
            'bid_status' => 'pending',
            'price' => $this->faker->numberBetween(50, 5000),
            'cover_letter' => $this->faker->paragraph(),
            'admin_feedback' => null,
            'awarded' => false,
            'error_message' => '',
        ];
    }
}
