<?php

namespace App\Services;

use App\Models\Bid;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class BidAwardChecker
{
    public function run(): void
    {
        $bids = Bid::where('bid_status', 'completed')
            ->where('awarded', false)
            ->with('proposal')
            ->get();

        // Index our bids by project_id (one bid per project per bidder).
        $byProject = [];
        foreach ($bids as $bid) {
            $pid = $bid->proposal->project_id ?? null;
            if ($pid) {
                $byProject[$pid] = $bid;
            }
        }

        if (empty($byProject)) {
            return;
        }

        $flUserId = config('variables.flUserId');
        $flKey = config('variables.flKey');

        foreach (array_chunk(array_keys($byProject), 100) as $chunk) {
            $query = 'compact=true&bidders[]=' . $flUserId;
            foreach ($chunk as $pid) {
                $query .= '&projects[]=' . $pid;
            }
            $url = rtrim(config('variables.flBase'), '/') . '/api/projects/0.1/bids/?' . $query;

            try {
                $response = Http::timeout(60)
                    ->withHeaders(['Freelancer-OAuth-V1' => $flKey])
                    ->get($url);

                if (!$response->successful()) {
                    Log::warning('Award check: HTTP ' . $response->status());
                    continue;
                }

                $returnedBids = $response->json('result.bids') ?? [];
                foreach ($returnedBids as $rb) {
                    $pid = $rb['project_id'] ?? null;
                    if (!$pid || !isset($byProject[$pid])) {
                        continue;
                    }
                    if (($rb['award_status'] ?? null) !== 'awarded') {
                        continue;
                    }

                    $bid = $byProject[$pid];
                    $bid->awarded = true;
                    $bid->awarded_price = $rb['amount'] ?? $bid->price;
                    $bid->save();
                }
            } catch (\Throwable $e) {
                Log::warning('Award check exception: ' . $e->getMessage());
                continue;
            }
        }
    }
}
