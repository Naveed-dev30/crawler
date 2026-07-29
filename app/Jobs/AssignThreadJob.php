<?php

namespace App\Jobs;

use App\Models\Filter;
use App\Models\Thread;
use App\Models\Transition;
use App\Services\ThreadAllocator;
use App\Services\ThreadAssigner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class AssignThreadJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public int $threadId) {}

    public function handle(ThreadAllocator $allocator, ThreadAssigner $assigner): void
    {
        $thread = Thread::with('proposal')->find($this->threadId);
        if (! $thread || $thread->assigned_user_id) {
            return;
        }

        $transitions = Transition::with('users.user')->get();
        if ($transitions->isEmpty()) {
            Log::warning("AssignThreadJob: no transitions configured for thread {$thread->id}");

            return;
        }

        $number = $allocator->allocate(
            $thread->proposal->title ?? "Project {$thread->project_id}",
            $thread->proposal->description ?? '',
            (string) (Filter::find(1)?->allocation_prompt ?? ''),
            $transitions->pluck('number')->map(fn ($n) => (int) $n)->all()
        );

        $transition = $number !== null ? $transitions->firstWhere('number', $number) : null;
        $firstUser = $transition?->users->first()?->user;

        if (! $transition || ! $firstUser) {
            Log::warning("AssignThreadJob: no allocation for thread {$thread->id}; left unassigned");

            return;
        }

        $assigner->assign($thread, $firstUser, ThreadAssigner::TYPE_AI);
        $thread->forceFill([
            'transition_id' => $transition->id,
            'transition_position' => 0,
        ])->save();

        // Answer the latest client message now that an owner exists.
        $lastClient = $thread->messages()
            ->where('direction', 'received')
            ->latest('message_time')
            ->first();

        if ($lastClient) {
            GenerateAiReplyJob::dispatch($thread->id, $lastClient->id);
        }
    }
}
