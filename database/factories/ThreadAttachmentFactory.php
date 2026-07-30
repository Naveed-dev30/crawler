<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ThreadAttachment>
 */
class ThreadAttachmentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition()
    {
        return [
            'thread_message_id' => \App\Models\ThreadMessage::factory(),
            'freelancer_attachment_id' => $this->faker->unique()->numberBetween(1000, 9999999),
            'filename' => $this->faker->word() . '.pdf',
            'url' => $this->faker->url(),
            'mime_type' => 'application/pdf',
            'size' => $this->faker->numberBetween(1000, 5000000),
        ];
    }
}
