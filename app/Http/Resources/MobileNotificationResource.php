<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Explicit shape for an in-app alert.
 *
 * The endpoint used to return raw models, so every column added to
 * mobile_notifications leaked to the client automatically. Kept in sync with
 * MobileNotificationCreated::broadcastWith() so a socket-delivered alert and a
 * fetched one deserialize identically.
 */
class MobileNotificationResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'thread_id' => $this->thread_id,
            'type' => $this->type,
            'title' => $this->title,
            'body' => $this->body,
            'read_at' => $this->read_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
