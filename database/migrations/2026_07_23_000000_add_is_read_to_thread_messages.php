<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('thread_messages', function (Blueprint $table) {
            // Read state on Freelancer, from our profile's perspective; null = unknown.
            $table->boolean('is_read')->nullable()->after('message_time');
        });
    }

    public function down(): void
    {
        Schema::table('thread_messages', function (Blueprint $table) {
            $table->dropColumn('is_read');
        });
    }
};
