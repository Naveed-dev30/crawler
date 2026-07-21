<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('threads', function (Blueprint $table) {
            $table->id();
            $table->timestamps();
            $table->unsignedBigInteger('freelancer_thread_id')->unique();
            $table->unsignedBigInteger('project_id')->index();
            $table->foreignId('proposal_id')->constrained()->cascadeOnDelete();
            $table->foreignId('assigned_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status')->default('fresh');
            $table->boolean('blocked')->default(false);
            $table->timestamp('last_client_message_at')->nullable();
            $table->timestamp('last_escalated_at')->nullable();
            $table->unsignedBigInteger('freelancer_time_updated')->default(0);
            $table->index(['status', 'blocked']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('threads');
    }
};
