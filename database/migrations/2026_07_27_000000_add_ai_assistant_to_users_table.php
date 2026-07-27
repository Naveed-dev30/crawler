<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('ai_schedule_enabled')->default(false)->after('fcm_token');
            $table->time('ai_window_start')->nullable()->after('ai_schedule_enabled');
            $table->time('ai_window_end')->nullable()->after('ai_window_start');
            $table->string('ai_timezone')->nullable()->after('ai_window_end');
            $table->boolean('ai_manual_state')->nullable()->after('ai_timezone');
            $table->timestamp('ai_manual_until')->nullable()->after('ai_manual_state');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'ai_schedule_enabled', 'ai_window_start', 'ai_window_end',
                'ai_timezone', 'ai_manual_state', 'ai_manual_until',
            ]);
        });
    }
};
