<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->text('profile_prompt')->nullable()->after('role');
            // Unique but nullable: admins/team keep NULL (MySQL allows repeated NULLs).
            $table->unsignedTinyInteger('escalation_ladder')->nullable()->unique()->after('profile_prompt');
            $table->string('fcm_token', 512)->nullable()->after('escalation_ladder');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['escalation_ladder']);
            $table->dropColumn(['profile_prompt', 'escalation_ladder', 'fcm_token']);
        });
    }
};
