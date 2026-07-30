<?php

namespace App\Http\Controllers;

use App\Models\InsightSnapshot;
use App\Support\InsightSkillMetric;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class InsightsController extends Controller
{
    public function index()
    {
        $latest = InsightSnapshot::orderByDesc('scraped_at')->first();

        $history = InsightSnapshot::orderByDesc('scraped_at')
            ->limit(90)
            ->get(['scraped_at', 'earnings_total', 'earnings_30d', 'bids_remaining', 'unearned_bids', 'overall_ranking'])
            ->reverse()
            ->values()
            ->map(fn ($s) => [
                'date' => $s->scraped_at->format('Y-m-d'),
                'earnings_total' => $s->earnings_total,
                'earnings_30d' => $s->earnings_30d,
                'bids_remaining' => $s->bids_remaining,
                'unearned_bids' => $s->unearned_bids,
                'overall_ranking' => $s->overall_ranking,
            ])
            ->all();

        return response()->json([
            'latest' => $latest?->makeHidden('raw'),
            'history' => $history,
        ]);
    }

    public function page(Request $request)
    {
        $minRaw = InsightSnapshot::min('scraped_at');
        $maxRaw = InsightSnapshot::max('scraped_at');
        $dateBounds = [
            'min' => $minRaw ? Carbon::parse($minRaw) : null,
            'max' => $maxRaw ? Carbon::parse($maxRaw) : null,
        ];

        [$from, $to] = $this->range($request);

        $latestQuery = InsightSnapshot::orderByDesc('scraped_at');
        if ($to) {
            $latestQuery->whereDate('scraped_at', '<=', $to);
        }
        $latest = $latestQuery->first();

        $prior = null;
        if ($latest) {
            $target = $latest->scraped_at->copy()->subDays(30)->toDateString();
            $prior = InsightSnapshot::whereDate('scraped_at', '<=', $target)
                ->orderByDesc('scraped_at')
                ->first();
        }

        $deltas = $this->buildDeltas($latest, $prior);

        $historyQuery = InsightSnapshot::orderByDesc('scraped_at');
        if ($from) {
            $historyQuery->whereDate('scraped_at', '>=', $from);
        }
        if ($to) {
            $historyQuery->whereDate('scraped_at', '<=', $to);
        }
        $history = $historyQuery->limit(365)
            ->get(['scraped_at', 'earnings_total', 'bids_remaining'])
            ->reverse()
            ->values()
            ->map(fn ($s) => [
                'date' => $s->scraped_at->format('Y-m-d'),
                'earnings_total' => $s->earnings_total,
                'bids_remaining' => $s->bids_remaining,
            ])
            ->all();

        return view('content.pages.insights', [
            'latest' => $latest,
            'history' => $history,
            'deltas' => $deltas,
            'refreshedAt' => $latest?->scraped_at,
            'snapshotCount' => InsightSnapshot::count(),
            'dateBounds' => $dateBounds,
            'from' => $from,
            'to' => $to,
        ]);
    }

    /**
     * section => (skill label => ['direction' => ..., 'number' => ...]).
     */
    private function buildDeltas(?InsightSnapshot $latest, ?InsightSnapshot $prior): array
    {
        $out = [];

        foreach (InsightSkillMetric::SECTIONS as $section) {
            $map = [];
            $nowRows = $latest?->{$section} ?? [];
            $pastRows = $prior?->{$section} ?? [];

            if ($section === 'trending_skills') {
                $pastPos = [];
                foreach (array_values($pastRows) as $i => $row) {
                    if (is_array($row) && ($lbl = InsightSkillMetric::label($row)) !== null) {
                        $pastPos[$lbl] = $i + 1;
                    }
                }
                foreach (array_values($nowRows) as $i => $row) {
                    if (! is_array($row) || ($lbl = InsightSkillMetric::label($row)) === null) {
                        continue;
                    }
                    $map[$lbl] = InsightSkillMetric::delta($section, $i + 1, $pastPos[$lbl] ?? null);
                }
            } else {
                $pastVal = [];
                foreach ($pastRows as $row) {
                    if (is_array($row) && ($lbl = InsightSkillMetric::label($row)) !== null) {
                        $pastVal[$lbl] = InsightSkillMetric::value($section, $row);
                    }
                }
                foreach ($nowRows as $row) {
                    if (! is_array($row) || ($lbl = InsightSkillMetric::label($row)) === null) {
                        continue;
                    }
                    $map[$lbl] = InsightSkillMetric::delta(
                        $section,
                        InsightSkillMetric::value($section, $row),
                        $pastVal[$lbl] ?? null
                    );
                }
            }

            $out[$section] = $map;
        }

        return $out;
    }

    public function ingest(Request $request)
    {
        $payload = $request->all();

        Log::info('========================= insights ingest: payload', ['payload' => $payload]);

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

        $attributes = [
            'scraped_at' => $scrapedAt,
            'earnings_total' => $this->parseMoney($userStats['totalEarnings'][0]['value'] ?? null),
            'earnings_30d' => $this->parseMoney($userStats['totalEarnings'][1]['value'] ?? null),
            'bids_remaining' => $this->bidSummaryValue($userStats['bidSummary'] ?? null, 'Bids Remaining'),
            'unearned_bids' => $this->bidSummaryValue($userStats['bidSummary'] ?? null, 'Unearned Bids'),
            'overall_ranking' => $this->stringOrNull($marketStats['overallRanking'][0]['value'] ?? null),
            'job_proficiency' => $this->arrayOrNull($userStats['jobProficiency'] ?? null),
            'rating_per_skill' => $this->arrayOrNull($userStats['ratingPerSkill'] ?? null),
            'earnings_per_skill' => $this->arrayOrNull($userStats['earningsPerSkill'] ?? null),
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
        ];

        // One snapshot per calendar day: a later crawl run overrides the same-day row.
        $snapshot = InsightSnapshot::whereDate('scraped_at', $scrapedAt->toDateString())->first();
        if ($snapshot) {
            $snapshot->fill($attributes)->save();
        } else {
            $snapshot = InsightSnapshot::create($attributes);
        }

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

    public function skillHistory(Request $request)
    {
        $section = (string) $request->query('section');

        if (! in_array($section, InsightSkillMetric::SECTIONS, true)) {
            return response()->json(['message' => 'Unknown section'], 422);
        }

        $label = (string) $request->query('label');
        [$from, $to] = $this->range($request);

        $query = InsightSnapshot::orderBy('scraped_at');
        if ($from) {
            $query->whereDate('scraped_at', '>=', $from);
        }
        if ($to) {
            $query->whereDate('scraped_at', '<=', $to);
        }
        $snapshots = $query->get(['scraped_at', $section]);

        $labels = [];
        $values = [];
        foreach ($snapshots as $snap) {
            $labels[] = $snap->scraped_at->format('Y-m-d');
            $rows = $snap->{$section} ?? [];
            $val = null;

            if ($section === 'trending_skills') {
                foreach (array_values($rows) as $i => $row) {
                    if (is_array($row) && InsightSkillMetric::label($row) === $label) {
                        $val = $i + 1;
                        break;
                    }
                }
            } else {
                foreach ($rows as $row) {
                    if (is_array($row) && InsightSkillMetric::label($row) === $label) {
                        $val = InsightSkillMetric::value($section, $row);
                        break;
                    }
                }
            }
            $values[] = $val;
        }

        return response()->json([
            'labels' => $labels,
            'values' => $values,
            'higherIsBetter' => InsightSkillMetric::higherIsBetter($section),
            'label' => $label,
        ]);
    }

    private function range(Request $request): array
    {
        $parse = function ($value) {
            if (! is_string($value) || $value === '') {
                return null;
            }
            try {
                return Carbon::parse($value)->toDateString();
            } catch (\Throwable $e) {
                return null;
            }
        };

        return [$parse($request->query('from')), $parse($request->query('to'))];
    }
}
