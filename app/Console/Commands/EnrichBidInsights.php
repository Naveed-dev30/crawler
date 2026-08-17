<?php

namespace App\Console\Commands;

use App\Services\BidInsightEnricher;
use Illuminate\Console\Command;

class EnrichBidInsights extends Command
{
    protected $signature = 'insights:enrich-bids
        {--days=60 : Only sync winners for bids scraped this recently; 0 = all}
        {--limit= : Cap how many projects are checked for a winner in one run}';

    protected $description = 'Fill Time to Bid and Winning Bid on bid insights';

    public function handle(BidInsightEnricher $enricher): int
    {
        $limit = $this->option('limit');

        $result = $enricher->run(
            (int) $this->option('days'),
            $limit === null ? null : max(1, (int) $limit),
        );

        $this->info("Own bid filled: {$result['own_bid']}");
        $this->info("Currency/URL filled: {$result['project_facts']}");
        $this->info("Time to bid filled: {$result['time_to_bid']}");
        $this->info("Winning bid filled: {$result['winning_bid']}");

        return self::SUCCESS;
    }
}
