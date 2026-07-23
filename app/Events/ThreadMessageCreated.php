<?php

namespace App\Events;

use App\Models\ThreadMessage;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ThreadMessageCreated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public ThreadMessage $message)
    {
    }

    public function broadcastOn(): array
    {
        $channels = [new PrivateChannel('thread.' . $this->message->thread_id)];

        $assignedUserId = $this->message->thread?->assigned_user_id;
        if ($assignedUserId) {
            $channels[] = new PrivateChannel('user.' . $assignedUserId);
        }

        return $channels;
    }

    public function broadcastAs(): string
    {
        return 'message.created';
    }

    public function broadcastWith(): array
    {
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
            'message_time' => $this->message->message_time?->toIso8601String(),
        ];
    }
}
