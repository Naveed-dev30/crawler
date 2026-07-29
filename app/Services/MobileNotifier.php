<?php

namespace App\Services;

use App\Events\MobileNotificationCreated;
use App\Jobs\SendFcmPushJob;
use App\Models\MobileNotification;
use App\Models\Thread;
use App\Models\ThreadMessage;
use App\Models\User;
use App\Support\NotificationType;
use App\Support\PushContent;

/**
 * The single write-path for every mobile notification.
 *
 * Creating the in-app row, broadcasting it, and dispatching the FCM push used
 * to live inline in ThreadAssigner, which is why chat messages — handled in a
 * different service — got neither. Everything funnels through here so the row,
 * the socket payload and the push can never drift apart.
 */
class MobileNotifier
{
    /** A client replied on a thread this user owns. */
    public function message(User $user, Thread $thread, ThreadMessage $latest, int $count = 1): void
    {
        $this->notify($user, PushContent::message($thread, $latest, $count), $thread);
    }

    /** The user just became the assignee (AI match or manual assign). */
    public function assigned(User $user, Thread $thread, ?User $from = null): void
    {
        $this->notify($user, PushContent::assigned($thread, $from), $thread);
    }

    /** A thread waited too long elsewhere and landed on this user. */
    public function escalated(User $user, Thread $thread, ?User $from = null): void
    {
        $this->notify($user, PushContent::escalated($thread, $from), $thread);
    }

    /** This user's thread moved up the ladder to someone else. */
    public function escalatedAway(User $user, Thread $thread, User $to): void
    {
        $this->notify($user, PushContent::escalatedAway($thread, $to), $thread);
    }

    /** This user's thread was handed to someone else manually. */
    public function reassignedAway(User $user, Thread $thread, User $to): void
    {
        $this->notify($user, PushContent::reassignedAway($thread, $to), $thread);
    }

    /**
     * Store the alert, broadcast it, and queue the push — in that order, so the
     * socket and the push both carry an id the client can dedupe against.
     */
    public function notify(User $user, PushContent $content, ?Thread $thread = null): ?MobileNotification
    {
        $row = $this->storeRow($user, $content, $thread);

        if ($row) {
            event(new MobileNotificationCreated($row));
        }

        SendFcmPushJob::dispatch($user->id, $content->title, $content->body, array_filter([
            'type' => $content->type,
            'thread_id' => $thread?->id,
            'notification_id' => $row?->id,
        ], static fn ($value) => $value !== null));

        return $row;
    }

    /**
     * Chat messages are kept out of the alerts list by default — see the
     * `messages_in_alerts` note in config/push.php. When they are enabled, a
     * conversation holds at most one unread row: the newest message updates it
     * in place instead of appending, so a chatty client cannot bury every
     * assignment alert.
     */
    private function storeRow(User $user, PushContent $content, ?Thread $thread): ?MobileNotification
    {
        if ($content->type !== NotificationType::MESSAGE) {
            return MobileNotification::create([
                'user_id' => $user->id,
                'thread_id' => $thread?->id,
                'type' => $content->type,
                'title' => $content->title,
                'body' => $content->body,
            ]);
        }

        if (! config('push.messages_in_alerts')) {
            return null;
        }

        $existing = MobileNotification::where('user_id', $user->id)
            ->where('thread_id', $thread?->id)
            ->where('type', NotificationType::MESSAGE)
            ->whereNull('read_at')
            ->latest('id')
            ->first();

        if ($existing) {
            $existing->fill(['title' => $content->title, 'body' => $content->body])->save();

            return $existing;
        }

        return MobileNotification::create([
            'user_id' => $user->id,
            'thread_id' => $thread?->id,
            'type' => $content->type,
            'title' => $content->title,
            'body' => $content->body,
        ]);
    }
}
