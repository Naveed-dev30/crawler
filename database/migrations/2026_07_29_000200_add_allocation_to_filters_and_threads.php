<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('filters', function (Blueprint $table) {
            $table->longText('allocation_prompt')->nullable();
        });

        Schema::table('threads', function (Blueprint $table) {
            $table->foreignId('transition_id')->nullable()->after('assigned_user_id')
                ->constrained()->nullOnDelete();
            $table->unsignedInteger('transition_position')->nullable()->after('transition_id');
        });
    }

    public function down(): void
    {
        Schema::table('threads', function (Blueprint $table) {
            $table->dropConstrainedForeignId('transition_id');
            $table->dropColumn('transition_position');
        });
        Schema::table('filters', function (Blueprint $table) {
            $table->dropColumn('allocation_prompt');
        });
    }
};
