<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gamification_snapshots', function (Blueprint $table) {
            $table->id();
            $table->timestamp('scraped_at')->unique();
            $table->unsignedInteger('self_rank')->nullable();
            $table->unsignedBigInteger('self_score')->nullable();
            $table->unsignedInteger('self_level')->nullable();
            $table->string('self_username')->nullable();
            $table->string('self_public_name')->nullable();
            $table->json('top5')->nullable();
            $table->longText('raw')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gamification_snapshots');
    }
};
