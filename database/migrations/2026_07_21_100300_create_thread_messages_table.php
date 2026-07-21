<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('thread_messages', function (Blueprint $table) {
            $table->id();
            $table->timestamps();
            $table->foreignId('thread_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('freelancer_message_id')->nullable()->unique();
            $table->string('direction');
            $table->unsignedBigInteger('from_freelancer_user_id')->nullable();
            $table->foreignId('sender_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->longText('message')->nullable();
            $table->timestamp('message_time');
            $table->index(['thread_id', 'message_time']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('thread_messages');
    }
};
