<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ThreadMessage>
 */
class ThreadMessageFactory extends Factory
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
            'freelancer_message_id' => $this->faker->unique()->numberBetween(1000, 9999999),
            'direction' => 'received',
            'from_freelancer_user_id' => $this->faker->numberBetween(1000, 999999),
            'sender_user_id' => null,
            'message' => $this->faker->sentence(),
            'message_time' => now(),
        ];
    }
}
