<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('thread_attachments', function (Blueprint $table) {
            $table->id();
            $table->timestamps();
            $table->foreignId('thread_message_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('freelancer_attachment_id')->nullable();
            $table->string('filename');
            $table->text('url');
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('size')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('thread_attachments');
    }
};
