<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bid_insights', function (Blueprint $table) {
            // Freelancer's username, as distinct from the display name we
            // already store. Needed to build a profile link (/u/{username});
            // display names contain spaces and cannot be used for that.
            $table->string('client_username')->nullable()->after('client_name');
        });

        Schema::table('threads', function (Blueprint $table) {
            // The client's Freelancer user id, taken from the thread's members
            // list. The projects API stops returning owner details once a
            // project closes, so the thread is the only durable source of
            // "who are we talking to" for older conversations.
            $table->unsignedBigInteger('client_user_id')->nullable()->after('project_id');
            $table->index('client_user_id');
        });
    }

    public function down(): void
    {
        Schema::table('bid_insights', function (Blueprint $table) {
            $table->dropColumn('client_username');
        });

        Schema::table('threads', function (Blueprint $table) {
            $table->dropIndex(['client_user_id']);
            $table->dropColumn('client_user_id');
        });
    }
};
