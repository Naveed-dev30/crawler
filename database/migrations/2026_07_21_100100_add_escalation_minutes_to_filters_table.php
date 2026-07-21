<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('filters', function (Blueprint $table) {
            $table->unsignedInteger('escalation_minutes')->default(30);
            // System prompt for the OpenAI user-profile matcher (ThreadMatcher).
            $table->longText('profile_match_prompt')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('filters', function (Blueprint $table) {
            $table->dropColumn(['escalation_minutes', 'profile_match_prompt']);
        });
    }
};
