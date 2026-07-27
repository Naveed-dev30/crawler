<?php

namespace App\Jobs;

use App\Models\Thread;
use App\Models\User;
use App\Services\ThreadAssigner;
use App\Services\ThreadMatcher;
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

    public function handle(ThreadMatcher $matcher, ThreadAssigner $assigner): void
    {
        $thread = Thread::with('proposal')->find($this->threadId);
        if (! $thread || $thread->assigned_user_id) {
            return;
        }

        $profiles = User::mobile()
            ->whereNotNull('profile_prompt')
            ->pluck('profile_prompt', 'id')
            ->all();

        if ($profiles === []) {
            Log::warning("AssignThreadJob: no mobile users to assign thread {$thread->id}");

            return;
        }

        $userId = $matcher->match(
            $thread->proposal->title ?? "Project {$thread->project_id}",
            $thread->proposal->description ?? '',
            $profiles
        );

        // Fail-safe: unmatched threads go to the first responder (ladder 1,
        // or the lowest ladder present) so every thread has an owner and the
        // escalation ladder can start.
        $user = $userId
            ? User::find($userId)
            : User::mobile()->whereNotNull('escalation_ladder')->orderBy('escalation_ladder')->first();

        if (! $user) {
            Log::warning("AssignThreadJob: no assignable user for thread {$thread->id}");

            return;
        }

        $assigner->assign($thread, $user, ThreadAssigner::TYPE_AI);

        // The sync-time trigger skipped this thread's opening message because
        // it arrived before an owner existed. Now that one does, answer the
        // latest client message. GenerateAiReplyJob re-checks aiActiveNow,
        // blocked, and already-answered, so this no-ops when inappropriate.
        $lastClient = $thread->messages()
            ->where('direction', 'received')
            ->latest('message_time')
            ->first();

        if ($lastClient) {
            GenerateAiReplyJob::dispatch($thread->id, $lastClient->id);
        }
    }
}
