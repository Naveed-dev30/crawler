<?php

namespace App\Console\Commands;

use App\Models\BidInsight;
use App\Services\FreelancerProjectStats;
use Illuminate\Console\Command;

/**
 * Fills total_bids and the winning bid on existing rows, so a stored rank reads
 * "#3 of 234" and the winning-bid column stops being blank.
 *
 * The extension's insights payload carries neither figure — verified: no stored
 * raw payload contains a winning_bid key — so both are resolved from the
 * Freelancer API rather than by changing the capture.
 */
class BackfillBidMarketData extends Command
{
    protected $signature = 'bids:market-data
        {--limit=200 : Stop after this many projects}
        {--dry-run : Report what would change without writing anything}';

    protected $description = 'Backfill total bid count and winning bid on bid insights';

    public function handle(FreelancerProjectStats $stats): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $dry = (bool) $this->option('dry-run');

        // Rows we track (they have a rank) but cannot fully describe yet.
        // Newest first, since those are the ones anyone is looking at.
        $projectIds = BidInsight::whereNotNull('bid_rank')
            ->where(fn ($q) => $q->whereNull('total_bids')->orWhereNull('winning_bid_sealed'))
            ->orderByDesc('last_scraped_at')
            ->limit($limit)
            ->pluck('project_id')
            ->all();

        if ($projectIds === []) {
            $this->info('Nothing to do — every tracked bid already has totals and a winning bid.');

            return self::SUCCESS;
        }

        $this->info(($dry ? '[dry run] ' : '').'Looking up market data for '.count($projectIds).' project(s).');

        $counts = $stats->bidCounts($projectIds);
        $winners = $stats->winningBids($projectIds);

        $this->line('Totals returned for '.count($counts).' project(s); '
            .count($winners).' project(s) have an awarded bid.');

        $written = 0;

        foreach ($projectIds as $projectId) {
            $projectId = (int) $projectId;
            $update = [];

            if (isset($counts[$projectId])) {
                $update['total_bids'] = $counts[$projectId];
            }

            if (isset($winners[$projectId])) {
                $update['winning_bid_sealed'] = $winners[$projectId]['sealed'];

                // Sealed projects hide the figure — leave it null rather than
                // record the zero the API sends.
                if ($winners[$projectId]['amount'] !== null) {
                    $update['winning_bid_amount'] = $winners[$projectId]['amount'];
                }
                if ($winners[$projectId]['text'] !== null) {
                    $update['winning_bid_text'] = $winners[$projectId]['text'];
                }
            }

            if ($update === []) {
                continue;
            }

            if ($dry) {
                $this->line("  would set project {$projectId} → "
                    .json_encode(array_intersect_key($update, array_flip(['total_bids', 'winning_bid_amount', 'winning_bid_sealed']))));
                $written++;

                continue;
            }

            BidInsight::where('project_id', $projectId)->update($update);
            $written++;
        }

        $this->info(($dry ? 'Would update ' : 'Updated ').$written.' project(s).');

        return self::SUCCESS;
    }
}
