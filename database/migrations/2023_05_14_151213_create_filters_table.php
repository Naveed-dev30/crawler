<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('filters', function (Blueprint $table) {
            $table->id();
            $table->timestamps();
            $table->boolean('crawler_on');
            $table->double('min_fixed_amount');
            $table->double('max_fixed_amount');
            $table->double('min_hourly_amount');
            $table->double('max_hourly_amount');
            $table->longText('prompt');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('filters');
    }
};
