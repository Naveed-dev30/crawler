<?php

namespace App\Jobs;

use App\Models\Thread;
use App\Services\FreelancerMessenger;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Fired when a mobile user opens a thread: marks it read on Freelancer and
 * mirrors the read state onto our stored inbound messages.
 */
class MarkThreadReadJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public int $threadId)
    {
    }

    public function handle(FreelancerMessenger $messenger): void
    {
        $thread = Thread::find($this->threadId);
        if (!$thread || !$thread->freelancer_thread_id) {
            return;
        }

        if ($messenger->markThreadRead((int) $thread->freelancer_thread_id)) {
            $thread->messages()
                ->where('direction', 'received')
                ->where(fn ($q) => $q->where('is_read', false)->orWhereNull('is_read'))
                ->update(['is_read' => true]);
        }
    }
}
