<?php

namespace App\Console\Commands;

use App\Services\ThreadSyncer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class SyncThreads extends Command
{
    protected $signature = 'threads:sync {--once : Run a single sync pass and exit}';

    protected $description = 'Continuously sync Freelancer message threads every 10 seconds';

    public function handle(): int
    {
        do {
            $started = microtime(true);

            // Lock guards against two sync containers racing a pass.
            $lock = Cache::lock('threads:sync', 15);
            if ($lock->get()) {
                try {
                    app(ThreadSyncer::class)->run();
                } finally {
                    $lock->release();
                }
            }

            if ($this->option('once')) {
                break;
            }

            $elapsed = microtime(true) - $started;
            usleep((int) max(0, (10 - $elapsed) * 1_000_000));
        } while (true);

        return self::SUCCESS;
    }
}
