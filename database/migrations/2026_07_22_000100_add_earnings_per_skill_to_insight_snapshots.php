<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('insight_snapshots', function (Blueprint $table) {
            $table->json('earnings_per_skill')->nullable()->after('rating_per_skill');
        });
    }

    public function down(): void
    {
        Schema::table('insight_snapshots', function (Blueprint $table) {
            $table->dropColumn('earnings_per_skill');
        });
    }
};
