<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('page_captures', function (Blueprint $table) {
            $table->id();
            $table->string('source')->index();
            $table->string('url');
            $table->timestamp('scraped_at');
            $table->longText('payload');
            $table->string('content_hash', 64)->index();
            $table->timestamps();

            $table->unique(['source', 'scraped_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('page_captures');
    }
};
