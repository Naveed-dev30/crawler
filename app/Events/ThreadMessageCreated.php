<?php

namespace App\Events;

use App\Models\ThreadMessage;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ThreadMessageCreated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public ThreadMessage $message) {}

    public function broadcastOn(): array
    {
        $channels = [
            new PrivateChannel('thread.'.$this->message->thread_id),
            // Shared signal so the admin Chats list can re-sort live.
            new PrivateChannel('threads'),
        ];

        $assignedUserId = $this->message->thread?->assigned_user_id;
        if ($assignedUserId) {
            $channels[] = new PrivateChannel('user.'.$assignedUserId);
        }

        return $channels;
    }

    public function broadcastAs(): string
    {
        return 'message.created';
    }

    public function broadcastWith(): array
    {
        $this->message->loadMissing('attachments');

        return [
            'id' => $this->message->id,
            'thread_id' => $this->message->thread_id,
            'direction' => $this->message->direction,
            'message' => $this->message->message,
            'sender_user_id' => $this->message->sender_user_id,
            'sender_name' => $this->message->direction === 'sent'
                ? ($this->message->sender?->name ?? 'Owner')
                : null,
            'is_sent' => $this->message->direction === 'sent'
                ? $this->message->freelancer_message_id !== null
                : null,
            'is_read' => $this->message->is_read,
            'sent_by_ai' => (bool) $this->message->sent_by_ai,
            'message_time' => $this->message->message_time?->toIso8601String(),
            // Attachment-only replies carry no text, so a listener that renders
            // `message` alone would draw an empty bubble. Same shape as
            // ThreadMessageResource so the app can reuse one parser; `is_ready`
            // is false while the mirror job is still fetching the bytes, and the
            // signed URLs stay null until it lands.
            'attachments' => $this->message->attachments->map(fn ($a) => [
                'id' => $a->id,
                'filename' => $a->filename,
                'url' => $a->serve_url,
                'view_url' => $a->serve_url,
                'download_url' => $a->download_url,
                'is_ready' => $a->isStored(),
                'mime_type' => $a->mime_type,
                'size' => $a->size,
            ])->all(),
        ];
    }
}
