<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('proposals', function (Blueprint $table) {
            // Operator review of the AI's disqualification: was skipping this
            // project Correct or Incorrect? Mirrors bids.check.
            $table->string('qualify_check')->default('Unreviewed')->after('qualify_summary');
        });
    }

    public function down(): void
    {
        Schema::table('proposals', function (Blueprint $table) {
            $table->dropColumn('qualify_check');
        });
    }
};
