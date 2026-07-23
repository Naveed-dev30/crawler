<?php

namespace App\Services;

use App\Jobs\AssignThreadJob;
use App\Models\Proposal;
use App\Models\Thread;
use App\Models\ThreadMessage;
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
    public function __construct(private FreelancerMessenger $messenger)
    {
    }

    public function run(): void
    {
        try {
            $this->sync();
        } catch (\Throwable $e) {
            Log::warning('ThreadSyncer: ' . $e->getMessage());
        }
    }

    private function sync(): void
    {
        $flThreads = $this->messenger->fetchThreads();
        if ($flThreads === []) {
            return;
        }

        $ourFlUserId = (int) config('variables.flUserId');

        foreach ($flThreads as $flThread) {
            $context = $flThread['thread']['context'] ?? $flThread['context'] ?? [];
            if (($context['type'] ?? null) !== 'project') {
                continue;
            }

            $projectId = (int) ($context['id'] ?? 0);
            $flThreadId = (int) ($flThread['id'] ?? 0);
            $timeUpdated = (int) ($flThread['time_updated'] ?? 0);

            if (!$projectId || !$flThreadId) {
                continue;
            }

            $proposalId = Proposal::where('project_id', $projectId)->value('id');
            if (!$proposalId) {
                continue; // not a project we bid on
            }

            $thread = Thread::where('freelancer_thread_id', $flThreadId)->first();

            if (!$thread) {
                $thread = Thread::create([
                    'freelancer_thread_id' => $flThreadId,
                    'project_id' => $projectId,
                    'proposal_id' => $proposalId,
                    'status' => 'fresh',
                    'freelancer_time_updated' => $timeUpdated,
                ]);

                $this->importMessages($thread, $ourFlUserId);

                AssignThreadJob::dispatch($thread->id);
                continue;
            }

            if ($timeUpdated > (int) $thread->freelancer_time_updated) {
                $this->importMessages($thread, $ourFlUserId, (int) $thread->freelancer_time_updated);
                $thread->freelancer_time_updated = $timeUpdated;
                $thread->save();
            }
        }
    }

    private function importMessages(Thread $thread, int $ourFlUserId, int $fromTime = 0): void
    {
        $messages = $this->messenger->fetchMessages((int) $thread->freelancer_thread_id, $fromTime);

        $lastClientMessageAt = $thread->last_client_message_at;

        foreach ($messages as $flMessage) {
            $fromUser = (int) ($flMessage['from_user'] ?? 0);
            $flMessageId = (int) ($flMessage['id'] ?? 0);
            if (!$flMessageId) {
                continue;
            }

            $isRead = array_key_exists('is_read', $flMessage) ? (bool) $flMessage['is_read'] : null;
            $isOurs = $fromUser === $ourFlUserId;
            $messageTime = Carbon::createFromTimestamp((int) ($flMessage['time_created'] ?? now()->timestamp));

            $existing = ThreadMessage::where('freelancer_message_id', $flMessageId)->first();
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
                $stored->attachments()->create([
                    'freelancer_attachment_id' => $flAttachment['id'] ?? null,
                    'filename' => $filename,
                    'url' => $flAttachment['url']
                        ?? $this->messenger->attachmentUrl($flMessageId, $filename),
                    'mime_type' => $flAttachment['mime_type'] ?? null,
                    'size' => $flAttachment['size'] ?? null,
                ]);
            }

            event(new \App\Events\ThreadMessageCreated($stored));

            if (!$isOurs && (!$lastClientMessageAt || $messageTime->gt($lastClientMessageAt))) {
                $lastClientMessageAt = $messageTime;
            }
        }

        if ($lastClientMessageAt && !$lastClientMessageAt->equalTo($thread->last_client_message_at ?? Carbon::createFromTimestamp(0))) {
            $thread->last_client_message_at = $lastClientMessageAt;
            $thread->save();
        }
    }
}
