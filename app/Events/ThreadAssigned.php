<?php

namespace App\Events;

use App\Models\Thread;
use App\Models\User;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ThreadAssigned implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public Thread $thread,
        public User $to,
        public string $type,
        public ?User $from = null,
    ) {
    }

    public function broadcastOn(): array
    {
        $channels = [new PrivateChannel('user.' . $this->to->id)];

        if ($this->from && $this->from->id !== $this->to->id) {
            $channels[] = new PrivateChannel('user.' . $this->from->id);
        }

        return $channels;
    }

    public function broadcastAs(): string
    {
        return 'thread.assigned';
    }

    public function broadcastWith(): array
    {
        return [
            'thread_id' => $this->thread->id,
            'project_id' => $this->thread->project_id,
            'title' => $this->thread->proposal->title ?? "Project {$this->thread->project_id}",
            'type' => $this->type,
            'to_user' => ['id' => $this->to->id, 'name' => $this->to->name],
            'from_user' => $this->from ? ['id' => $this->from->id, 'name' => $this->from->name] : null,
        ];
    }
}
