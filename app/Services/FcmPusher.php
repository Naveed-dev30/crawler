<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Log;
use Kreait\Firebase\Exception\Messaging\NotFound;
use Kreait\Firebase\Factory;
use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Firebase\Messaging\Notification;

/**
 * FCM HTTP v1 wrapper. Resolved from the container so tests can mock it;
 * SendFcmPushJob is the single production call-site.
 */
class FcmPusher
{
    private ?\Kreait\Firebase\Contract\Messaging $messaging = null;

    public function sendToUser(User $user, string $title, string $body, array $data = []): bool
    {
        if (empty($user->fcm_token)) {
            return false;
        }

        try {
            $message = CloudMessage::withTarget('token', $user->fcm_token)
                ->withNotification(Notification::create($title, $body))
                ->withData(array_map('strval', $data));

            $this->messaging()->send($message);

            return true;
        } catch (NotFound $e) {
            // Stale/unregistered token — stop trying until next login refreshes it.
            $user->fcm_token = null;
            $user->save();
            Log::warning("FcmPusher: stale token cleared for user {$user->id}");

            return false;
        } catch (\Throwable $e) {
            Log::warning('FcmPusher: ' . $e->getMessage());

            return false;
        }
    }

    private function messaging(): \Kreait\Firebase\Contract\Messaging
    {
        if ($this->messaging === null) {
            $this->messaging = (new Factory())
                ->withServiceAccount(config('services.firebase.credentials'))
                ->createMessaging();
        }

        return $this->messaging;
    }
}
