<?php

namespace App\Http\Controllers\Api\V1\Mobile;

use App\Http\Controllers\Api\V1\Mobile\Concerns\RespondsMobile;
use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    use RespondsMobile;

    public function login(Request $request)
    {
        $validated = $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
            'fcm_token' => 'required|string|max:512',
            'device_name' => 'nullable|string',
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

        $user->fcm_token = $validated['fcm_token'];
        $user->save();

        $token = $user->createToken($validated['device_name'] ?? 'mobile-app')->plainTextToken;

        return $this->ok([
            'token' => $token,
            'user' => new UserResource($user),
        ], 'Logged in successfully.');
    }

    public function me(Request $request)
    {
        return $this->ok(new UserResource($request->user()), 'Current user.');
    }

    public function logout(Request $request)
    {
        $user = $request->user();

        // Device is signing out — stop pushing to it until the next login.
        $user->fcm_token = null;
        $user->save();

        $user->currentAccessToken()->delete();

        return $this->ok(null, 'Logged out successfully.');
    }
}
