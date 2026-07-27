<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('thread_messages', function (Blueprint $table) {
            $table->boolean('sent_by_ai')->default(false)->after('is_read');
        });
    }

    public function down(): void
    {
        Schema::table('thread_messages', function (Blueprint $table) {
            $table->dropColumn('sent_by_ai');
        });
    }
};
