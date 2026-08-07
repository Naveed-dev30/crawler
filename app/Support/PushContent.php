<?php

namespace App\Support;

use App\Models\Thread;
use App\Models\ThreadMessage;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Builds the title and body for every notification the mobile app receives.
 *
 * Deliberately side-effect free — no persistence, no dispatching — so the copy
 * can be asserted directly. {@see \App\Services\MobileNotifier} is what turns a
 * PushContent into a stored row and an FCM push.
 *
 * Callers must have the thread's `proposal` relation loaded; {@see projectTitle}
 * falls back to the project id rather than lazy-loading, so a missing relation
 * degrades to a usable title instead of an N+1.
 */
final class PushContent
{
    /**
     * Notification titles are truncated hard by both platforms (roughly one
     * line in the shade); keeping our own limit below that avoids the OS
     * cutting mid-word.
     */
    private const TITLE_LIMIT = 80;

    /** Matches the truncation the assignment push has always used. */
    private const BODY_LIMIT = 180;

    private function __construct(
        public readonly string $type,
        public readonly string $title,
        public readonly string $body,
    ) {}

    /**
     * A client replied on a thread the recipient owns.
     *
     * [$count] is the number of new inbound messages in the same sync batch —
     * the push carries the newest one and says how many others arrived, rather
     * than firing once per message.
     */
    public static function message(Thread $thread, ThreadMessage $message, int $count = 1): self
    {
        $body = self::messageBody($message);

        if ($count > 1) {
            $body = Str::limit($body, self::BODY_LIMIT - 15).' (+'.($count - 1).' more)';
        }

        return new self(NotificationType::MESSAGE, self::projectTitle($thread), $body);
    }

    /**
     * The recipient just became the assignee.
     *
     * The title deliberately does NOT lead with the project name: this used to
     * be titled with the project and bodied with the client's last message,
     * which was indistinguishable from a chat push.
     */
    public static function assigned(Thread $thread, ?User $from = null): self
    {
        $title = self::projectTitle($thread);

        return new self(
            NotificationType::THREAD_ASSIGNED,
            'New project assigned',
            $from
                ? "{$from->name} assigned “{$title}” to you."
                : "“{$title}” was assigned to you.",
        );
    }

    /** The recipient inherited a thread that waited past the escalation window. */
    public static function escalated(Thread $thread, ?User $from = null): self
    {
        $title = self::projectTitle($thread);

        return new self(
            NotificationType::THREAD_ESCALATED,
            'Escalated to you',
            $from
                ? "“{$title}” waited too long with {$from->name} and was escalated to you."
                : "“{$title}” waited too long and was escalated to you.",
        );
    }

    /** The recipient's thread moved up the ladder to someone else. */
    public static function escalatedAway(Thread $thread, User $to): self
    {
        $title = self::projectTitle($thread);

        return new self(
            NotificationType::THREAD_ESCALATED_AWAY,
            'Escalation moved on',
            "“{$title}” was escalated to {$to->name}.",
        );
    }

    /** The recipient's thread was handed to someone else manually. */
    public static function reassignedAway(Thread $thread, User $to): self
    {
        $title = self::projectTitle($thread);

        return new self(
            NotificationType::THREAD_REASSIGNED_AWAY,
            'Project reassigned',
            "“{$title}” is now with {$to->name}.",
        );
    }

    /**
     * The project name shown to the user.
     *
     * Uses the proposal title rather than the client's name on purpose: client
     * names are not reliably real (see the placeholder fallbacks that used to
     * live in ThreadResource), whereas the proposal title always is.
     */
    private static function projectTitle(Thread $thread): string
    {
        $title = $thread->proposal->title ?? null;

        return $title
            ? Str::limit($title, self::TITLE_LIMIT)
            : "Project {$thread->project_id}";
    }

    /**
     * Body for a chat message. Attachment-only and empty messages still need to
     * say something useful — an empty push body renders as a blank line.
     */
    private static function messageBody(ThreadMessage $message): string
    {
        $text = trim((string) $message->message);

        if ($text !== '') {
            return Str::limit($text, self::BODY_LIMIT);
        }

        $attachments = $message->relationLoaded('attachments')
            ? $message->attachments->count()
            : $message->attachments()->count();

        return match (true) {
            $attachments > 1 => "📎 {$attachments} attachments",
            $attachments === 1 => '📎 Attachment',
            default => 'New message',
        };
    }
}
