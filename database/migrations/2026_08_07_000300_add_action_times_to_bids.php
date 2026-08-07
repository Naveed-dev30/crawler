<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bids', function (Blueprint $table) {
            // posted_at: set once when a bid actually posts (Last Bid) — stable,
            // unlike updated_at which moves on later edits.
            // last_action_at: set on every attempt incl. failures (Last Action).
            $table->timestamp('posted_at')->nullable()->after('error_message');
            $table->timestamp('last_action_at')->nullable()->after('posted_at');
        });

        // Backfill: existing completed bids get their creation time as the post
        // time; every row's last action defaults to its last update.
        DB::statement("UPDATE bids SET posted_at = created_at WHERE bid_status = 'completed'");
        DB::statement('UPDATE bids SET last_action_at = updated_at');
    }

    public function down(): void
    {
        Schema::table('bids', function (Blueprint $table) {
            $table->dropColumn(['posted_at', 'last_action_at']);
        });
    }
};
