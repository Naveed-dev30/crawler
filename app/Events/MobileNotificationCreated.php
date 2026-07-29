<?php

namespace App\Events;

use App\Models\MobileNotification;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * An in-app alert row was created for a user.
 *
 * Lets the alerts list update live while the app is open, without waiting for
 * the FCM round-trip (which the OS may also coalesce or delay). The push is
 * still sent — it is what covers the backgrounded and killed cases.
 *
 * Broadcasts on the existing per-user channel, so no channel-auth change is
 * needed: `user.{id}` is already restricted to that user or an admin.
 */
class MobileNotificationCreated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public MobileNotification $notification) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('user.'.$this->notification->user_id)];
    }

    public function broadcastAs(): string
    {
        return 'notification.created';
    }

    /**
     * Mirrors MobileNotificationResource so a socket-delivered alert and a
     * fetched one deserialize through the same client-side model.
     */
    public function broadcastWith(): array
    {
        return [
            'id' => $this->notification->id,
            'user_id' => $this->notification->user_id,
            'thread_id' => $this->notification->thread_id,
            'type' => $this->notification->type,
            'title' => $this->notification->title,
            'body' => $this->notification->body,
            'read_at' => $this->notification->read_at?->toIso8601String(),
            'created_at' => $this->notification->created_at?->toIso8601String(),
        ];
    }
}
