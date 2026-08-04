<?php

namespace App\Services;

use App\Events\ThreadMessageCreated;
use App\Jobs\DownloadThreadAttachment;
use App\Support\SafeBroadcast;
use App\Models\Thread;
use App\Models\ThreadMessage;
use Illuminate\Http\UploadedFile;

class SendThreadMessage
{
    public function __construct(private FreelancerMessenger $messenger) {}

    /**
     * @param  array<int, UploadedFile>  $files
     */
    public function send(
        Thread $thread,
        ?string $text,
        array $files = [],
        ?int $senderUserId = null,
        bool $sentByAi = false
    ): ?ThreadMessage {
        $result = $this->messenger->sendMessage((int) $thread->freelancer_thread_id, $text, $files);

        if ($result === null) {
            return null;
        }

        $now = now();

        $stored = $thread->messages()->create([
            'freelancer_message_id' => $result['id'] ?? null,
            'direction' => 'sent',
            'sender_user_id' => $senderUserId,
            'message' => $text,
            'message_time' => $now,
            'sent_by_ai' => $sentByAi,
        ]);

        foreach ($files as $file) {
            $attachment = $stored->attachments()->make([
                'filename' => $file->getClientOriginalName(),
                'url' => '', // outbound: source of truth is our local copy
                'mime_type' => $file->getClientMimeType(),
                'size' => $file->getSize(),
            ]);

            // We hold the bytes already — mirror them locally so the sent
            // attachment is viewable/downloadable in browser + mobile too.
            $path = $file->store('thread-attachments', DownloadThreadAttachment::DISK);
            $attachment->stored_path = $path;
            $attachment->stored_disk = DownloadThreadAttachment::DISK;
            $stored->attachments()->save($attachment);
        }

        SafeBroadcast::event(new ThreadMessageCreated($stored));

        $thread->last_message_at = $now;
        if ($thread->status === 'fresh') {
            $thread->status = 'answered';
        }
        $thread->save();

        return $stored;
    }
}
