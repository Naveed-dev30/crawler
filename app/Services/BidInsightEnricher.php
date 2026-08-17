<?php

namespace App\Services;

use App\Models\Bid;
use App\Models\BidInsight;
use App\Models\Proposal;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Fills the Bid Insights columns the crawler capture leaves blank:
 *
 *  - bid_id / bid_amount / time_submitted — the extension only ever sees the
 *    projects listed on /insights/bids, so every older bid of ours lands here
 *    as an all-dash row. Our own bid is re-read from Freelancer's bids
 *    endpoint, which knows the exact submit time our posted_at only
 *    approximates (median 2s out, but up to ~6 minutes).
 *  - bid_currency / project_url — never in any payload; both come straight
 *    off the proposal we crawled.
 *  - time_to_bid_seconds — derived locally as our bid's submit time minus the
 *    project's posted time (proposals.project_added_time). No network needed.
 *  - winning_bid_* — read from the same bids endpoint, which returns the
 *    awarded bid of a project even when the winner is somebody else. Sealed
 *    winners come back as amount 0 with sealed=true, so those are recorded as
 *    sealed rather than as a zero bid.
 *
 * Every pass only ever fills blanks: an award is final, and anything the
 * crawler did manage to send stays untouched.
 */
class BidInsightEnricher
{
    /** Projects per bids-endpoint call — the same batch size the award check uses. */
    private const CHUNK = 100;

    /**
     * @return array{own_bid: int, project_facts: int, time_to_bid: int, winning_bid: int}
     */
    public function run(int $days = 60, ?int $limit = null): array
    {
        // Order matters: our own bid supplies the submit time that time to bid
        // is measured from, so it has to land first.
        return [
            'own_bid' => $this->backfillOwnBids($days, $limit),
            'project_facts' => $this->backfillProjectFacts(),
            'time_to_bid' => $this->backfillTimeToBid(),
            'winning_bid' => $this->syncWinningBids($days, $limit),
        ];
    }

    /**
     * Re-read our own bid for every insight row that has no bid data at all.
     * These are the blank rows on the page: projects we bid on that were never
     * part of a capture, plus the client-identity rows ThreadSyncer creates.
     *
     * @param  int  $days  only look at rows scraped this recently; 0 = no limit
     */
    public function backfillOwnBids(int $days = 60, ?int $limit = null): int
    {
        $query = BidInsight::whereNull('bid_id')
            ->whereNull('time_submitted')
            // Only projects we actually placed a bid on. A qualified proposal
            // whose bid failed has no bid to show and must not be polled.
            ->whereIn('project_id', $this->placedBidProjects())
            ->when($days > 0, fn ($q) => $q->where('last_scraped_at', '>=', now()->subDays($days)))
            ->orderByDesc('last_scraped_at');

        if ($limit !== null) {
            $query->limit($limit);
        }

        $projectIds = $query->pluck('project_id')->unique()->values();

        if ($projectIds->isEmpty()) {
            return 0;
        }

        $filled = 0;

        foreach ($projectIds->chunk(self::CHUNK) as $chunk) {
            $bids = $this->fetchOwnBids($chunk);

            foreach ($chunk as $projectId) {
                $filled += $this->storeOwnBid((int) $projectId, $bids[(int) $projectId] ?? null) ? 1 : 0;
            }
        }

        return $filled;
    }

    /**
     * Currency and project link, straight off the proposal — the page shows a
     * bare number and a dead ↗ link without them.
     */
    public function backfillProjectFacts(): int
    {
        $filled = 0;

        BidInsight::where(function ($q) {
            $q->whereNull('bid_currency')->orWhereNull('project_url');
        })
            ->orderBy('id')
            ->chunkById(500, function ($rows) use (&$filled) {
                $proposals = Proposal::whereIn('project_id', $rows->pluck('project_id')->all())
                    ->get(['project_id', 'currency_name', 'seo_url'])
                    ->keyBy('project_id');

                foreach ($rows as $row) {
                    $proposal = $proposals[$row->project_id] ?? null;

                    if ($proposal === null) {
                        continue;
                    }

                    $attributes = array_filter([
                        'bid_currency' => $row->bid_currency === null ? $proposal->currency_name : null,
                        'project_url' => $row->project_url === null && $proposal->seo_url
                            ? rtrim((string) config('variables.flBase'), '/').'/projects/'.ltrim($proposal->seo_url, '/')
                            : null,
                    ], fn ($v) => $v !== null && $v !== '');

                    if ($attributes === []) {
                        continue;
                    }

                    $row->fill($attributes)->save();
                    $filled++;
                }
            });

        return $filled;
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

    /**
     * Project ids we have a placed bid on.
     */
    private function placedBidProjects()
    {
        return Bid::where('bids.bid_status', 'completed')
            ->join('proposals', 'proposals.id', '=', 'bids.proposal_id')
            ->select('proposals.project_id');
    }

    /**
     * Our own bids on the given projects, keyed by project id.
     */
    private function fetchOwnBids($projectIds): array
    {
        $query = 'compact=true&bidders[]='.config('variables.flUserId');
        foreach ($projectIds as $projectId) {
            $query .= '&projects[]='.$projectId;
        }

        try {
            $response = Http::timeout(60)
                ->withHeaders(['Freelancer-OAuth-V1' => config('variables.flKey')])
                ->get(rtrim((string) config('variables.flBase'), '/').'/api/projects/0.1/bids/?'.$query);

            if (! $response->successful()) {
                Log::warning('Own bid backfill: HTTP '.$response->status());

                return [];
            }
        } catch (\Throwable $e) {
            Log::warning('Own bid backfill exception: '.$e->getMessage());

            return [];
        }

        $bids = [];
        foreach ($response->json('result.bids') ?? [] as $bid) {
            $projectId = $bid['project_id'] ?? null;
            // Keep the earliest bid: a retracted-then-rebid project returns
            // both, and the first one is the one time-to-bid describes.
            if ($projectId && ! isset($bids[(int) $projectId])) {
                $bids[(int) $projectId] = $bid;
            }
        }

        return $bids;
    }

    private function storeOwnBid(int $projectId, ?array $bid): bool
    {
        $row = BidInsight::where('project_id', $projectId)->first();

        if ($row === null) {
            return false;
        }

        $attributes = [];

        if ($bid !== null) {
            if (isset($bid['id'])) {
                $attributes['bid_id'] = $bid['id'];
            }
            if ($row->bid_amount === null && is_numeric($bid['amount'] ?? null)) {
                $attributes['bid_amount'] = $bid['amount'];
            }
            if (is_numeric($bid['time_submitted'] ?? null)) {
                $attributes['time_submitted'] = Carbon::createFromTimestamp((int) $bid['time_submitted']);
            }
            if ($row->description === null && ! empty($bid['description'])) {
                $attributes['description'] = $bid['description'];
            }
        } elseif ($row->bid_amount === null) {
            // Freelancer no longer serves the project (deleted or expired).
            // Our own record still knows what we bid, so show that much rather
            // than a wholly blank row — but not posted_at as the submit time,
            // which is only an approximation and would skew time to bid.
            $amount = Bid::where('bids.bid_status', 'completed')
                ->join('proposals', 'proposals.id', '=', 'bids.proposal_id')
                ->where('proposals.project_id', $projectId)
                ->value('bids.price');

            if ($amount !== null) {
                $attributes['bid_amount'] = $amount;
            }
        }

        if ($attributes === []) {
            return false;
        }

        $row->fill($attributes)->save();

        return true;
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
