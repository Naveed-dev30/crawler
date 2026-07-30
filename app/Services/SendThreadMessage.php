<?php

namespace App\Services;

use App\Events\ThreadMessageCreated;
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

        $stored = $thread->messages()->create([
            'freelancer_message_id' => $result['id'] ?? null,
            'direction' => 'sent',
            'sender_user_id' => $senderUserId,
            'message' => $text,
            'message_time' => now(),
            'sent_by_ai' => $sentByAi,
        ]);

        foreach ($files as $file) {
            $stored->attachments()->create([
                'filename' => $file->getClientOriginalName(),
                'url' => '', // outbound attachment; content lives on Freelancer
                'mime_type' => $file->getClientMimeType(),
                'size' => $file->getSize(),
            ]);
        }

        event(new ThreadMessageCreated($stored));

        if ($thread->status === 'fresh') {
            $thread->status = 'answered';
            $thread->save();
        }

        return $stored;
    }
}
