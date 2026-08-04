<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('threads', function (Blueprint $table) {
            // Newest activity in EITHER direction. last_client_message_at only
            // tracks inbound, so our own replies never bubbled a thread up the
            // list. Sort by this instead.
            $table->timestamp('last_message_at')->nullable()->after('last_client_message_at');
        });

        // Backfill from the actual message history; fall back to the inbound
        // watermark, then thread creation time.
        DB::statement('
            UPDATE threads t
            SET last_message_at = COALESCE(
                (SELECT MAX(m.message_time) FROM thread_messages m WHERE m.thread_id = t.id),
                t.last_client_message_at,
                t.created_at
            )
        ');
    }

    public function down(): void
    {
        Schema::table('threads', function (Blueprint $table) {
            $table->dropColumn('last_message_at');
        });
    }
};
