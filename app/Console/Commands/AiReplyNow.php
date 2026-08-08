<?php

namespace App\Console\Commands;

use App\Jobs\GenerateAiReplyJob;
use App\Models\Thread;
use App\Models\ThreadMessage;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Dev helper: run the AI auto-reply pipeline synchronously against an
 * existing thread, bypassing the sync-time trigger (which only fires on
 * newly stored inbound messages). Reports every guard that would stop a
 * reply, since the job itself returns silently.
 */
class AiReplyNow extends Command
{
    protected $signature = 'ai:reply-now
        {thread : Thread id}
        {--message= : Client (received) message id; defaults to the latest received}';

    protected $description = 'Synchronously generate + send an AI reply for a thread (dev/testing).';

    public function handle(): int
    {
        $thread = Thread::with('assignedUser')->find($this->argument('thread'));
        if (! $thread) {
            $this->error("Thread {$this->argument('thread')} not found.");

            return self::FAILURE;
        }

        $client = $this->option('message')
            ? ThreadMessage::where('thread_id', $thread->id)->find($this->option('message'))
            : $thread->messages()->where('direction', 'received')->latest('message_time')->first();

        if (! $client) {
            $this->error('No received client message to reply to.');

            return self::FAILURE;
        }
        if ($client->direction !== 'received') {
            $this->error("Message {$client->id} is not a received (client) message.");

            return self::FAILURE;
        }

        // Pre-flight: mirror the job's guards so the operator sees why a
        // reply would be skipped before dispatching.
        $now = Carbon::now('UTC');
        $user = $thread->assignedUser;
        $answered = $thread->messages()
            ->where('direction', 'sent')
            ->where('message_time', '>=', $client->message_time)
            ->exists();

        $this->table(['Guard', 'Value'], [
            ['thread', $thread->id],
            ['client message', $client->id . ' — "' . str($client->message)->limit(50) . '"'],
            ['assigned user', $user?->id ?? '— none (will skip)'],
            ['blocked', $thread->blocked ? 'true (will skip)' : 'false'],
            ['auto-reply enabled', config('variables.aiAutoReplyEnabled') ? 'true' : 'false (will skip — AI_AUTO_REPLY_ENABLED)'],
            ['aiActiveNow', $user ? ($user->aiActiveNow($now) ? 'true' : 'false (will skip)') : 'n/a'],
            ['already answered', $answered ? 'true (will skip)' : 'false'],
            ['openAIKey set', config('variables.openAIKey') ? 'yes' : 'no (generator returns null)'],
        ]);

        $before = $thread->messages()->where('sent_by_ai', true)->count();

        $this->info('Dispatching GenerateAiReplyJob synchronously…');
        GenerateAiReplyJob::dispatchSync($thread->id, $client->id);

        $after = $thread->messages()->where('sent_by_ai', true)->count();

        if ($after > $before) {
            $latest = $thread->messages()->where('sent_by_ai', true)->latest('message_time')->first();
            $this->info('AI reply sent: "' . str($latest->message)->limit(120) . '"');

            return self::SUCCESS;
        }

        $this->warn('No AI reply was sent — a guard above blocked it, or reply generation failed.');

        return self::SUCCESS;
    }
}
