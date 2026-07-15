<?php

namespace App\Console\Commands;

use App\Services\BidAwardChecker;
use Illuminate\Console\Command;

class CheckBidAwards extends Command
{
    protected $signature = 'bids:check-awards';

    protected $description = 'Poll Freelancer for award status of placed bids';

    public function handle(): int
    {
        (new BidAwardChecker())->run();
        $this->info('Award check complete.');

        return self::SUCCESS;
    }
}
