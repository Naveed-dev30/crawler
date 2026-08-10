<?php

namespace App\Console\Commands;

use App\Services\AiGate;
use Illuminate\Console\Command;

class ProbeAiRateLimit extends Command
{
    protected $signature = 'ai:probe';

    protected $description = 'Re-enable AI if it was auto-disabled by a rate limit and OpenAI now responds.';

    public function handle(AiGate $gate): int
    {
        if ($gate->enabled()) {
            return self::SUCCESS; // already on, nothing to do
        }

        // Only auto-recover rate-limit trips. A manual (admin) disable stays off
        // until an admin turns it back on.
        if ($gate->disabledReason() !== AiGate::REASON_RATE_LIMIT) {
            $this->line('AI disabled manually — leaving off.');

            return self::SUCCESS;
        }

        if ($gate->tryEnable()) {
            $this->info('OpenAI responded — AI re-enabled.');
        } else {
            $this->line('Still rate-limited — staying off.');
        }

        return self::SUCCESS;
    }
}
