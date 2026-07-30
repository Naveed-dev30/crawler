<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\DeviceToken>
 */
class DeviceTokenFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            // Length and shape roughly match a real FCM registration token.
            'token' => Str::random(22).':APA91b'.Str::random(134),
            'platform' => 'android',
            'device_name' => 'mobile-app',
            'sound_key' => 'default',
            'sound_enabled' => true,
            'last_used_at' => now(),
        ];
    }

    public function ios(): static
    {
        return $this->state(fn () => ['platform' => 'ios']);
    }

    public function chime(): static
    {
        return $this->state(fn () => ['sound_key' => 'chime']);
    }

    /** Sound switched off — pushes go to the silent channel, no aps.sound. */
    public function silent(): static
    {
        return $this->state(fn () => ['sound_enabled' => false]);
    }
}
