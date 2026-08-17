<?php

namespace App\Http\Controllers;

use App\Jobs\RefreshBidMarketDataJob;
use App\Models\BidInsight;
use App\Models\BidInsightChange;
use App\Models\Proposal;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BidInsightsController extends Controller
{
    public function ingest(Request $request)
    {
        $payload = $request->all();


        $bids = $payload['bids'] ?? null;
        if (! is_array($bids)) {
            return response()->json(['message' => 'Invalid payload'], 422);
        }

        $rawTs = $payload['scraped_at'] ?? null;
        try {
            $scrapedAt = (is_string($rawTs) && $rawTs !== '') ? Carbon::parse($rawTs) : now();
        } catch (\Throwable $e) {
            $scrapedAt = now();
        }

        $created = 0;
        $updated = 0;
        $changes = 0;
        $skipped = 0;

        // Time to bid is measured against the project's posted time, which the
        // crawler payload never carries — look it up from our own proposals.
        $postedAt = [];
        foreach (Proposal::whereIn('project_id', array_filter(array_map(
            fn ($item) => is_array($item) ? ($item['project_id'] ?? null) : null,
            $bids
        )))->pluck('project_added_time', 'project_id') as $projectId => $addedAt) {
            $postedAt[(int) $projectId] = $addedAt;
        }

        DB::transaction(function () use ($bids, $scrapedAt, $postedAt, &$created, &$updated, &$changes, &$skipped) {
            foreach ($bids as $item) {
                if (! is_array($item)) {
                    $skipped++;

                    continue;
                }
                $pid = $item['project_id'] ?? null;
                if (! (is_int($pid) || (is_string($pid) && ctype_digit($pid)))) {
                    $skipped++;

                    continue;
                }

                $mapped = $this->mapBid($item, $postedAt);

                $existing = BidInsight::where('project_id', (int) $item['project_id'])->first();

                if ($existing === null) {
                    $attributes = ['project_id' => (int) $item['project_id']];
                    foreach (array_merge(BidInsight::ONE_TIME_FIELDS, BidInsight::RECURRING_FIELDS) as $field) {
                        if (array_key_exists($field, $mapped)) {
                            $attributes[$field] = $mapped[$field];
                        }
                    }
                    $attributes['last_scraped_at'] = $scrapedAt;
                    $attributes['raw'] = $item;
                    BidInsight::create($attributes);
                    $created++;

                    continue;
                }

                $changes += $this->applyUpdate($existing, $mapped, $item, $scrapedAt);
                $updated++;
            }
        });

        // The payload carries our rank but neither the size of the field nor
        // the winning bid; look both up out of band.
        RefreshBidMarketDataJob::dispatch(
            collect($bids)
                ->filter(fn ($item) => is_array($item))
                ->pluck('project_id')
                ->filter(fn ($id) => is_int($id) || (is_string($id) && ctype_digit($id)))
                ->map(fn ($id) => (int) $id)
                ->unique()
                ->values()
                ->all()
        );

        return response()->json([
            'success' => true,
            'created' => $created,
            'updated' => $updated,
            'changes' => $changes,
            'skipped' => $skipped,
        ]);
    }

    /**
     * Translate the external crawler's payload keys into DB column names.
     * Keys already using DB column names pass through untouched, so both
     * the live payload shape and the original contract are accepted.
     */
    private function mapBid(array $item, array $postedAt = []): array
    {
        $mapped = [];

        if (array_key_exists('id', $item)) {
            $mapped['bid_id'] = $item['id'];
        }
        if (array_key_exists('amount', $item)) {
            $mapped['bid_amount'] = $item['amount'];
        }
        if (array_key_exists('rank', $item)) {
            $mapped['bid_rank'] = $item['rank'];
        }
        if (array_key_exists('action_taken', $item)) {
            $mapped['actions_taken'] = $item['action_taken'];
        }
        if (is_numeric($item['rating'] ?? null)) {
            // The client's rating of our bid — the third "Actions Taken" signal.
            // Sits outside action_taken because Freelancer sends it as a score.
            $mapped['bid_rating'] = $item['rating'];
        }
        if (is_numeric($item['time_submitted'] ?? null)) {
            $mapped['time_submitted'] = Carbon::createFromTimestamp((int) $item['time_submitted']);
        }
        if (array_key_exists('project_chats_initiated', $item) || array_key_exists('project_invites', $item)) {
            $mapped['client_engagement'] = [
                'project_chats_initiated' => $item['project_chats_initiated'] ?? null,
                'project_invites' => $item['project_invites'] ?? null,
            ];
        }

        foreach (array_merge(BidInsight::ONE_TIME_FIELDS, BidInsight::RECURRING_FIELDS) as $field) {
            if (! array_key_exists($field, $mapped) && array_key_exists($field, $item)) {
                $mapped[$field] = $item[$field];
            }
        }

        $derived = $this->deriveTimeToBid($mapped, $postedAt[(int) ($item['project_id'] ?? 0)] ?? null);
        if ($derived !== null) {
            $mapped['time_to_bid_seconds'] = $derived;
        }

        return $mapped;
    }

    /**
     * Seconds between the project going live and our bid landing. Only used
     * when the payload didn't state it; BidInsightEnricher applies the same
     * rule to rows that were ingested before their proposal was known.
     */
    private function deriveTimeToBid(array $mapped, mixed $addedAt): ?int
    {
        if (array_key_exists('time_to_bid_seconds', $mapped) || ! $addedAt) {
            return null;
        }

        $submitted = $mapped['time_submitted'] ?? null;

        if ($submitted === null) {
            return null;
        }

        try {
            $seconds = ($submitted instanceof Carbon ? $submitted : Carbon::parse($submitted))
                ->getTimestamp() - (int) $addedAt;
        } catch (\Throwable $e) {
            return null;
        }

        return $seconds >= 0 ? $seconds : null;
    }

    private function applyUpdate(BidInsight $existing, array $mapped, array $item, Carbon $scrapedAt): int
    {
        $changeCount = 0;

        foreach (BidInsight::ONE_TIME_FIELDS as $field) {
            if ($existing->{$field} === null && array_key_exists($field, $mapped)) {
                $existing->{$field} = $mapped[$field];
            }
        }

        foreach (BidInsight::RECURRING_FIELDS as $field) {
            if (! array_key_exists($field, $mapped)) {
                continue;
            }
            $old = $existing->{$field};
            $new = $mapped[$field];
            if ($this->normalize($old) !== $this->normalize($new)) {
                BidInsightChange::create([
                    'bid_insight_id' => $existing->id,
                    'field' => $field,
                    'old_value' => $this->stringify($old),
                    'new_value' => $this->stringify($new),
                    'observed_at' => $scrapedAt,
                ]);
                $existing->{$field} = $new;
                $changeCount++;
            }
        }

        $existing->last_scraped_at = $scrapedAt;
        $existing->raw = $item;
        $existing->save();

        return $changeCount;
    }

    private function normalize(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (is_array($value)) {
            // Key order varies between crawler payloads and DB round-trips;
            // sort so identical content never registers as a change.
            return json_encode($this->ksortRecursive($value));
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        if (is_numeric($value)) {
            return (string) (float) $value;
        }

        return (string) $value;
    }

    private function ksortRecursive(array $value): array
    {
        foreach ($value as &$item) {
            if (is_array($item)) {
                $item = $this->ksortRecursive($item);
            }
        }
        ksort($value);

        return $value;
    }

    private function stringify(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (is_array($value)) {
            return json_encode($value);
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        return (string) $value;
    }

    public function index()
    {
        $page = BidInsight::orderByDesc('last_scraped_at')->paginate(50);
        $page->getCollection()->each->makeHidden('raw');

        return response()->json($page);
    }

    public function changes(BidInsight $bidInsight)
    {
        return response()->json(
            $bidInsight->changes()->orderByDesc('observed_at')->paginate(50)
        );
    }

    public function page()
    {
        // storeClientInsight() writes a client-only bid_insights row for every
        // crawled project — including ones later marked Not Qualified (no bid).
        // Those show up here as rows with all-dash bid columns. Only surface a
        // row when it carries real bid data, or its project has a qualified
        // proposal we actually placed a bid on — a qualified proposal whose bid
        // failed (out of bids, rejected) has no bid data to ever show, and
        // BidInsightEnricher cannot fill one either. The row itself is kept
        // (mobile chat may use the client info).
        $bids = BidInsight::where(function ($q) {
            $q->whereNotNull('bid_id')
                ->orWhereNotNull('bid_amount')
                ->orWhereNotNull('time_submitted')
                ->orWhereNotNull('time_to_bid_seconds')
                ->orWhereNotNull('bid_rank')
                ->orWhereNotNull('winning_bid_amount')
                ->orWhere('winning_bid_sealed', true)
                ->orWhereIn('project_id', Proposal::where('qualified', true)
                    ->whereHas('bid', fn ($bid) => $bid->where('bid_status', 'completed'))
                    ->select('project_id'));
        })
            ->orderByDesc('last_scraped_at')
            ->paginate(20);

        return view('content.pages.insights-bids', ['bids' => $bids]);
    }
}
