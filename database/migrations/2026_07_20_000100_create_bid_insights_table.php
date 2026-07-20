<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bid_insights', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('project_id')->unique();
            $table->string('project_url')->nullable();
            // one-time fields
            $table->unsignedInteger('time_to_bid_seconds')->nullable();
            $table->decimal('bid_amount', 12, 2)->nullable();
            $table->string('bid_currency', 8)->nullable();
            $table->string('client_country', 64)->nullable();
            $table->decimal('client_rating', 3, 2)->nullable();
            $table->unsignedInteger('client_reviews')->nullable();
            // recurring fields
            $table->unsignedInteger('bid_rank')->nullable();
            $table->decimal('winning_bid_amount', 12, 2)->nullable();
            $table->boolean('winning_bid_sealed')->nullable();
            $table->longText('winning_bid_text')->nullable();
            $table->json('actions_taken')->nullable();
            $table->json('client_engagement')->nullable();
            $table->timestamp('last_scraped_at');
            $table->json('raw')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bid_insights');
    }
};
