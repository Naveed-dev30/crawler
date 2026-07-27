<?php
// app/Services/AiReplyGenerator.php
namespace App\Services;

use App\Models\Thread;
use App\Models\ThreadMessage;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Drafts an AI reply to a client message using the assigned user's profile
 * prompt and recent thread history. Same fail-safe idiom as ThreadMatcher:
 * bounded retries, never throws, null on failure.
 */
class AiReplyGenerator
{
    private const MODEL = 'gpt-3.5-turbo';
    private const MAX_ATTEMPTS = 2;
    private const HISTORY = 10;

    public function generate(Thread $thread, ThreadMessage $clientMessage): ?string
    {
        $profile = trim((string) ($thread->assignedUser?->profile_prompt ?? ''));
        $system = "You are replying to a client on a freelancing marketplace on behalf of a freelancer. "
            . "Write a concise, professional reply as the freelancer. Do not include a signature.\n\n"
            . "Freelancer profile:\n" . ($profile !== '' ? $profile : 'Experienced freelancer.');

        $history = $thread->messages()
            ->orderByDesc('message_time')
            ->limit(self::HISTORY)
            ->get()
            ->reverse()
            ->map(fn ($m) => ($m->direction === 'received' ? 'Client' : 'Freelancer') . ': ' . (string) $m->message)
            ->implode("\n");

        $payload = [
            'model' => self::MODEL,
            'temperature' => 0.4,
            'messages' => [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user', 'content' => "Conversation so far:\n{$history}\n\nWrite the freelancer's next reply."],
            ],
        ];

        $bearer = 'Bearer ' . config('variables.openAIKey');
        $url = 'https://api.openai.com/v1/chat/completions';

        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            try {
                $response = Http::timeout(60)->withHeaders(['Authorization' => $bearer])->post($url, $payload);
                if ($response->successful()) {
                    $text = trim((string) $response->json('choices.0.message.content'));
                    if ($text !== '') {
                        return $text;
                    }
                    Log::warning("AiReplyGenerator: empty reply (attempt {$attempt})");
                } else {
                    Log::warning('AiReplyGenerator: HTTP ' . $response->status() . " (attempt {$attempt})");
                }
            } catch (\Throwable $e) {
                Log::warning('AiReplyGenerator: exception ' . $e->getMessage() . " (attempt {$attempt})");
            }
        }

        return null;
    }
}
