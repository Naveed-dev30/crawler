<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // "Last login" as the users page means it: the most recent API call
            // the mobile app made. Kept on the user rather than read from
            // personal_access_tokens.last_used_at, because those rows are
            // deleted on logout and replaced on re-login, which would blank the
            // column for someone who used the app minutes ago.
            $table->timestamp('last_api_activity_at')->nullable()->after('role');
        });

        // Seed from whatever token activity still exists so the column is not
        // empty for everyone on deploy. No table alias — SQLite (test DB)
        // rejects `UPDATE users u`; the correlated subquery works on both.
        DB::statement("
            UPDATE users
            SET last_api_activity_at = (
                SELECT MAX(t.last_used_at) FROM personal_access_tokens t
                WHERE t.tokenable_type = 'App\\\\Models\\\\User' AND t.tokenable_id = users.id
            )
        ");
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('last_api_activity_at');
        });
    }
};
