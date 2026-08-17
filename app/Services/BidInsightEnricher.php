<?php

namespace App\Services;

use App\Models\BidInsight;
use App\Models\Proposal;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Fills the two Bid Insights columns the crawler payload never carries, which
 * is why "Time to Bid" and "Winning Bid" render as dashes on the page:
 *
 *  - time_to_bid_seconds — derived locally as our bid's submit time minus the
 *    project's posted time (proposals.project_added_time). No network needed.
 *  - winning_bid_* — read from Freelancer's bids endpoint, which returns the
 *    awarded bid of a project even when the winner is somebody else. Sealed
 *    winners come back as amount 0 with sealed=true, so those are recorded as
 *    sealed rather than as a zero bid.
 *
 * Both passes only ever fill blanks: an award is final, and anything the
 * crawler did manage to send stays untouched.
 */
class BidInsightEnricher
{
    /** Projects per bids-endpoint call — the same batch size the award check uses. */
    private const CHUNK = 100;

    /**
     * @return array{time_to_bid: int, winning_bid: int}
     */
    public function run(int $days = 60, ?int $limit = null): array
    {
        return [
            'time_to_bid' => $this->backfillTimeToBid(),
            'winning_bid' => $this->syncWinningBids($days, $limit),
        ];
    }

    /**
     * Time to bid = bid submitted at − project posted at, for every insight row
     * whose project we still have a proposal for.
     */
    public function backfillTimeToBid(): int
    {
        $filled = 0;

        BidInsight::whereNull('time_to_bid_seconds')
            ->whereNotNull('time_submitted')
            ->orderBy('id')
            ->chunkById(500, function ($rows) use (&$filled) {
                $posted = Proposal::whereIn('project_id', $rows->pluck('project_id')->all())
                    ->pluck('project_added_time', 'project_id');

                foreach ($rows as $row) {
                    $addedAt = $posted[$row->project_id] ?? null;

                    if (! $addedAt) {
                        continue;
                    }

                    $seconds = $row->time_submitted->getTimestamp() - (int) $addedAt;

                    // A bid can't predate its project; a negative delta means
                    // mismatched timestamps, and the column is unsigned anyway.
                    if ($seconds < 0) {
                        continue;
                    }

                    $row->time_to_bid_seconds = $seconds;
                    $row->save();
                    $filled++;
                }
            });

        return $filled;
    }

    /**
     * Pull the awarded bid for every project we bid on that has no winner
     * recorded yet. Projects still open simply come back with no bid.
     *
     * @param  int  $days  only look at bids scraped this recently; 0 = no limit
     */
    public function syncWinningBids(int $days = 60, ?int $limit = null): int
    {
        $query = BidInsight::whereNull('winning_bid_amount')
            ->where(function ($q) {
                $q->whereNull('winning_bid_sealed')->orWhere('winning_bid_sealed', false);
            })
            // Client-only rows (crawled projects we never bid on) have nothing
            // to compare a winner against, so skip them.
            ->where(function ($q) {
                $q->whereNotNull('bid_id')
                    ->orWhereNotNull('time_submitted')
                    ->orWhereNotNull('bid_amount');
            })
            ->when($days > 0, fn ($q) => $q->where('last_scraped_at', '>=', now()->subDays($days)))
            ->orderByDesc('last_scraped_at');

        if ($limit !== null) {
            $query->limit($limit);
        }

        $projectIds = $query->pluck('project_id')->unique()->values();

        if ($projectIds->isEmpty()) {
            return 0;
        }

        $flKey = config('variables.flKey');
        $base = rtrim((string) config('variables.flBase'), '/');
        $filled = 0;

        foreach ($projectIds->chunk(self::CHUNK) as $chunk) {
            $query = 'award_statuses[]=awarded';
            foreach ($chunk as $projectId) {
                $query .= '&projects[]='.$projectId;
            }

            try {
                $response = Http::timeout(60)
                    ->withHeaders(['Freelancer-OAuth-V1' => $flKey])
                    ->get($base.'/api/projects/0.1/bids/?'.$query);

                if (! $response->successful()) {
                    Log::warning('Winning bid sync: HTTP '.$response->status());

                    continue;
                }

                foreach ($response->json('result.bids') ?? [] as $bid) {
                    $filled += $this->storeWinner($bid) ? 1 : 0;
                }
            } catch (\Throwable $e) {
                Log::warning('Winning bid sync exception: '.$e->getMessage());

                continue;
            }
        }

        return $filled;
    }

    private function storeWinner(array $bid): bool
    {
        $projectId = $bid['project_id'] ?? null;

        if (! $projectId || ($bid['award_status'] ?? 'awarded') !== 'awarded') {
            return false;
        }

        $amount = $bid['amount'] ?? null;
        $amount = is_numeric($amount) ? (float) $amount : null;

        // Sealed projects hide the winner's number — the endpoint reports it as
        // 0 — so record the fact it was sealed instead of a bogus 0.00.
        $attributes = ($amount === null || $amount <= 0)
            ? (($bid['sealed'] ?? false) ? ['winning_bid_sealed' => true] : [])
            : array_filter([
                'winning_bid_amount' => $amount,
                'winning_bid_sealed' => false,
                'winning_bid_text' => $bid['description'] ?? null,
            ], fn ($v) => $v !== null);

        if ($attributes === []) {
            return false;
        }

        $row = BidInsight::where('project_id', (int) $projectId)->first();

        // A project can award more than one freelancer, so the endpoint may
        // return several winners for it. First real amount wins, and it is
        // never replaced — an award doesn't change after the fact.
        if ($row === null || $row->winning_bid_amount !== null) {
            return false;
        }

        if ($row->winning_bid_sealed && ! array_key_exists('winning_bid_amount', $attributes)) {
            return false;
        }

        // Filling a blank, not changing a value the crawler reported, so this
        // deliberately writes no bid_insight_changes audit row.
        $row->fill($attributes)->save();

        return true;
    }
}
