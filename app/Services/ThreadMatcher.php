<?php

namespace App\Services;

use App\Models\Filter;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Picks the mobile user whose profile prompt best matches a project, via
 * OpenAI. Same fail-safe idiom as ProposalQualifier: bounded retries,
 * fence/prose-tolerant JSON parsing, never throws, null on failure.
 */
class ThreadMatcher
{
    private const MODEL = 'gpt-3.5-turbo';
    private const MAX_ATTEMPTS = 2; // initial try + 1 retry

    /**
     * @param array<int, string> $profiles user_id => profile_prompt
     * @return int|null matched user id, or null on failure
     */
    public function match(string $title, string $description, array $profiles): ?int
    {
        if ($profiles === []) {
            return null;
        }

        $bearer = 'Bearer ' . config('variables.openAIKey');
        $url = 'https://api.openai.com/v1/chat/completions';

        $payload = [
            'model' => self::MODEL,
            'temperature' => 0,
            'messages' => [
                ['role' => 'system', 'content' => $this->systemPrompt($profiles)],
                ['role' => 'user', 'content' => "Project: {$title}\n\n{$description}"],
            ],
        ];

        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            try {
                $response = Http::timeout(60)
                    ->withHeaders(['Authorization' => $bearer])
                    ->post($url, $payload);

                if ($response->successful()) {
                    $userId = $this->parse($response->json('choices.0.message.content'));
                    if ($userId !== null && array_key_exists($userId, $profiles)) {
                        return $userId;
                    }
                    Log::warning('ThreadMatcher: unusable reply (attempt ' . $attempt . ')');
                } else {
                    Log::warning('ThreadMatcher: HTTP ' . $response->status() . " (attempt {$attempt})");
                }
            } catch (\Throwable $e) {
                Log::warning('ThreadMatcher: exception ' . $e->getMessage() . " (attempt {$attempt})");
            }
        }

        return null;
    }

    /**
     * @param array<int, string> $profiles
     */
    private function systemPrompt(array $profiles): string
    {
        $custom = trim((string) (Filter::find(1)?->profile_match_prompt ?? ''));

        $intro = $custom !== ''
            ? $custom
            : 'You are a work router. Pick the single team member whose profile best '
                . 'matches the project. If none fits well, pick the closest anyway.';

        $rendered = '';
        foreach ($profiles as $id => $profile) {
            $rendered .= "ID {$id}:\n{$profile}\n---\n";
        }

        return $intro . "\n\nTeam member profiles:\n" . $rendered
            . "\nReply with ONLY a JSON object of the form {\"user_id\": <numeric id>}. "
            . 'Output nothing but the JSON.';
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

        if (! is_array($data) || ! isset($data['user_id']) || ! is_numeric($data['user_id'])) {
            return null;
        }

        return (int) $data['user_id'];
    }
}
