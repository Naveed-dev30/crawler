<?php

namespace App\Support;

/**
 * The kinds of notification the mobile app can receive.
 *
 * The value travels in two places that must agree: the `type` column on
 * `mobile_notifications`, and the `type` key of the FCM `data` payload. The app
 * switches on it to decide where a tap lands (chat vs the alerts tab) and which
 * providers to refresh, so adding a case here means adding one there.
 *
 * The client maps anything it does not recognise onto a safe default rather
 * than dropping the push, so introducing a new type is backwards compatible.
 */
final class NotificationType
{
    private function __construct() {}

    /** A client sent a message on a thread assigned to the recipient. */
    public const MESSAGE = 'message';

    /** A thread was assigned to the recipient (AI match or manual). */
    public const THREAD_ASSIGNED = 'thread_assigned';

    /** A thread was escalated *to* the recipient after waiting too long. */
    public const THREAD_ESCALATED = 'thread_escalated';

    /** A thread the recipient owned was escalated away to the next ladder step. */
    public const THREAD_ESCALATED_AWAY = 'thread_escalated_away';

    /** A thread the recipient owned was manually reassigned to someone else. */
    public const THREAD_REASSIGNED_AWAY = 'thread_reassigned_away';

    /**
     * Every known type. Used to validate the `type` column and to keep the
     * mobile contract documented in one place.
     *
     * @return array<int, string>
     */
    public static function all(): array
    {
        return [
            self::MESSAGE,
            self::THREAD_ASSIGNED,
            self::THREAD_ESCALATED,
            self::THREAD_ESCALATED_AWAY,
            self::THREAD_REASSIGNED_AWAY,
        ];
    }

    /**
     * Types that describe the recipient losing a thread. These deep-link to the
     * alerts list rather than the chat, because the recipient is no longer the
     * assignee and opening the thread would 403.
     *
     * @return array<int, string>
     */
    public static function losingThread(): array
    {
        return [self::THREAD_ESCALATED_AWAY, self::THREAD_REASSIGNED_AWAY];
    }
}
