<?php

use App\Support\NotificationType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mobile_notifications', function (Blueprint $table) {
            // Existing rows were all written by ThreadAssigner::notify(), so
            // the default is right for the common case. "Escalated away" rows
            // are mislabelled as assignments in history; acceptable, since the
            // column only drives client-side routing of new pushes.
            $table->string('type', 32)
                ->default(NotificationType::THREAD_ASSIGNED)
                ->after('thread_id');

            // The list query is `where user_id ... order by created_at desc`
            // (NotificationController::index); only `user_id` alone was indexed.
            $table->index(['user_id', 'created_at']);

            // Backs the new unread_count in the paginated meta.
            $table->index(['user_id', 'read_at']);
        });
    }

    public function down(): void
    {
        Schema::table('mobile_notifications', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'read_at']);
            $table->dropIndex(['user_id', 'created_at']);
            $table->dropColumn('type');
        });
    }
};
