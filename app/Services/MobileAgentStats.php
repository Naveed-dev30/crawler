<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Thread;
use App\Models\ThreadMessage;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Str;

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

    /**
     * @return array<int, array{time:string,type:string,project_id:?int,detail:string}>
     */
    public function activityFor(User $user, Carbon $from, Carbon $to): array
    {
        $uid = (int) $user->id;

        $logs = ActivityLog::involving($uid)
            ->whereBetween('created_at', [$from, $to])
            ->get(['thread_id', 'type', 'message', 'created_at']);

        $messages = ThreadMessage::where('sender_user_id', $uid)
            ->where('direction', 'sent')
            ->where('sent_by_ai', false)
            ->whereBetween('message_time', [$from, $to])
            ->get(['thread_id', 'message', 'message_time']);

        $threadIds = $logs->pluck('thread_id')->merge($messages->pluck('thread_id'))->filter()->unique();
        $projects = Thread::whereIn('id', $threadIds)->pluck('project_id', 'id');

        $items = [];
        foreach ($logs as $log) {
            $items[] = [
                'time' => Carbon::parse($log->created_at)->toIso8601String(),
                'type' => $log->type,
                'project_id' => $log->thread_id ? ($projects[$log->thread_id] ?? null) : null,
                'detail' => (string) $log->message,
            ];
        }
        foreach ($messages as $msg) {
            $items[] = [
                'time' => Carbon::parse($msg->message_time)->toIso8601String(),
                'type' => 'responded',
                'project_id' => $msg->thread_id ? ($projects[$msg->thread_id] ?? null) : null,
                'detail' => Str::limit((string) $msg->message, 120),
            ];
        }

        usort($items, fn ($a, $b) => strcmp($b['time'], $a['time']));

        return $items;
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
