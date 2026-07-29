<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Picks a transition number for a project via OpenAI. Same fail-safe idiom as
 * ThreadMatcher: bounded retries, fence/prose-tolerant JSON parsing, never
 * throws, null on failure or when the reply is outside the valid set.
 */
class ThreadAllocator
{
    private const MODEL = 'gpt-3.5-turbo';

    private const MAX_ATTEMPTS = 2;

    /**
     * @param  array<int, int>  $validNumbers
     */
    public function allocate(string $title, string $description, string $allocationPrompt, array $validNumbers): ?int
    {
        if ($validNumbers === []) {
            return null;
        }

        $bearer = 'Bearer '.config('variables.openAIKey');
        $url = 'https://api.openai.com/v1/chat/completions';

        $payload = [
            'model' => self::MODEL,
            'temperature' => 0,
            'messages' => [
                ['role' => 'system', 'content' => $this->systemPrompt($allocationPrompt, $validNumbers)],
                ['role' => 'user', 'content' => "Project: {$title}\n\n{$description}"],
            ],
        ];

        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            try {
                $response = Http::timeout(60)->withHeaders(['Authorization' => $bearer])->post($url, $payload);

                if ($response->successful()) {
                    $number = $this->parse($response->json('choices.0.message.content'));
                    if ($number !== null && in_array($number, $validNumbers, true)) {
                        return $number;
                    }
                    Log::warning('ThreadAllocator: unusable reply (attempt '.$attempt.')');
                } else {
                    Log::warning('ThreadAllocator: HTTP '.$response->status()." (attempt {$attempt})");
                }
            } catch (\Throwable $e) {
                Log::warning('ThreadAllocator: exception '.$e->getMessage()." (attempt {$attempt})");
            }
        }

        return null;
    }

    /**
     * @param  array<int, int>  $validNumbers
     */
    private function systemPrompt(string $allocationPrompt, array $validNumbers): string
    {
        $intro = trim($allocationPrompt) !== ''
            ? trim($allocationPrompt)
            : 'You are a work router. Choose the number whose lane best fits the project.';

        $list = implode(', ', $validNumbers);

        return $intro."\n\nValid numbers: {$list}."
            ."\nReply with ONLY a JSON object of the form {\"number\": <one of the valid numbers>}. "
            .'Output nothing but the JSON.';
    }

    private function parse(?string $raw): ?int
    {
        $text = trim((string) $raw);
        $text = preg_replace('/^```(?:json)?/i', '', $text);
        $text = preg_replace('/```\s*$/', '', $text);
        $text = trim($text);

        $data = json_decode($text, true);
        if (! is_array($data) && preg_match('/\{.*\}/s', $text, $m)) {
            $data = json_decode($m[0], true);
        }

        if (! is_array($data) || ! isset($data['number']) || ! is_numeric($data['number'])) {
            return null;
        }

        return (int) $data['number'];
    }
}
