<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bid_insights', function (Blueprint $table) {
            // Third "Actions Taken" signal, alongside the two flags inside
            // actions_taken. Freelancer carries it as a top-level `rating` on
            // the insights payload — 0 until the client rates the bid — so it
            // never fit the boolean map and was previously dropped into `raw`.
            $table->decimal('bid_rating', 3, 1)->nullable()->after('actions_taken');
        });
    }

    public function down(): void
    {
        Schema::table('bid_insights', function (Blueprint $table) {
            $table->dropColumn('bid_rating');
        });
    }
};
