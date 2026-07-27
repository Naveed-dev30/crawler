<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bid_insights', function (Blueprint $table) {
            $table->string('client_country_flag', 1024)->nullable()->after('client_country');
            $table->timestamp('client_member_since')->nullable()->after('client_avatar');
            $table->json('client_verification')->nullable()->after('client_member_since');
        });
    }

    public function down(): void
    {
        Schema::table('bid_insights', function (Blueprint $table) {
            $table->dropColumn(['client_country_flag', 'client_member_since', 'client_verification']);
        });
    }
};
