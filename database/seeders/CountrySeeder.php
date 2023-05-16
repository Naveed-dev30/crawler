<?php

namespace Database\Seeders;

use App\Models\Country;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class CountrySeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        // Pakistan
        $pakistan = new Country();
        $pakistan->id = 1;
        $pakistan->currency_id = 1;
        $pakistan->country = 'Pakistan';
        $pakistan->language = 'Urdu';
        $pakistan->save();

        // US
        $us = new Country();
        $us->id = 2;
        $us->currency_id = 2;
        $us->country = 'United States';
        $us->language = 'English';
        $us->save();

        // United Kingdom
        $uk = new Country();
        $uk->id = 3;
        $uk->currency_id = 3;
        $uk->country = 'United Kingdom';
        $uk->language = 'English';
        $uk->save();

        // Canada
        $canada = new Country();
        $canada->id = 4;
        $canada->currency_id = 4;
        $canada->country = 'Canada';
        $canada->language = 'English';
        $canada->save();

    }
}
