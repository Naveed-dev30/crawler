<?php

namespace Database\Factories;

use App\Models\UpworkJob;
use Illuminate\Database\Eloquent\Factories\Factory;

class UpworkJobFactory extends Factory
{
    protected $model = UpworkJob::class;

    public function definition(): array
    {
        $id = '~0'.$this->faker->unique()->bothify('##########');

        return [
            'job_id' => $id,
            'title' => $this->faker->sentence(4),
            'description' => $this->faker->paragraph(),
            'url' => 'https://www.upwork.com/jobs/'.$id,
            'job_type' => $this->faker->randomElement(['hourly', 'fixed']),
            'budget_amount' => $this->faker->numberBetween(100, 5000),
            'hourly_min' => null,
            'hourly_max' => null,
            'currency' => 'USD',
            'posted_at' => $this->faker->dateTimeBetween('-10 days', 'now'),
            'skills' => ['PHP', 'Laravel'],
            'client_country' => $this->faker->country(),
            'client_total_spent' => $this->faker->numberBetween(0, 50000),
            'client_payment_verified' => $this->faker->boolean(),
        ];
    }
}
