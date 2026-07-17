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
     * negative prompt, and capture the model's reason.
     *
     * @return array{qualified: bool, reason: string}
     *
     * Fail-closed: any API error, timeout, or unparseable reply after retries
     * returns ['qualified' => false, 'reason' => '']. Never throws.
     */
    public function qualify(string $negativePrompt, string $description): array
    {
        $bearer = 'Bearer ' . config('variables.openAIKey');
        $url = 'https://api.openai.com/v1/chat/completions';

        $system = 'You are a strict project filter. The user does NOT want to bid on '
            . 'projects matching these negative criteria: ' . $negativePrompt . '. '
            . 'Given the project description, decide whether to skip it. Reply with ONLY a '
            . 'JSON object of the form {"qualified": <true|false>, "reason": "<short reason>"}. '
            . 'Set "qualified" to false if the project MATCHES the negative criteria (skip it), '
            . 'or true if it does NOT match (safe to proceed). "reason" is a short phrase '
            . 'naming the criteria matched or why it is safe. Output nothing but the JSON.';

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
                    $parsed = $this->parse($response->json('choices.0.message.content'));
                    if ($parsed !== null) {
                        return $parsed;
                    }
                    Log::warning('ProposalQualifier: unparseable reply (attempt ' . $attempt . ')');
                } else {
                    Log::warning('ProposalQualifier: HTTP ' . $response->status() . " (attempt {$attempt})");
                }
            } catch (\Throwable $e) {
                Log::warning('ProposalQualifier: exception ' . $e->getMessage() . " (attempt {$attempt})");
            }
        }

        Log::info('ProposalQualifier: no clear verdict after retries → skipping proposal (fail-closed)');

        return ['qualified' => false, 'reason' => ''];
    }

    /**
     * Parse a model reply (a JSON object, possibly wrapped in a markdown fence)
     * into ['qualified' => bool, 'reason' => string]. Returns null when the reply
     * has no usable boolean "qualified".
     *
     * @return array{qualified: bool, reason: string}|null
     */
    private function parse(?string $raw): ?array
    {
        $text = trim((string) $raw);

        // Strip a leading/trailing markdown code fence if present.
        $text = preg_replace('/^```(?:json)?/i', '', $text);
        $text = preg_replace('/```\s*$/', '', $text);
        $text = trim($text);

        $data = json_decode($text, true);

        // Fallback: if the reply wraps the JSON object in prose, extract the
        // first {...} object and decode that.
        if (! is_array($data) && preg_match('/\{.*\}/s', $text, $m)) {
            $data = json_decode($m[0], true);
        }

        if (! is_array($data) || ! array_key_exists('qualified', $data) || ! is_bool($data['qualified'])) {
            return null;
        }

        return [
            'qualified' => $data['qualified'],
            'reason' => is_string($data['reason'] ?? null) ? trim($data['reason']) : '',
        ];
    }
}
