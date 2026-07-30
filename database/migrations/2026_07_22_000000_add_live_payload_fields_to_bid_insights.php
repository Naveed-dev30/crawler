<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bid_insights', function (Blueprint $table) {
            $table->unsignedBigInteger('bid_id')->nullable()->index()->after('project_id');
            $table->text('description')->nullable()->after('bid_currency');
            $table->timestamp('time_submitted')->nullable()->after('time_to_bid_seconds');
            $table->json('upgrades')->nullable()->after('client_engagement');
        });
    }

    public function down(): void
    {
        Schema::table('bid_insights', function (Blueprint $table) {
            $table->dropColumn(['bid_id', 'description', 'time_submitted', 'upgrades']);
        });
    }
};
