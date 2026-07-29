<?php

namespace App\Services;

use App\Models\Filter;
use App\Models\Thread;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Escalation pass: fresh, unblocked, assigned threads that have waited past
 * the configured window move to the user one ladder step up. Timer resets on
 * each escalation. No user at ladder+1 → the thread stays put.
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

        // The ladder is small and fixed; load it once instead of querying per
        // thread. Ordered so we can pick the next step ABOVE the current one,
        // which keeps escalation working when the ladder has gaps (1, 2, 4).
        $ladder = User::mobile()
            ->whereNotNull('escalation_ladder')
            ->orderBy('escalation_ladder')
            ->get();

        $assigner = app(ThreadAssigner::class);

        Thread::where('status', 'fresh')
            ->where('blocked', false)
            ->whereNotNull('assigned_user_id')
            ->with(['assignedUser', 'proposal'])
            ->chunkById(200, function ($threads) use ($cutoff, $ladder, $assigner) {
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

                    $current = $thread->assignedUser;
                    if (! $current || $current->escalation_ladder === null) {
                        continue;
                    }

                    $next = $ladder
                        ->first(fn ($user) => (int) $user->escalation_ladder > (int) $current->escalation_ladder);

                    if (! $next) {
                        continue; // top of the ladder — stay with the current assignee
                    }

                    $assigner->assign($thread, $next, ThreadAssigner::TYPE_ESCALATION, $current);
                }
            });
    }
}
