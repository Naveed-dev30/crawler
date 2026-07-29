<?php

namespace App\Services;

use App\Models\DeviceToken;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Kreait\Firebase\Contract\Messaging;
use Kreait\Firebase\Exception\Messaging\NotFound;
use Kreait\Firebase\Factory;
use Kreait\Firebase\Messaging\AndroidConfig;
use Kreait\Firebase\Messaging\ApnsConfig;
use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Firebase\Messaging\Notification;

/**
 * FCM HTTP v1 wrapper. Resolved from the container so tests can mock it by
 * injecting a Messaging double; SendFcmPushJob is the single production
 * call-site. Each device is pushed independently — one dead token never
 * affects the user's other devices, and one user never affects another.
 */
class FcmPusher
{
    /**
     * Error signatures meaning the token itself is dead — safe to delete so the
     * next login re-registers. Deliberately excludes "SenderId mismatch",
     * which is a project-config problem, not a dead token.
     */
    private const DEAD_TOKEN_SIGNS = [
        'not a valid FCM registration token',
        'registration-token-not-registered',
        'Requested entity was not found',
    ];

    /**
     * Signatures worth retrying. Without rethrowing these, SendFcmPushJob's
     * $tries/$backoff were dead config: every Throwable was swallowed here and
     * the job always reported success.
     */
    private const TRANSIENT_SIGNS = [
        'UNAVAILABLE',
        'INTERNAL',
        'Deadline',
        'timed out',
        'Service Unavailable',
    ];

    public function __construct(private ?Messaging $messaging = null) {}

    /**
     * Push to every device signed in to this account.
     *
     * @return int the number of devices the message reached
     */
    public function sendToUser(User $user, string $title, string $body, array $data = []): int
    {
        $devices = $user->deviceTokens()->get();

        if ($devices->isEmpty()) {
            // Visible skip: a silent no-op here made a missing token look like a
            // broken pipeline during debugging.
            Log::info("FcmPusher: user {$user->id} has no registered devices — push skipped.");

            return 0;
        }

        $payload = $this->payload($data);
        $delivered = 0;

        foreach ($devices as $device) {
            if ($this->sendToDevice($device, $title, $body, $payload)) {
                $delivered++;
            }
        }

        return $delivered;
    }

    /**
     * @param  array<string, string>  $payload  already normalised by payload()
     */
    private function sendToDevice(DeviceToken $device, string $title, string $body, array $payload): bool
    {
        try {
            $apns = array_filter(['sound' => $device->apnsSound()], static fn ($v) => $v !== null);

            $message = CloudMessage::new()
                // withTarget() is deprecated since kreait 7.16.0.
                ->toToken($device->token)
                ->withNotification(Notification::create($title, $body))
                ->withData($payload)
                ->withAndroidConfig(AndroidConfig::fromArray([
                    'priority' => 'high',
                    'notification' => [
                        'channel_id' => $device->androidChannelId(),
                        'notification_priority' => 'PRIORITY_HIGH',
                    ],
                ]))
                ->withApnsConfig(ApnsConfig::fromArray([
                    'headers' => ['apns-priority' => '10'],
                    // Omitting aps.sound entirely is how "sound off" is
                    // expressed on iOS — there is no silent sound file.
                    'payload' => ['aps' => $apns],
                ]));

            $this->messaging()->send($message);

            return true;
        } catch (NotFound $e) {
            $this->dropDevice($device, 'unregistered token (NotFound)');

            return false;
        } catch (\Throwable $e) {
            $msg = $e->getMessage();

            if (Str::contains($msg, self::DEAD_TOKEN_SIGNS)) {
                $this->dropDevice($device, $msg);

                return false;
            }

            if (Str::contains($msg, self::TRANSIENT_SIGNS)) {
                // Let the job retry rather than reporting a silent success.
                throw $e;
            }

            Log::warning("FcmPusher: device {$device->id} push failed — {$msg}");

            return false;
        }
    }

    /**
     * FCM requires non-empty keys and string values. Nulls are dropped rather
     * than cast, so an absent optional field (e.g. notification_id on a
     * message push) does not arrive at the client as an empty string.
     *
     * @return array<string, string>
     */
    private function payload(array $data): array
    {
        $payload = [];

        foreach ($data as $key => $value) {
            if ($value === null || $key === '') {
                continue;
            }
            $payload[(string) $key] = (string) $value;
        }

        return $payload;
    }

    /**
     * Delete a dead device so pushes stop bouncing until it registers again.
     * Only this row goes — the user's other devices are unaffected.
     */
    private function dropDevice(DeviceToken $device, string $reason): void
    {
        $device->delete();
        Log::warning("FcmPusher: dropped device {$device->id} for user {$device->user_id} — {$reason}");
    }

    private function messaging(): Messaging
    {
        return $this->messaging ??= (new Factory)
            ->withServiceAccount(config('services.firebase.credentials'))
            ->createMessaging();
    }
}
