<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class ThreadMessageResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'thread_id' => $this->thread_id,
            'direction' => $this->direction,
            'message' => $this->message,
            'sender_user_id' => $this->sender_user_id,
            // Null for inbound messages: we do not know the client's name from
            // the message feed, and inventing one made fabricated data
            // indistinguishable from real data in the app.
            'sender_name' => $this->direction === 'sent'
                ? ($this->sender?->name ?? 'Owner')
                : $this->sender_name,
            'sender_avatar' => null,
            'is_mine' => $this->direction === 'sent'
                && $this->sender_user_id !== null
                && (int) $this->sender_user_id === (int) $request->user()?->id,
            'is_sent' => $this->direction === 'sent' ? $this->freelancer_message_id !== null : null,
            'is_read' => $this->is_read,
            'sent_by_ai' => (bool) $this->sent_by_ai,
            'message_time' => $this->message_time?->toIso8601String(),
            'attachments' => $this->whenLoaded('attachments', function () {
                return $this->attachments->map(fn ($a) => [
                    'id' => $a->id,
                    'filename' => $a->filename,
                    // Authed (signed) links to our mirrored copy — openable in
                    // the app without Freelancer's OAuth header. Null until the
                    // mirror job finishes. `url` kept for backward-compat.
                    'url' => $a->serve_url,
                    'view_url' => $a->serve_url,
                    'download_url' => $a->download_url,
                    'is_ready' => $a->isStored(),
                    'mime_type' => $a->mime_type,
                    'size' => $a->size,
                ]);
            }),
        ];
    }
}
