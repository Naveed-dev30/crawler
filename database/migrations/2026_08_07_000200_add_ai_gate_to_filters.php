<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('filters', function (Blueprint $table) {
            // Global AI kill-switch. Flipped off automatically on an OpenAI rate
            // limit (reason=rate_limit) or by an admin (reason=manual). While off,
            // no AI calls fire — proposals are not qualified and no bids post.
            $table->boolean('ai_enabled')->default(true)->after('crawler_on');
            $table->string('ai_disabled_reason')->nullable()->after('ai_enabled');
            $table->timestamp('ai_disabled_at')->nullable()->after('ai_disabled_reason');
        });
    }

    public function down(): void
    {
        Schema::table('filters', function (Blueprint $table) {
            $table->dropColumn(['ai_enabled', 'ai_disabled_reason', 'ai_disabled_at']);
        });
    }
};
