<?php

namespace App\Services;

use App\Jobs\SendFcmPushJob;
use App\Models\ActivityLog;
use App\Models\MobileNotification;
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

    public function assign(Thread $thread, User $to, string $type, ?User $from = null): void
    {
        $thread->assigned_user_id = $to->id;
        if ($type === self::TYPE_ESCALATION) {
            $thread->last_escalated_at = now();
        }
        $thread->save();

        $title = $thread->proposal->title ?? "Project {$thread->project_id}";
        $lastMessage = $thread->messages()
            ->where('direction', 'received')
            ->orderByDesc('message_time')
            ->value('message');
        $body = \Illuminate\Support\Str::limit((string) ($lastMessage ?: 'New thread assigned to you'), 180);

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

        $this->notify($to, $thread, $title, $body);

        if ($from && $from->id !== $to->id && $type !== self::TYPE_AI) {
            $fromTitle = $type === self::TYPE_ESCALATION
                ? "Thread escalated away: {$title}"
                : "Thread reassigned: {$title}";
            $this->notify($from, $thread, $fromTitle, $body);
        }
    }

    private function notify(User $user, Thread $thread, string $title, string $body): void
    {
        MobileNotification::create([
            'user_id' => $user->id,
            'thread_id' => $thread->id,
            'title' => $title,
            'body' => $body,
        ]);

        SendFcmPushJob::dispatch($user->id, $title, $body, ['thread_id' => $thread->id]);
    }
}
