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
     * negative prompt, and capture the model's reason. Also picks the best profile.
     *
     * @return array{qualified: bool, reason: string, profile_id: int|null}
     *
     * Fail-closed: any API error, timeout, or unparseable reply after retries
     * returns ['qualified' => false, 'reason' => '', 'profile_id' => null]. Never throws.
     */
    public function qualify(string $negativePrompt, array $profiles, string $description): array
    {
        $bearer = 'Bearer '.config('variables.openAIKey');
        $url = 'https://api.openai.com/v1/chat/completions';

        $system = 'You are a strict project filter and profile router. ';

        if (trim($negativePrompt) !== '') {
            $system .= 'The user does NOT want to bid on projects matching these negative '
                .'criteria: '.$negativePrompt.'. Set "qualified" to false if the project '
                .'MATCHES the negative criteria (skip it), or true if it does NOT match. ';
        } else {
            $system .= 'There are no skip criteria; always set "qualified" to true. ';
        }

        $profileIds = array_map(fn ($p) => (int) $p['id'], $profiles);
        if (! empty($profiles)) {
            $list = implode('; ', array_map(fn ($p) => $p['id'].': '.$p['title'], $profiles));
            $system .= 'Available bidding profiles (id: title) are ['.$list.']. Choose the '
                .'single best-matching profile id for this project following any guidance '
                .'in the criteria above; use exactly one of these ids, or null if none fit. ';
        }

        $system .= 'Reply with ONLY a JSON object of the form '
            .'{"qualified": <true|false>, "reason": "<short reason>", "profile_id": <id|null>}. '
            .'"reason" is a short phrase. Output nothing but the JSON.';

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
                    $parsed = $this->parse($response->json('choices.0.message.content'), $profileIds);
                    if ($parsed !== null) {
                        return $parsed;
                    }
                    Log::warning('ProposalQualifier: unparseable reply (attempt '.$attempt.')');
                } else {
                    Log::warning('ProposalQualifier: HTTP '.$response->status()." (attempt {$attempt})");
                }
            } catch (\Throwable $e) {
                Log::warning('ProposalQualifier: exception '.$e->getMessage()." (attempt {$attempt})");
            }
        }

        return ['qualified' => false, 'reason' => '', 'profile_id' => null];
    }

    /**
     * @param  array<int, int>  $profileIds  valid ids the model may choose from
     * @return array{qualified: bool, reason: string, profile_id: int|null}|null
     */
    private function parse(?string $raw, array $profileIds = []): ?array
    {
        $text = trim((string) $raw);
        $text = preg_replace('/^```(?:json)?/i', '', $text);
        $text = preg_replace('/```\s*$/', '', $text);
        $text = trim($text);

        $data = json_decode($text, true);

        if (! is_array($data) && preg_match('/\{.*\}/s', $text, $m)) {
            $data = json_decode($m[0], true);
        }

        if (! is_array($data) || ! array_key_exists('qualified', $data) || ! is_bool($data['qualified'])) {
            return null;
        }

        $profileId = $data['profile_id'] ?? null;
        $profileId = (is_numeric($profileId) && in_array((int) $profileId, $profileIds, true))
            ? (int) $profileId
            : null;

        return [
            'qualified' => $data['qualified'],
            'reason' => is_string($data['reason'] ?? null) ? trim($data['reason']) : '',
            'profile_id' => $profileId,
        ];
    }
}
