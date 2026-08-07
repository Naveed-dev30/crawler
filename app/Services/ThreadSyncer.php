<?php

namespace App\Services;

use App\Events\ThreadMessageCreated;
use App\Jobs\AssignThreadJob;
use App\Jobs\DownloadThreadAttachment;
use App\Jobs\GenerateAiReplyJob;
use App\Models\Proposal;
use App\Models\Thread;
use App\Models\ThreadMessage;
use App\Support\SafeBroadcast;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * One sync pass: pull Freelancer threads for projects we bid on, store new
 * threads (status 'fresh') and their received messages, and queue AI
 * assignment for new threads. Blocked threads keep syncing — blocking only
 * suppresses notifications and escalation.
 */
class ThreadSyncer
{
    public function __construct(
        private FreelancerMessenger $messenger,
        private MobileNotifier $notifier,
    ) {}

    public function run(): void
    {
        try {
            $this->sync();
        } catch (\Throwable $e) {
            Log::warning('ThreadSyncer: '.$e->getMessage());
        }
    }

    private function sync(): void
    {
        $flThreads = $this->messenger->fetchThreads();
        if ($flThreads === []) {
            return;
        }

        $ourFlUserId = (int) config('variables.flUserId');

        // Resolve every project → proposal in one query. This used to be a
        // lookup per thread per pass, against an unindexed `proposals.project_id`.
        $projectIds = [];
        foreach ($flThreads as $flThread) {
            $context = $flThread['thread']['context'] ?? $flThread['context'] ?? [];
            if (($context['type'] ?? null) === 'project' && ($context['id'] ?? 0)) {
                $projectIds[] = (int) $context['id'];
            }
        }

        $proposalIds = Proposal::whereIn('project_id', array_unique($projectIds))
            ->pluck('id', 'project_id');

        foreach ($flThreads as $flThread) {
            $context = $flThread['thread']['context'] ?? $flThread['context'] ?? [];
            if (($context['type'] ?? null) !== 'project') {
                continue;
            }

            $projectId = (int) ($context['id'] ?? 0);
            $flThreadId = (int) ($flThread['id'] ?? 0);
            $timeUpdated = (int) ($flThread['time_updated'] ?? 0);

            if (! $projectId || ! $flThreadId) {
                continue;
            }

            $proposalId = $proposalIds[$projectId] ?? null;
            if (! $proposalId) {
                continue; // not a project we bid on
            }

            $thread = Thread::where('freelancer_thread_id', $flThreadId)->first();

            if (! $thread) {
                $thread = Thread::create([
                    'freelancer_thread_id' => $flThreadId,
                    'project_id' => $projectId,
                    'proposal_id' => $proposalId,
                    'status' => 'fresh',
                    'freelancer_time_updated' => $timeUpdated,
                ]);

                // No assignee yet, so importMessages() sends no push — the
                // thread_assigned push from AssignThreadJob covers this user.
                $this->importMessages($thread, $ourFlUserId);

                AssignThreadJob::dispatch($thread->id);

                continue;
            }

            if ($timeUpdated > (int) $thread->freelancer_time_updated) {
                // Only advance the watermark when the fetch actually succeeded;
                // otherwise a transient Freelancer failure would skip this
                // window's messages permanently.
                if ($this->importMessages($thread, $ourFlUserId, (int) $thread->freelancer_time_updated)) {
                    $thread->freelancer_time_updated = $timeUpdated;
                    $thread->save();
                }
            }
        }
    }

    public function maybeQueueAiReply(Thread $thread, ThreadMessage $message): void
    {
        if ($message->direction !== 'received' || $thread->blocked || $thread->assigned_user_id === null) {
            return;
        }
        $thread->loadMissing('assignedUser');
        if ($thread->assignedUser && $thread->assignedUser->aiActiveNow(\Illuminate\Support\Carbon::now('UTC'))) {
            GenerateAiReplyJob::dispatch($thread->id, $message->id);
        }
    }

    /**
     * Import a thread's new messages.
     *
     * Returns false when the upstream fetch failed, so the caller leaves the
     * watermark where it was and retries the same window next pass.
     */
    private function importMessages(Thread $thread, int $ourFlUserId, int $fromTime = 0): bool
    {
        $messages = $this->messenger->fetchMessages((int) $thread->freelancer_thread_id, $fromTime);

        if ($messages === null) {
            return false;
        }

        $lastClientMessageAt = $thread->last_client_message_at;
        $lastMessageAt = $thread->last_message_at;

        // One lookup for the whole batch instead of a SELECT per message.
        $flMessageIds = array_values(array_filter(array_map(
            static fn ($flMessage) => (int) ($flMessage['id'] ?? 0),
            $messages,
        )));
        $existingMessages = ThreadMessage::whereIn('freelancer_message_id', $flMessageIds)
            ->get()
            ->keyBy('freelancer_message_id');

        // Coalesce: a batch carries one push for the newest inbound message,
        // not one push per message. A 200-message backfill must not fan out.
        $latestInbound = null;
        $inboundCount = 0;

        foreach ($messages as $flMessage) {
            $fromUser = (int) ($flMessage['from_user'] ?? 0);
            $flMessageId = (int) ($flMessage['id'] ?? 0);
            if (! $flMessageId) {
                continue;
            }

            $isRead = array_key_exists('is_read', $flMessage) ? (bool) $flMessage['is_read'] : null;
            $isOurs = $fromUser === $ourFlUserId;
            $messageTime = Carbon::createFromTimestamp((int) ($flMessage['time_created'] ?? now()->timestamp));

            // Newest activity in EITHER direction bubbles the thread up the list.
            if (! $lastMessageAt || $messageTime->gt($lastMessageAt)) {
                $lastMessageAt = $messageTime;
            }

            $existing = $existingMessages->get($flMessageId);
            if ($existing) {
                // App-sent messages come back around in the feed — only their read state can change.
                if ($isRead !== null && $existing->is_read !== $isRead) {
                    $existing->is_read = $isRead;
                    $existing->save();
                }

                continue;
            }

            $stored = ThreadMessage::create([
                'thread_id' => $thread->id,
                'freelancer_message_id' => $flMessageId,
                // Outbound messages here were sent from the Freelancer profile
                // itself (app sends are stored at send time): no app sender.
                'direction' => $isOurs ? 'sent' : 'received',
                'from_freelancer_user_id' => $fromUser,
                'sender_user_id' => null,
                'message' => $flMessage['message'] ?? null,
                'message_time' => $messageTime,
                'is_read' => $isRead,
            ]);

            foreach ($flMessage['attachments'] ?? [] as $flAttachment) {
                $filename = $flAttachment['filename'] ?? 'attachment';
                $attachment = $stored->attachments()->create([
                    'freelancer_attachment_id' => $flAttachment['id'] ?? null,
                    'filename' => $filename,
                    'url' => $flAttachment['url']
                        ?? $this->messenger->attachmentUrl($flMessageId, $filename),
                    'mime_type' => $flAttachment['mime_type'] ?? null,
                    'size' => $flAttachment['size'] ?? null,
                ]);

                // Mirror the bytes onto our disk so browser/mobile can open it
                // without Freelancer's OAuth header.
                DownloadThreadAttachment::dispatch($attachment->id);
            }

            SafeBroadcast::event(new ThreadMessageCreated($stored));

            $this->maybeQueueAiReply($thread, $stored);

            if (! $isOurs) {
                $inboundCount++;
                if ($latestInbound === null || $messageTime->gte($latestInbound->message_time)) {
                    $latestInbound = $stored;
                }

                if (! $lastClientMessageAt || $messageTime->gt($lastClientMessageAt)) {
                    $lastClientMessageAt = $messageTime;
                }
            }
        }

        $dirty = false;

        if ($lastClientMessageAt && ! $lastClientMessageAt->equalTo($thread->last_client_message_at ?? Carbon::createFromTimestamp(0))) {
            $thread->last_client_message_at = $lastClientMessageAt;
            $dirty = true;
        }

        if ($lastMessageAt && ! $lastMessageAt->equalTo($thread->last_message_at ?? Carbon::createFromTimestamp(0))) {
            $thread->last_message_at = $lastMessageAt;
            $dirty = true;
        }

        // A client reply reopens the conversation. Without this an 'answered'
        // thread stays answered forever, and ThreadEscalator — which only scans
        // 'fresh' threads — can never escalate an ignored follow-up.
        if ($latestInbound !== null && $thread->status !== 'fresh') {
            $thread->status = 'fresh';
            $dirty = true;
        }

        if ($dirty) {
            $thread->save();
        }

        $this->notifyAssignee($thread, $latestInbound, $inboundCount);

        return true;
    }

    /**
     * One push per thread per sync pass, for the newest inbound message.
     *
     * Guards mirror maybeQueueAiReply(): nothing for our own messages, nothing
     * on a blocked thread, and nothing when no one owns the thread yet (which
     * is also what keeps a brand-new thread's full history import silent).
     */
    private function notifyAssignee(Thread $thread, ?ThreadMessage $latestInbound, int $inboundCount): void
    {
        if ($latestInbound === null || $thread->blocked || $thread->assigned_user_id === null) {
            return;
        }

        $thread->loadMissing(['assignedUser', 'proposal']);

        if ($thread->assignedUser) {
            $this->notifier->message($thread->assignedUser, $thread, $latestInbound, $inboundCount);
        }
    }
}
