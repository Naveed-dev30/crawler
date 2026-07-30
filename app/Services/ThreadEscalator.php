<?php

namespace App\Services;

use App\Models\Filter;
use App\Models\Thread;
use App\Models\TransitionUser;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Escalation pass: fresh, unblocked, assigned threads that sit past the
 * configured window move to the next user in their transition lane. Timer
 * resets on each move. No next user in the lane -> the thread stays put.
 */
class ThreadEscalator
{
    public function run(): void
    {
        try {
            $this->escalate();
        } catch (\Throwable $e) {
            Log::warning('ThreadEscalator: '.$e->getMessage());
        }
    }

    private function escalate(): void
    {
        $windowMinutes = (int) (Filter::find(1)?->escalation_minutes ?? 30);
        $cutoff = now()->subMinutes($windowMinutes);

        $assigner = app(ThreadAssigner::class);

        Thread::where('status', 'fresh')
            ->where('blocked', false)
            ->whereNotNull('assigned_user_id')
            ->whereNotNull('transition_id')
            ->with(['assignedUser', 'proposal'])
            ->chunkById(200, function ($threads) use ($cutoff, $assigner) {
                foreach ($threads as $thread) {
                    // Waiting time counts from the newest of: last escalation, last
                    // client message. created_at is only a fallback for threads that
                    // somehow have neither.
                    $reference = collect([
                        $thread->last_escalated_at,
                        $thread->last_client_message_at,
                    ])->filter()->max() ?? $thread->created_at;

                    // Explicit comparison rather than diffInMinutes(), which is
                    // ABSOLUTE in Carbon 2 — a future-dated client message
                    // (Freelancer clock skew) read as long overdue and escalated
                    // immediately.
                    if (! $reference || Carbon::parse($reference)->gt($cutoff)) {
                        continue;
                    }

                    $next = TransitionUser::with('user')
                        ->where('transition_id', $thread->transition_id)
                        ->where('position', (int) $thread->transition_position + 1)
                        ->first();

                    if (! $next || ! $next->user) {
                        continue; // end of lane — stay put
                    }

                    $current = $thread->assignedUser;
                    $assigner->assign($thread, $next->user, ThreadAssigner::TYPE_ESCALATION, $current);
                    $thread->forceFill(['transition_position' => $next->position])->save();
                }
            });
    }
}
