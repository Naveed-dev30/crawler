<?php

namespace App\Services;

use App\Events\ThreadAssigned;
use App\Models\ActivityLog;
use App\Models\Thread;
use App\Models\User;

/**
 * Single write-path for every thread assignment (AI match, escalation,
 * manual reassign): sets the assignee and produces the log row, in-app
 * notifications, and FCM pushes consistently.
 */
class ThreadAssigner
{
    public const TYPE_AI = 'ai_match';

    public const TYPE_ESCALATION = 'escalation';

    public const TYPE_MANUAL = 'manual_assign';

    public function __construct(private MobileNotifier $notifier) {}

    public function assign(Thread $thread, User $to, string $type, ?User $from = null): void
    {
        // Defense in depth: the controllers already guard this, but a no-op
        // reassign must never page the same user about a thread they still own.
        if ((int) $thread->assigned_user_id === (int) $to->id) {
            return;
        }

        $thread->assigned_user_id = $to->id;
        if ($type === self::TYPE_ESCALATION) {
            $thread->last_escalated_at = now();
        }
        $thread->save();

        // The copy builder reads $thread->proposal; load it here so the two
        // controller call-sites (which do not eager-load) don't lazy-load it.
        $thread->loadMissing('proposal');

        if ($type !== self::TYPE_AI) {
            $verb = $type === self::TYPE_ESCALATION ? 'escalated' : 'assigned';
            ActivityLog::create([
                'thread_id' => $thread->id,
                'from_user_id' => $from?->id,
                'to_user_id' => $to->id,
                'type' => $type,
                'message' => "thread {$thread->project_id} {$verb} from user({$from?->name}) to user({$to->name})",
            ]);
        }

        event(new ThreadAssigned($thread, $to, $type, $from));

        if ($type === self::TYPE_ESCALATION) {
            $this->notifier->escalated($to, $thread, $from);
        } else {
            $this->notifier->assigned($to, $thread, $from);
        }

        if ($from && $from->id !== $to->id && $type !== self::TYPE_AI) {
            if ($type === self::TYPE_ESCALATION) {
                $this->notifier->escalatedAway($from, $thread, $to);
            } else {
                $this->notifier->reassignedAway($from, $thread, $to);
            }
        }
    }
}
