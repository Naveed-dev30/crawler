<?php

namespace App\Jobs;

use App\Models\BidInsight;
use App\Services\FreelancerProjectStats;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Fills the two things the extension's insights payload never carries: how many
 * bids a project drew (so a rank reads "#3 of 234"), and what the winning bid
 * was. Both come from the Freelancer projects/bids endpoints.
 *
 * Queued rather than done inline in the ingest request: the extension posts a
 * whole page of bids at once, and several Freelancer round-trips must not hold
 * that request open.
 */
class RefreshBidMarketDataJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** @param  array<int, int>  $projectIds */
    public function __construct(private array $projectIds) {}

    public function handle(FreelancerProjectStats $stats): void
    {
        if ($this->projectIds === []) {
            return;
        }

        foreach ($stats->bidCounts($this->projectIds) as $projectId => $count) {
            // The field only grows while a project is open, so a stale larger
            // value is never overwritten by a smaller one from a partial read.
            BidInsight::where('project_id', $projectId)
                ->where(fn ($q) => $q->whereNull('total_bids')->orWhere('total_bids', '<', $count))
                ->update(['total_bids' => $count]);
        }

        foreach ($stats->winningBids($this->projectIds) as $projectId => $winner) {
            BidInsight::where('project_id', $projectId)->update(array_filter([
                'winning_bid_amount' => $winner['amount'],
                'winning_bid_sealed' => $winner['sealed'],
                'winning_bid_text' => $winner['text'],
            ], fn ($v) => $v !== null));
        }
    }
}
