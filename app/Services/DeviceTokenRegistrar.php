<?php

namespace App\Services;

use App\Models\DeviceToken;
use App\Models\User;

/**
 * Registers (or re-registers) the device a user just signed in from.
 *
 * Shared by login and the fcm-token refresh endpoint so there is one write
 * path for device rows.
 */
class DeviceTokenRegistrar
{
    /**
     * @param  array<string, mixed>  $input  validated request data
     * @return DeviceToken|null  null when there is no usable token
     */
    public function register(User $user, array $input, ?int $accessTokenId = null): ?DeviceToken
    {
        $token = $this->usableToken($input['fcm_token'] ?? null);

        if ($token === null) {
            // Push permission denied, or an iOS simulator with no APNs token.
            // Never a failure: the user signs in fine and simply receives no
            // pushes until a real token arrives via the refresh endpoint.
            return null;
        }

        $attributes = [
            'user_id' => $user->id,
            'personal_access_token_id' => $accessTokenId,
            'last_used_at' => now(),
        ];

        // Only overwrite optional fields the client actually sent, so a refresh
        // that omits them does not reset the device's sound preference.
        foreach (['platform', 'device_name', 'sound_key'] as $key) {
            if (isset($input[$key]) && $input[$key] !== '') {
                $attributes[$key] = $input[$key];
            }
        }
        if (array_key_exists('sound_enabled', $input) && $input['sound_enabled'] !== null) {
            $attributes['sound_enabled'] = (bool) $input['sound_enabled'];
        }

        // Keyed on the token, which is globally unique: if this handset was
        // previously signed in to another account, the row MOVES to the new
        // user instead of leaving a ghost that pushes their messages here.
        return DeviceToken::updateOrCreate(['token' => $token], $attributes);
    }

    /**
     * Drop the device this access token signed in from. Falls back to the raw
     * token for devices registered before the link existed, or when the client
     * re-registered without one.
     */
    public function forget(User $user, ?int $accessTokenId, ?string $token = null): void
    {
        if ($accessTokenId !== null) {
            DeviceToken::where('user_id', $user->id)
                ->where('personal_access_token_id', $accessTokenId)
                ->delete();
        }

        $token = $this->usableToken($token);
        if ($token !== null) {
            DeviceToken::where('user_id', $user->id)->where('token', $token)->delete();
        }
    }

    /**
     * Null for absent, blank, or sentinel tokens. The app sends a placeholder
     * string when it cannot get a real one; storing it means every push makes a
     * doomed FCM call, gets the token "cleared" as dead, and the next login
     * writes the same junk straight back.
     */
    public function usableToken(?string $token): ?string
    {
        $token = trim((string) $token);

        if ($token === '') {
            return null;
        }

        if (in_array(strtolower($token), config('push.placeholder_tokens', []), true)) {
            return null;
        }

        return $token;
    }
}
