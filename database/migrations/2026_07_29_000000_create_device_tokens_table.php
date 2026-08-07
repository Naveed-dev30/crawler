<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('device_tokens', function (Blueprint $table) {
            $table->id();
            $table->timestamps();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // 512 chars × 4 bytes = 2048, comfortably under InnoDB's 3072-byte
            // key limit. Unique so a phone that changes hands MOVES to the new
            // account rather than leaving a ghost row pushing someone else's
            // messages to it.
            $table->string('token', 512)->unique();

            $table->string('platform', 16)->nullable();      // android | ios
            $table->string('device_name', 191)->nullable();

            // Per-device sound choice; stamped onto the FCM payload as the
            // Android channel_id and the APNs aps.sound.
            $table->string('sound_key', 16)->default('default');
            $table->boolean('sound_enabled')->default(true);

            // Soft link to the Sanctum token this device signed in with, so
            // logout can drop just this device. Deliberately NOT a foreign key:
            // personal_access_tokens rows are deleted on logout and expiry, and
            // a cascade there would take the device row with them.
            $table->unsignedBigInteger('personal_access_token_id')->nullable()->index();

            $table->timestamp('last_used_at')->nullable();
            $table->index('user_id');
        });

        $this->backfillFromUsers();
    }

    /**
     * Carry existing single-column tokens over so the fleet keeps receiving
     * pushes without waiting for everyone to sign in again.
     *
     * Query-builder only — this runs under SQLite in the test suite. Skips the
     * placeholder the app sends when it has no real token, which FCM rejects
     * outright. insertOrIgnore because `token` is unique and two users can hold
     * the same stale value.
     */
    private function backfillFromUsers(): void
    {
        if (! Schema::hasColumn('users', 'fcm_token')) {
            return;
        }

        $placeholders = config('push.placeholder_tokens', []);
        $now = now();

        DB::table('users')
            ->whereNotNull('fcm_token')
            ->where('fcm_token', '<>', '')
            ->when($placeholders !== [], fn ($query) => $query->whereNotIn('fcm_token', $placeholders))
            ->orderBy('id')
            ->chunk(500, function ($users) use ($now) {
                $rows = [];
                foreach ($users as $user) {
                    $rows[] = [
                        'user_id' => $user->id,
                        'token' => $user->fcm_token,
                        'platform' => null,
                        'device_name' => 'migrated',
                        'sound_key' => 'default',
                        'sound_enabled' => true,
                        'personal_access_token_id' => null,
                        'last_used_at' => $now,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }

                if ($rows !== []) {
                    DB::table('device_tokens')->insertOrIgnore($rows);
                }
            });
    }

    public function down(): void
    {
        // users.fcm_token is left untouched by this migration, so dropping the
        // table simply reverts to the old single-token behaviour.
        Schema::dropIfExists('device_tokens');
    }
};
