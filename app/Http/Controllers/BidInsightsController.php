<?php

namespace App\Http\Controllers;

use App\Models\BidInsight;
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

        DB::transaction(function () use ($bids, $scrapedAt, &$created, &$updated, &$changes, &$skipped) {
            foreach ($bids as $item) {
                if (! is_array($item) || ! is_numeric($item['project_id'] ?? null)) {
                    $skipped++;
                    continue;
                }

                $existing = BidInsight::where('project_id', (int) $item['project_id'])->first();

                if ($existing === null) {
                    $attributes = ['project_id' => (int) $item['project_id']];
                    foreach (array_merge(BidInsight::ONE_TIME_FIELDS, BidInsight::RECURRING_FIELDS) as $field) {
                        if (array_key_exists($field, $item)) {
                            $attributes[$field] = $item[$field];
                        }
                    }
                    $attributes['last_scraped_at'] = $scrapedAt;
                    $attributes['raw'] = $item;
                    BidInsight::create($attributes);
                    $created++;
                    continue;
                }

                $changes += $this->applyUpdate($existing, $item, $scrapedAt);
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

    private function applyUpdate(BidInsight $existing, array $item, Carbon $scrapedAt): int
    {
        // Task 6 implements diffing; for now just touch scrape metadata.
        $existing->last_scraped_at = $scrapedAt;
        $existing->raw = $item;
        $existing->save();

        return 0;
    }
}
