<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ThreadReadStateChanged implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public int $threadId)
    {
    }

    public function broadcastOn(): array
    {
        return [new PrivateChannel('thread.' . $this->threadId)];
    }

    public function broadcastAs(): string
    {
        return 'thread.read';
    }

    public function broadcastWith(): array
    {
        return ['thread_id' => $this->threadId];
    }
}
