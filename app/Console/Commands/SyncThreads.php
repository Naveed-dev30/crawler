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
            //
            // The TTL must outlive a worst-case pass, or the lock expires
            // mid-flight and the next iteration starts a second one: both then
            // reach ThreadMessage::create() for the same message, the unique
            // index on freelancer_message_id rejects the loser, and the outer
            // catch swallows it — killing the rest of that pass. A pass can
            // legitimately run long, since the Freelancer client allows 60s per
            // call and a thread can import 200 messages.
            $lock = Cache::lock('threads:sync', 180);
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
