<?php

namespace Database\Seeders;

use App\Models\Currency;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class CurrencySeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        // Urdu
        $urdu = new Currency();
        $urdu->id = 1;
        $urdu->currency_name = 'Urdu';
        $urdu->curreny_symbol = 'PKR';
        $urdu->save();

        // USD
        $usd = new Currency();
        $usd->id = 2;
        $usd->currency_name = 'Dollar';
        $usd->curreny_symbol = '$';
        $usd->save();

        // UK
        $uk = new Currency();
        $uk->id = 3;
        $uk->currency_name = 'Pound';
        $uk->curreny_symbol = '£';
        $uk->save();

        // CAD
        $cad = new Currency();
        $cad->id = 4;
        $cad->currency_name = 'Candian Dollar';
        $cad->curreny_symbol = 'CAD';
        $cad->save();
    }
}
