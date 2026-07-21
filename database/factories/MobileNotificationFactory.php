<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\MobileNotification>
 */
class MobileNotificationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition()
    {
        return [
            'user_id' => \App\Models\User::factory(),
            'thread_id' => \App\Models\Thread::factory(),
            'title' => $this->faker->sentence(3),
            'body' => $this->faker->sentence(),
            'read_at' => null,
        ];
    }
}
