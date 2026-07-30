<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\ThreadMessage;
use App\Models\User;
use Carbon\Carbon;

class MobileAgentStats
{
    public const ASSIGN_TYPES = ['ai_match', 'manual_assign', 'escalation'];

    /**
     * @return array<int, array{user_id:int,name:string,assigned:int,responded:int,blocked:int,reassigned:int,avg_response_seconds:?int}>
     */
    public function rows(Carbon $from, Carbon $to): array
    {
        return User::mobile()->orderBy('name')->get()->map(function (User $u) use ($from, $to) {
            $uid = (int) $u->id;

            $assigned = ActivityLog::whereIn('type', self::ASSIGN_TYPES)
                ->where('to_user_id', $uid)
                ->whereBetween('created_at', [$from, $to])
                ->count();

            $responded = ThreadMessage::where('sender_user_id', $uid)
                ->where('direction', 'sent')
                ->where('sent_by_ai', false)
                ->whereBetween('message_time', [$from, $to])
                ->count();

            $blocked = ActivityLog::where('from_user_id', $uid)
                ->where('type', 'block')
                ->whereBetween('created_at', [$from, $to])
                ->count();

            $reassigned = ActivityLog::where('from_user_id', $uid)
                ->where('type', 'manual_assign')
                ->whereNotNull('to_user_id')
                ->where('to_user_id', '!=', $uid)
                ->whereBetween('created_at', [$from, $to])
                ->count();

            return [
                'user_id' => $uid,
                'name' => (string) $u->name,
                'assigned' => $assigned,
                'responded' => $responded,
                'blocked' => $blocked,
                'reassigned' => $reassigned,
                'avg_response_seconds' => $this->avgResponseSeconds($uid, $from, $to),
            ];
        })->all();
    }

    private function avgResponseSeconds(int $uid, Carbon $from, Carbon $to): ?int
    {
        // Earliest assignment-to-agent per thread within range.
        $assignments = ActivityLog::whereIn('type', self::ASSIGN_TYPES)
            ->where('to_user_id', $uid)
            ->whereBetween('created_at', [$from, $to])
            ->get(['thread_id', 'created_at'])
            ->groupBy('thread_id')
            ->map(fn ($group) => $group->min('created_at'));

        $gaps = [];
        foreach ($assignments as $threadId => $assignedAt) {
            if ($threadId === null) {
                continue;
            }
            $assignedAt = Carbon::parse($assignedAt);
            $firstReply = ThreadMessage::where('thread_id', $threadId)
                ->where('sender_user_id', $uid)
                ->where('direction', 'sent')
                ->where('sent_by_ai', false)
                ->where('message_time', '>=', $assignedAt)
                ->min('message_time');

            if ($firstReply !== null) {
                $gaps[] = Carbon::parse($firstReply)->diffInSeconds($assignedAt);
            }
        }

        return count($gaps) ? (int) round(array_sum($gaps) / count($gaps)) : null;
    }
}
