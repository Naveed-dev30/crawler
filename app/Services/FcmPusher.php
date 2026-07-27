<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Kreait\Firebase\Contract\Messaging;
use Kreait\Firebase\Exception\Messaging\NotFound;
use Kreait\Firebase\Factory;
use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Firebase\Messaging\Notification;

/**
 * FCM HTTP v1 wrapper. Resolved from the container so tests can mock it by
 * injecting a Messaging double; SendFcmPushJob is the single production
 * call-site. Each user is pushed independently — one user's missing or dead
 * token never affects another user's push.
 */
class FcmPusher
{
    /**
     * Error signatures meaning the token itself is dead — safe to clear so the
     * next login re-registers. Deliberately excludes "SenderId mismatch",
     * which is a project-config problem, not a dead token.
     */
    private const DEAD_TOKEN_SIGNS = [
        'not a valid FCM registration token',
        'registration-token-not-registered',
        'Requested entity was not found',
    ];

    public function __construct(private ?Messaging $messaging = null) {}

    public function sendToUser(User $user, string $title, string $body, array $data = []): bool
    {
        if (empty($user->fcm_token)) {
            // Visible skip: a silent no-op here made a missing token look like a
            // broken pipeline during debugging.
            Log::info("FcmPusher: user {$user->id} has no fcm_token — push skipped.");

            return false;
        }

        try {
            $message = CloudMessage::withTarget('token', $user->fcm_token)
                ->withNotification(Notification::create($title, $body))
                ->withData(array_map('strval', $data));

            $this->messaging()->send($message);

            return true;
        } catch (NotFound $e) {
            $this->clearToken($user, 'unregistered token (NotFound)');

            return false;
        } catch (\Throwable $e) {
            $msg = $e->getMessage();

            if (Str::contains($msg, self::DEAD_TOKEN_SIGNS)) {
                $this->clearToken($user, $msg);

                return false;
            }

            Log::warning("FcmPusher: user {$user->id} push failed — {$msg}");

            return false;
        }
    }

    /**
     * Drop a dead token so pushes stop bouncing until the next login refreshes it.
     */
    private function clearToken(User $user, string $reason): void
    {
        $user->fcm_token = null;
        $user->save();
        Log::warning("FcmPusher: cleared token for user {$user->id} — {$reason}");
    }

    private function messaging(): Messaging
    {
        return $this->messaging ??= (new Factory)
            ->withServiceAccount(config('services.firebase.credentials'))
            ->createMessaging();
    }
}
