<?php

namespace App\Http\Controllers;

use App\Models\BidInsight;
use App\Models\BidInsightChange;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class BidInsightsController extends Controller
{
    public function ingest(Request $request)
    {
        $payload = $request->all();

        Log::info('========================= bid insights ingest: payload', ['payload' => $payload]);

        $bids = $payload['bids'] ?? null;
        if (!is_array($bids)) {
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

        DB::transaction(function () use ($bids, $scrapedAt, &$created, &$updated, &$changes, &$skipped) {
            foreach ($bids as $item) {
                if (!is_array($item)) {
                    $skipped++;
                    continue;
                }
                $pid = $item['project_id'] ?? null;
                if (!(is_int($pid) || (is_string($pid) && ctype_digit($pid)))) {
                    $skipped++;
                    continue;
                }

                $mapped = $this->mapBid($item);

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
    private function mapBid(array $item): array
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
            if (!array_key_exists($field, $mapped) && array_key_exists($field, $item)) {
                $mapped[$field] = $item[$field];
            }
        }

        return $mapped;
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
            if (!array_key_exists($field, $mapped)) {
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
            return json_encode($value);
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        if (is_numeric($value)) {
            return (string) (float) $value;
        }

        return (string) $value;
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
        $bids = BidInsight::orderByDesc('last_scraped_at')->paginate(50);

        return view('content.pages.insights-bids', ['bids' => $bids]);
    }
}
