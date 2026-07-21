<?php

namespace App\Console\Commands;

use App\Services\ThreadEscalator;
use Illuminate\Console\Command;

class EscalateThreads extends Command
{
    protected $signature = 'threads:escalate';

    protected $description = 'Escalate unanswered fresh threads up the user ladder';

    public function handle(): int
    {
        app(ThreadEscalator::class)->run();
        $this->info('Escalation pass complete.');

        return self::SUCCESS;
    }
}
