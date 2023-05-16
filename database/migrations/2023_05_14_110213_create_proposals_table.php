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
        Schema::create('proposals', function (Blueprint $table) {
            $table->id();
            $table->timestamps();
            $table->integer('project_id')->nullable();
            $table->string('title')->nullable();
            $table->longText('description')->nullable();
            $table->double('min_budget')->nullable();
            $table->double('max_budget')->nullable();
            $table->string('language')->nullable();
            $table->integer('project_owner')->nullable();
            $table->string('seo_url')->nullable();
            $table->string('currency_symbol')->nullable();
            $table->string('currency_name')->nullable();
            $table->string('country')->nullable();
            $table->string('type')->nullable();
            $table->integer('project_added_time')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('proposals');
    }
};
