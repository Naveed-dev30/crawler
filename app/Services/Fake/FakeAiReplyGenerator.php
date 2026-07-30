<?php

namespace App\Services\Fake;

use App\Models\Thread;
use App\Models\ThreadMessage;
use App\Services\AiReplyGenerator;

/**
 * Development stand-in for AiReplyGenerator (FL_FAKE=true). Returns a canned,
 * lightly contextual reply without calling OpenAI, so the auto-reply pipeline
 * can be exercised end-to-end locally with no API key or network traffic.
 */
class FakeAiReplyGenerator extends AiReplyGenerator
{
    public function generate(Thread $thread, ThreadMessage $clientMessage): ?string
    {
        $client = trim((string) $clientMessage->message);
        $snippet = $client !== '' ? ' regarding "' . str($client)->limit(60) . '"' : '';

        return "Thanks for your message{$snippet}. Yes, I'm available and happy to help — "
            . 'I can start this week and will share a short plan and timeline shortly. '
            . '(Auto-generated reply — FL_FAKE mode, no OpenAI call.)';
    }
}
