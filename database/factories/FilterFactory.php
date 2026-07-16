<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Filter>
 */
class FilterFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition()
    {
        return [
            'crawler_on'       => true,
            'min_fixed_amount' => 100,
            'max_fixed_amount' => 5000,
            'min_hourly_amount'=> 10,
            'max_hourly_amount'=> 100,
            'prompt'           => 'Default prompt',
            'useminfix'        => false,
            'useminhour'       => false,
            'usekeywords'      => false,
            'usecountries'     => false,
        ];
    }
}
