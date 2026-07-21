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
            'message_time' => $this->message_time?->toIso8601String(),
            'attachments' => $this->whenLoaded('attachments', function () {
                return $this->attachments->map(fn ($a) => [
                    'id' => $a->id,
                    'filename' => $a->filename,
                    'url' => $a->url,
                    'mime_type' => $a->mime_type,
                    'size' => $a->size,
                ]);
            }),
        ];
    }
}
