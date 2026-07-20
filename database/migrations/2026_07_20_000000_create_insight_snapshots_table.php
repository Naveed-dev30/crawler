<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('insight_snapshots', function (Blueprint $table) {
            $table->id();
            $table->timestamp('scraped_at')->unique();
            $table->decimal('earnings_total', 14, 2)->nullable();
            $table->decimal('earnings_30d', 14, 2)->nullable();
            $table->unsignedInteger('bids_remaining')->nullable();
            $table->unsignedInteger('unearned_bids')->nullable();
            $table->string('overall_ranking')->nullable();
            $table->json('job_proficiency')->nullable();
            $table->json('rating_per_skill')->nullable();
            $table->json('ranking_per_skill')->nullable();
            $table->json('high_demand_skills')->nullable();
            $table->json('trending_skills')->nullable();
            $table->json('bids_per_milestone')->nullable();
            $table->json('profile_views_week')->nullable();
            $table->json('profile_views_year')->nullable();
            $table->json('earnings_over_time')->nullable();
            $table->json('bid_conversion')->nullable();
            $table->longText('raw')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('insight_snapshots');
    }
};
