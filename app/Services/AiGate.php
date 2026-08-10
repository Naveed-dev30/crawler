<?php

namespace App\Services;

use App\Models\Filter;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Global gate for all OpenAI usage. When a rate limit (HTTP 429) is hit we flip
 * the gate off so the app stops hammering the API; a scheduled probe (or an
 * admin) turns it back on once the API answers again.
 *
 * State lives on the singleton Filter row (id=1): ai_enabled, ai_disabled_reason,
 * ai_disabled_at.
 */
class AiGate
{
    public const REASON_RATE_LIMIT = 'rate_limit';

    public const REASON_MANUAL = 'manual';

    public function filter(): ?Filter
    {
        return Filter::find(1);
    }

    /**
     * Is AI usage currently allowed? Defaults to true when unconfigured so a
     * fresh install / missing row never silently blocks bidding.
     */
    public function enabled(): bool
    {
        $filter = $this->filter();

        return $filter === null ? true : (bool) $filter->ai_enabled;
    }

    public function disabledReason(): ?string
    {
        return $this->filter()?->ai_disabled_reason;
    }

    /**
     * Turn the gate off. No-op if already off (keeps the original reason/time).
     */
    public function disable(string $reason): void
    {
        $filter = $this->filter();
        if ($filter === null || ! $filter->ai_enabled) {
            return;
        }

        $filter->ai_enabled = false;
        $filter->ai_disabled_reason = $reason;
        $filter->ai_disabled_at = now();
        $filter->save();

        Log::warning("AiGate: AI disabled (reason={$reason}).");
    }

    public function markRateLimited(): void
    {
        $this->disable(self::REASON_RATE_LIMIT);
    }

    public function enable(): void
    {
        $filter = $this->filter();
        if ($filter === null) {
            return;
        }

        $filter->ai_enabled = true;
        $filter->ai_disabled_reason = null;
        $filter->ai_disabled_at = null;
        $filter->save();

        Log::info('AiGate: AI enabled.');
    }

    /**
     * Live check that OpenAI answers (cheap 1-token call). Never mutates state.
     * Returns false on 429, any error, or a missing key.
     */
    public function probe(): bool
    {
        $key = config('variables.openAIKey');
        if (empty($key)) {
            return false;
        }

        try {
            $response = Http::timeout(20)
                ->withHeaders(['Authorization' => 'Bearer '.$key])
                ->post('https://api.openai.com/v1/chat/completions', [
                    'model' => 'gpt-3.5-turbo',
                    'max_tokens' => 1,
                    'messages' => [['role' => 'user', 'content' => 'ping']],
                ]);

            return $response->successful();
        } catch (\Throwable $e) {
            Log::warning('AiGate probe: '.$e->getMessage());

            return false;
        }
    }

    /**
     * Probe first, enable only if the API answered. Returns the new enabled
     * state (true = now on, false = probe failed, still off).
     */
    public function tryEnable(): bool
    {
        if (! $this->probe()) {
            return false;
        }

        $this->enable();

        return true;
    }
}
