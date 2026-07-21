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
            Log::warning('ThreadEscalator: ' . $e->getMessage());
        }
    }

    private function escalate(): void
    {
        $windowMinutes = (int) (Filter::find(1)?->escalation_minutes ?? 30);

        $threads = Thread::where('status', 'fresh')
            ->where('blocked', false)
            ->whereNotNull('assigned_user_id')
            ->with(['assignedUser', 'proposal'])
            ->get();

        foreach ($threads as $thread) {
            // Waiting time counts from the newest of: last escalation, last
            // client message. created_at is only a fallback for threads that
            // somehow have neither.
            $reference = collect([
                $thread->last_escalated_at,
                $thread->last_client_message_at,
            ])->filter()->max() ?? $thread->created_at;

            if (! $reference || Carbon::parse($reference)->diffInMinutes(now()) < $windowMinutes) {
                continue;
            }

            $current = $thread->assignedUser;
            if (! $current || $current->escalation_ladder === null) {
                continue;
            }

            $next = User::mobile()
                ->where('escalation_ladder', (int) $current->escalation_ladder + 1)
                ->first();

            if (! $next) {
                continue; // top of ladder (or gap) — stay with current assignee
            }

            app(ThreadAssigner::class)->assign($thread, $next, ThreadAssigner::TYPE_ESCALATION, $current);
        }
    }
}
