<?php

// app/Jobs/GenerateAiReplyJob.php

namespace App\Jobs;

use App\Models\Thread;
use App\Models\ThreadMessage;
use App\Services\AiReplyGenerator;
use App\Services\SendThreadMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;

class GenerateAiReplyJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public int $threadId, public int $clientMessageId) {}

    public function middleware(): array
    {
        return [(new WithoutOverlapping($this->threadId))->releaseAfter(30)->expireAfter(120)];
    }

    public function handle(SendThreadMessage $sender, AiReplyGenerator $generator): void
    {
        $thread = Thread::with('assignedUser')->find($this->threadId);
        $client = ThreadMessage::find($this->clientMessageId);
        if (! $thread || ! $client || ! $thread->assignedUser) {
            return;
        }

        if ($thread->blocked || ! $thread->assignedUser->aiActiveNow(Carbon::now('UTC'))) {
            return;
        }

        // A human (or an earlier AI pass) already answered after this client message.
        $answered = $thread->messages()
            ->where('direction', 'sent')
            ->where('message_time', '>=', $client->message_time)
            ->exists();
        if ($answered) {
            return;
        }

        $text = $generator->generate($thread, $client);
        if ($text === null) {
            return;
        }

        $sender->send($thread, $text, [], null, true);
    }
}
