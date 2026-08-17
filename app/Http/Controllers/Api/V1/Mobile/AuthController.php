<?php

namespace App\Http\Controllers\Api\V1\Mobile;

use App\Http\Controllers\Api\V1\Mobile\Concerns\RespondsMobile;
use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\DeviceTokenRegistrar;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class AuthController extends Controller
{
    use RespondsMobile;

    public function login(Request $request, DeviceTokenRegistrar $devices)
    {
        $validated = $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
            // Nullable on purpose: a device that cannot obtain an FCM token
            // (push denied, or a simulator without APNs) must still be able to
            // sign in. It registers later via POST fcm-token.
            'fcm_token' => 'nullable|string|max:512',
            'device_name' => 'nullable|string',
            'platform' => ['nullable', Rule::in(['android', 'ios'])],
            'sound_key' => ['nullable', Rule::in(config('push.sound_keys'))],
            'sound_enabled' => 'nullable|boolean',
        ]);

        $user = User::where('email', $validated['email'])->first();

        if (! $user || ! Hash::check($validated['password'], $user->password)) {
            return $this->fail('Invalid credentials.', 401);
        }

        // Role check after password check so the endpoint can't be used to
        // probe which emails belong to dashboard accounts.
        if (! $user->isMobile()) {
            return $this->fail('Not a mobile user.', 403);
        }

        // Signing in is itself a call from the app, and the one the users
        // page's "Last Login" column is really about.
        $user->touchApiActivity(force: true);

        $deviceName = $validated['device_name'] ?? 'mobile-app';

        // Signing in again from the same device supersedes the old token;
        // without this, personal_access_tokens grows without bound per user.
        $user->tokens()->where('name', $deviceName)->delete();

        $accessToken = $user->createToken($deviceName);

        // After createToken so the device row can be linked to this session,
        // which is what makes logout per-device.
        $devices->register($user, $validated, $accessToken->accessToken->id);

        return $this->ok([
            'token' => $accessToken->plainTextToken,
            'user' => new UserResource($user),
        ], 'Logged in successfully.');
    }

    public function me(Request $request)
    {
        return $this->ok(new UserResource($request->user()), 'Current user.');
    }

    public function logout(Request $request, DeviceTokenRegistrar $devices)
    {
        $validated = $request->validate([
            'fcm_token' => 'nullable|string|max:512',
        ]);

        $user = $request->user();
        $accessToken = $user->currentAccessToken();

        // Only THIS device stops receiving pushes. Nulling a shared column used
        // to sign every other device out of notifications too.
        $devices->forget($user, $accessToken?->id, $validated['fcm_token'] ?? null);

        $accessToken?->delete();

        return $this->ok(null, 'Logged out successfully.');
    }

    public function updateFcmToken(Request $request, DeviceTokenRegistrar $devices)
    {
        $validated = $request->validate([
            'fcm_token' => 'required|string|max:512',
            'device_name' => 'nullable|string',
            'platform' => ['nullable', Rule::in(['android', 'ios'])],
            'sound_key' => ['nullable', Rule::in(config('push.sound_keys'))],
            'sound_enabled' => 'nullable|boolean',
        ]);

        // Required here (the token IS the device identity), but a sentinel is
        // not a token — reject it rather than storing something undeliverable.
        if ($devices->usableToken($validated['fcm_token']) === null) {
            return $this->fail('A real FCM token is required.', 422, [
                'fcm_token' => ['The fcm token is not a usable device token.'],
            ]);
        }

        $devices->register(
            $request->user(),
            $validated,
            $request->user()->currentAccessToken()?->id,
        );

        return $this->ok(null, 'FCM token updated.');
    }
}
