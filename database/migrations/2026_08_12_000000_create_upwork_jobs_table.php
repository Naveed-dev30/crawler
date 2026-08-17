<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('upwork_jobs', function (Blueprint $table) {
            $table->id();
            $table->string('job_id')->unique();     // Upwork id / ciphertext — dedup key
            $table->string('title')->nullable();
            $table->longText('description')->nullable();
            $table->string('url')->nullable();
            $table->string('job_type')->nullable();  // hourly | fixed
            $table->double('budget_amount')->nullable();
            $table->double('hourly_min')->nullable();
            $table->double('hourly_max')->nullable();
            $table->string('currency')->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->json('skills')->nullable();
            $table->string('client_country')->nullable();
            $table->double('client_total_spent')->nullable();
            $table->boolean('client_payment_verified')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('upwork_jobs');
    }
};
