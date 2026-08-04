<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('thread_attachments', function (Blueprint $table) {
            // Local copy of the file on a private disk. Freelancer's own
            // attachment URLs need our OAuth header, so a browser or the mobile
            // app can't fetch them directly — we mirror the bytes here and serve
            // them through an authed route. Null until the download job runs
            // (or for a source we couldn't fetch).
            $table->string('stored_path')->nullable()->after('url');
            $table->string('stored_disk')->nullable()->after('stored_path');
        });
    }

    public function down(): void
    {
        Schema::table('thread_attachments', function (Blueprint $table) {
            $table->dropColumn(['stored_path', 'stored_disk']);
        });
    }
};
