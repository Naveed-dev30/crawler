<?php

namespace App\Http\Controllers;

use App\Models\InsightSnapshot;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class InsightsController extends Controller
{
    public function ingest(Request $request)
    {
        $payload = $request->all();

        $userStats = is_array($payload['userStats'] ?? null) ? $payload['userStats'] : null;
        $marketStats = is_array($payload['marketplaceStats'] ?? null) ? $payload['marketplaceStats'] : null;

        if ($userStats === null && $marketStats === null) {
            return response()->json(['message' => 'Invalid payload'], 422);
        }

        $userStats = $userStats ?? [];
        $marketStats = $marketStats ?? [];

        $rawTs = $payload['scraped_at'] ?? null;
        try {
            $scrapedAt = (is_string($rawTs) && $rawTs !== '') ? Carbon::parse($rawTs) : now();
        } catch (\Throwable $e) {
            $scrapedAt = now();
        }

        $snapshot = InsightSnapshot::updateOrCreate(
            ['scraped_at' => $scrapedAt],
            [
                'earnings_total' => $this->parseMoney($userStats['totalEarnings'][0]['value'] ?? null),
                'earnings_30d' => $this->parseMoney($userStats['totalEarnings'][1]['value'] ?? null),
                'bids_remaining' => $this->bidSummaryValue($userStats['bidSummary'] ?? null, 'Bids Remaining'),
                'unearned_bids' => $this->bidSummaryValue($userStats['bidSummary'] ?? null, 'Unearned Bids'),
                'overall_ranking' => $this->stringOrNull($marketStats['overallRanking'][0]['value'] ?? null),
                'job_proficiency' => $this->arrayOrNull($userStats['jobProficiency'] ?? null),
                'rating_per_skill' => $this->arrayOrNull($userStats['ratingPerSkill'] ?? null),
                'ranking_per_skill' => $this->arrayOrNull($marketStats['rankingPerSkill'] ?? null),
                'high_demand_skills' => $this->arrayOrNull($marketStats['highDemandSkills'] ?? null),
                'trending_skills' => $this->arrayOrNull($marketStats['trendingSkills'] ?? null),
                'bids_per_milestone' => [
                    'user' => $userStats['bidsPerMilestone'] ?? null,
                    'marketplace' => $marketStats['bidsPerMilestoneMarketplace'] ?? null,
                ],
                'profile_views_week' => $this->arrayOrNull($marketStats['profileViewCountPastWeek'] ?? null),
                'profile_views_year' => $this->arrayOrNull($marketStats['profileViewCountPastYear'] ?? null),
                'earnings_over_time' => $this->arrayOrNull($userStats['earningsOverTime'] ?? null),
                'bid_conversion' => $this->arrayOrNull($userStats['bidConversion'] ?? null),
                'raw' => json_encode($payload),
            ]
        );

        return response()->json(['success' => true, 'id' => $snapshot->id]);
    }

    private function parseMoney(mixed $value): ?float
    {
        if (! is_string($value) && ! is_numeric($value)) {
            if ($value !== null) {
                Log::warning('insights ingest: unparseable money value', ['value' => $value]);
            }

            return null;
        }
        $clean = preg_replace('/[^0-9.\-]/', '', (string) $value);

        return is_numeric($clean) ? (float) $clean : null;
    }

    private function bidSummaryValue(mixed $summary, string $label): ?int
    {
        if (! is_array($summary)) {
            return null;
        }
        foreach ($summary as $item) {
            if (is_array($item) && ($item['label'] ?? null) === $label && is_numeric($item['value'] ?? null)) {
                return (int) $item['value'];
            }
        }

        return null;
    }

    private function arrayOrNull(mixed $value): ?array
    {
        if ($value !== null && ! is_array($value)) {
            Log::warning('insights ingest: expected array section', ['value' => $value]);

            return null;
        }

        return is_array($value) ? $value : null;
    }

    private function stringOrNull(mixed $value): ?string
    {
        return (is_string($value) || is_numeric($value)) ? (string) $value : null;
    }
}
