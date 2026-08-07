<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One FCM registration token — that is, one installed app on one device.
 *
 * Replaces the single `users.fcm_token` column, where a second sign-in
 * overwrote the first device's token and signing out of any device stopped
 * pushes everywhere.
 */
class DeviceToken extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'token',
        'platform',
        'device_name',
        'sound_key',
        'sound_enabled',
        'personal_access_token_id',
        'last_used_at',
    ];

    protected $casts = [
        'sound_enabled' => 'boolean',
        'last_used_at' => 'datetime',
    ];

    /** Mirrors User: keeps datetime casts usable without a live connection. */
    protected $dateFormat = 'Y-m-d H:i:s';

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The Android channel this device's notifications must be posted on.
     *
     * A channel's sound is immutable on Android 8+, so each selectable sound
     * has its own channel and the server picks between them per push. An
     * unrecognised key falls back to the default channel rather than naming a
     * channel the app never created — which would drop the notification.
     */
    public function androidChannelId(): string
    {
        $prefix = config('push.android_channel_prefix');

        if (! $this->sound_enabled) {
            return "{$prefix}_silent";
        }

        $key = (string) $this->sound_key;

        if ($key === '' || $key === 'default' || ! in_array($key, config('push.sound_keys', []), true)) {
            return $prefix;
        }

        return "{$prefix}_{$key}";
    }

    /**
     * The APNs `aps.sound` value, or null to omit the key entirely — which is
     * how "sound off" is expressed on iOS (there is no silent sound file).
     */
    public function apnsSound(): ?string
    {
        if (! $this->sound_enabled) {
            return null;
        }

        $key = (string) $this->sound_key;

        if ($key === '' || $key === 'default' || ! in_array($key, config('push.sound_keys', []), true)) {
            return 'default';
        }

        return "{$key}.wav";
    }
}
