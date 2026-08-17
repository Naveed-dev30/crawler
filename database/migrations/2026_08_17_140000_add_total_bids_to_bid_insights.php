<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bid_insights', function (Blueprint $table) {
            // Denominator for bid_rank: "#3 of 234". Freelancer reports it as
            // bid_stats.bid_count on the projects endpoint — the extension's
            // insights payload only carries our own rank, never the field size.
            $table->unsignedInteger('total_bids')->nullable()->after('bid_rank');
        });
    }

    public function down(): void
    {
        Schema::table('bid_insights', function (Blueprint $table) {
            $table->dropColumn('total_bids');
        });
    }
};
