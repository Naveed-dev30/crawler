<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ActivityLog>
 */
class ActivityLogFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition()
    {
        return [
            'thread_id' => \App\Models\Thread::factory(),
            'from_user_id' => \App\Models\User::factory(),
            'to_user_id' => \App\Models\User::factory(),
            'type' => 'escalation',
            'message' => $this->faker->sentence(),
        ];
    }
}
