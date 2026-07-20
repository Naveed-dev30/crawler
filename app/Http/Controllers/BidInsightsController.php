<?php

namespace App\Http\Controllers;

use App\Models\BidInsight;
use App\Models\BidInsightChange;
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
                if (! is_array($item)) {
                    $skipped++;
                    continue;
                }
                $pid = $item['project_id'] ?? null;
                if (! (is_int($pid) || (is_string($pid) && ctype_digit($pid)))) {
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
        $changeCount = 0;

        foreach (BidInsight::ONE_TIME_FIELDS as $field) {
            if ($existing->{$field} === null && array_key_exists($field, $item)) {
                $existing->{$field} = $item[$field];
            }
        }

        foreach (BidInsight::RECURRING_FIELDS as $field) {
            if (! array_key_exists($field, $item)) {
                continue;
            }
            $old = $existing->{$field};
            $new = $item[$field];
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
}
