<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('proposals', function (Blueprint $table) {
            $table->boolean('qualified')->nullable()->default(null)->after('review_label');
            $table->longText('qualify_reason')->nullable()->after('qualified');
            $table->longText('qualify_summary')->nullable()->after('qualify_reason');
        });
    }

    public function down(): void
    {
        Schema::table('proposals', function (Blueprint $table) {
            $table->dropColumn(['qualified', 'qualify_reason', 'qualify_summary']);
        });
    }
};
