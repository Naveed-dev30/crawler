<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ProposalQualifier
{
    private const MODEL = 'gpt-3.5-turbo';
    private const MAX_ATTEMPTS = 2; // initial try + 1 retry

    /**
     * Decide whether to proceed with a bid for a proposal, given the operator's
     * negative prompt. Returns true = proceed, false = skip.
     *
     * Fail-closed: any API error, timeout, or non-true/false reply after retries
     * returns false (skip). Never throws.
     */
    public function qualify(string $negativePrompt, string $description): bool
    {
        $bearer = 'Bearer ' . config('variables.openAIKey');
        $url = 'https://api.openai.com/v1/chat/completions';

        $system = 'You are a strict project filter. The user does NOT want to bid on '
            . 'projects matching these negative criteria: ' . $negativePrompt . '. '
            . 'Given the project description, reply with exactly one word — "false" if '
            . 'the project MATCHES the negative criteria (it should be skipped), or "true" '
            . 'if it does NOT match (safe to proceed). Reply only true or false, nothing else.';

        $payload = [
            'model' => self::MODEL,
            'temperature' => 0,
            'messages' => [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user', 'content' => $description],
            ],
        ];

        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            try {
                $response = Http::timeout(60)
                    ->withHeaders(['Authorization' => $bearer])
                    ->post($url, $payload);

                if ($response->successful()) {
                    $raw = $response->json('choices.0.message.content');
                    $verdict = $this->parse($raw);
                    if ($verdict !== null) {
                        return $verdict;
                    }
                    Log::warning("ProposalQualifier: unparseable reply '" . trim((string) $raw) . "' (attempt {$attempt})");
                } else {
                    Log::warning('ProposalQualifier: HTTP ' . $response->status() . " (attempt {$attempt})");
                }
            } catch (\Throwable $e) {
                Log::warning('ProposalQualifier: exception ' . $e->getMessage() . " (attempt {$attempt})");
            }
        }

        Log::info('ProposalQualifier: no clear verdict after retries → skipping proposal (fail-closed)');
        return false;
    }

    /**
     * Parse a model reply to a strict boolean. Returns null when the reply is not
     * unambiguously true or false.
     */
    private function parse(?string $raw): ?bool
    {
        $t = strtolower(trim((string) $raw));
        $t = trim($t, " \t\n\r\0\x0B.\"'`");

        if ($t === 'true') {
            return true;
        }
        if ($t === 'false') {
            return false;
        }
        return null;
    }
}
