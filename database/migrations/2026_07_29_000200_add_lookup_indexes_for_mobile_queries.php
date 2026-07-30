<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ThreadSyncer resolves project_id -> proposal on every sync pass (the
        // sync loop runs every 10 seconds). The column was never indexed, so
        // each pass scanned the proposals table.
        //
        // NOTE: this is the one index here with real production cost. MySQL 8
        // adds it in place, but on a large proposals table it takes minutes —
        // run this migration in a quiet window. Nothing else depends on it.
        Schema::table('proposals', function (Blueprint $table) {
            $table->index('project_id');
        });

        // The mobile threads list filters by assignee + blocked and orders by
        // the client-message clock; `threads` only carried project_id and
        // (status, blocked).
        Schema::table('threads', function (Blueprint $table) {
            $table->index(['assigned_user_id', 'blocked', 'last_client_message_at'], 'threads_assignee_list_index');
        });
    }

    public function down(): void
    {
        Schema::table('threads', function (Blueprint $table) {
            $table->dropIndex('threads_assignee_list_index');
        });

        Schema::table('proposals', function (Blueprint $table) {
            $table->dropIndex(['project_id']);
        });
    }
};
