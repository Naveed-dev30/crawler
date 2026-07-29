<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transitions', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('number')->unique();
            $table->timestamps();
        });

        Schema::create('transition_users', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transition_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('position');
            $table->timestamps();
            $table->unique(['transition_id', 'user_id']);
            $table->unique(['transition_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transition_users');
        Schema::dropIfExists('transitions');
    }
};
