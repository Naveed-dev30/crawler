<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Thread>
 */
class ThreadFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition()
    {
        return [
            'freelancer_thread_id' => $this->faker->unique()->numberBetween(1000, 999999),
            'project_id' => $this->faker->unique()->numberBetween(1000, 999999),
            'proposal_id' => \App\Models\Proposal::factory(),
            'assigned_user_id' => null,
            'status' => 'fresh',
            'blocked' => false,
            'last_client_message_at' => now(),
            'last_escalated_at' => null,
            'freelancer_time_updated' => now()->timestamp,
        ];
    }
}
