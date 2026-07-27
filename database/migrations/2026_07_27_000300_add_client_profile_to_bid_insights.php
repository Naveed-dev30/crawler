<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bid_insights', function (Blueprint $table) {
            $table->string('client_name')->nullable()->after('client_country');
            $table->string('client_avatar', 1024)->nullable()->after('client_name');
        });
    }

    public function down(): void
    {
        Schema::table('bid_insights', function (Blueprint $table) {
            $table->dropColumn(['client_name', 'client_avatar']);
        });
    }
};
