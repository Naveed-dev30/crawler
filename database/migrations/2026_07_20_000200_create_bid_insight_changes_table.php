<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bid_insight_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bid_insight_id')->constrained('bid_insights')->cascadeOnDelete();
            $table->string('field', 64);
            $table->text('old_value')->nullable();
            $table->text('new_value')->nullable();
            $table->timestamp('observed_at');
            $table->timestamps();
            $table->index(['bid_insight_id', 'observed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bid_insight_changes');
    }
};
