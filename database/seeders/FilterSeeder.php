<?php

namespace Database\Seeders;

use App\Models\Filter;
use Illuminate\Database\Seeder;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;

class FilterSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
          $filter =  new Filter();
          $filter->crawler_on = false;
          $filter->min_fixed_amount = 0;
          $filter->max_fixed_amount = 0;
          $filter->min_hourly_amount = 0;
          $filter->max_hourly_amount = 0;
          $filter->prompt = "";
          $filter->save();
    }
}
