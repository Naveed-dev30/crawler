<?php

namespace App\Support;

use Illuminate\Support\Facades\Log;

/**
 * Dispatches an event whose broadcast must never take the caller down with it.
 *
 * All the realtime events are ShouldBroadcastNow, so the publish happens inline
 * rather than on the queue — which is what keeps socket delivery under a second
 * instead of waiting on the worker's poll interval. The cost is that a Soketi
 * outage would otherwise throw straight into whatever triggered the event:
 * sending a chat message would 500 *after* the message had already been stored
 * and relayed to Freelancer, and one failure mid-sync would abort the rest of
 * the pass.
 *
 * The socket is an enhancement. The REST and FCM paths must survive without it.
 */
final class SafeBroadcast
{
    private function __construct() {}

    public static function event(object $event): void
    {
        try {
            event($event);
        } catch (\Throwable $e) {
            Log::warning(
                'SafeBroadcast: '.$event::class.' failed to broadcast — '.$e->getMessage()
            );
        }
    }
}
