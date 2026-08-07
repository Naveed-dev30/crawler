<?php

namespace App\Http\Controllers;

use App\Models\GamificationSnapshot;
use Carbon\Carbon;
use Illuminate\Http\Request;

class GamificationController extends Controller
{
    /** Max distinct player lines on the top-5 chart — five, ranked by best rank hit. */
    private const TOP5_MAX_LINES = 5;

    public function ingest(Request $request)
    {
        $payload = $request->all();


        $top = $payload['leaderboard']['top'] ?? null;
        if (! is_array($top)) {
            return response()->json(['message' => 'Invalid payload'], 422);
        }

        $top5 = collect($top)->map(fn ($e) => [
            'rank' => $e['rank'] ?? null,
            'user_id' => $e['user_id'] ?? null,
            'username' => $e['username'] ?? null,
            'public_name' => $e['public_name'] ?? null,
            'level' => $e['level'] ?? null,
            'score' => $e['score'] ?? null,
            'is_current_user' => (bool) ($e['is_current_user'] ?? false),
        ])->values()->all();

        $self = collect($payload['leaderboard']['nearby'] ?? [])
            ->first(fn ($e) => ($e['is_current_user'] ?? false) === true);

        $rawTs = $payload['source']['scraped_at'] ?? null;
        try {
            $scrapedAt = (is_string($rawTs) && $rawTs !== '') ? Carbon::parse($rawTs) : now();
        } catch (\Throwable $e) {
            $scrapedAt = now();
        }

        $attributes = [
            'scraped_at' => $scrapedAt,
            'self_rank' => $self['rank'] ?? null,
            'self_score' => $self['score'] ?? ($payload['level']['xp_total'] ?? null),
            'self_level' => $self['level'] ?? ($payload['level']['level'] ?? null),
            'self_username' => $self['username'] ?? null,
            'self_public_name' => $self['public_name'] ?? null,
            'top5' => $top5,
            'raw' => json_encode($payload),
        ];

        // One snapshot per calendar day: a later crawl run overrides the same-day row.
        $snapshot = GamificationSnapshot::whereDate('scraped_at', $scrapedAt->toDateString())->first();
        if ($snapshot) {
            $snapshot->fill($attributes)->save();
        } else {
            $snapshot = GamificationSnapshot::create($attributes);
        }

        return response()->json(['success' => true, 'id' => $snapshot->id]);
    }

    public function index(Request $request)
    {
        $minRaw = GamificationSnapshot::min('scraped_at');
        $maxRaw = GamificationSnapshot::max('scraped_at');
        $dateBounds = [
            'min' => $minRaw ? Carbon::parse($minRaw) : null,
            'max' => $maxRaw ? Carbon::parse($maxRaw) : null,
        ];

        [$from, $to] = $this->range($request);

        $latestQuery = GamificationSnapshot::orderByDesc('scraped_at');
        if ($to) {
            $latestQuery->whereDate('scraped_at', '<=', $to);
        }
        $latest = $latestQuery->first();

        $historyQuery = GamificationSnapshot::orderBy('scraped_at');
        if ($from) {
            $historyQuery->whereDate('scraped_at', '>=', $from);
        }
        if ($to) {
            $historyQuery->whereDate('scraped_at', '<=', $to);
        }
        $history = $historyQuery->limit(365)
            ->get(['scraped_at', 'self_rank', 'self_score'])
            ->map(fn ($s) => [
                'date' => $s->scraped_at->format('Y-m-d'),
                'rank' => $s->self_rank,
                'score' => $s->self_score,
            ])
            ->all();

        // Daily score line per current top-5 player. Each day's snapshot stores
        // only its own top5, so a player who dropped out of the top 5 on a given
        // day has no point that day (null → the line simply gaps there).
        [$top5Dates, $top5Series] = $this->buildTop5Series($from, $to);

        return view('content.pages.leaderboard', [
            'latest' => $latest,
            'history' => $history,
            'top5Dates' => $top5Dates,
            'top5Series' => $top5Series,
            'refreshedAt' => $latest?->scraped_at,
            'snapshotCount' => GamificationSnapshot::count(),
            'dateBounds' => $dateBounds,
            'from' => $from,
            'to' => $to,
        ]);
    }

    /**
     * A score series for every player who appeared in the top 5 anywhere in the
     * range — not just the latest five. So when a newcomer bumps someone to #6,
     * the bumped player keeps their historical line instead of vanishing.
     *
     * Players are keyed by user_id when the ingest captured it (rename-proof),
     * else by public_name. Lines are ordered by best (lowest) rank achieved and
     * capped for legibility. A day a player was outside the top 5 yields null so
     * the ApexCharts line simply gaps there.
     *
     * @return array{0: array<int, string>, 1: array<int, array{name: string, data: array<int, int|null>}>}
     */
    private function buildTop5Series($from, $to): array
    {
        $query = GamificationSnapshot::orderBy('scraped_at');
        if ($from) {
            $query->whereDate('scraped_at', '>=', $from);
        }
        if ($to) {
            $query->whereDate('scraped_at', '<=', $to);
        }
        $snaps = $query->limit(365)->get(['scraped_at', 'top5']);

        if ($snaps->isEmpty()) {
            return [[], []];
        }

        $dates = $snaps->map(fn ($s) => $s->scraped_at->format('Y-m-d'))->all();

        // Union pass: collect every distinct player + the best rank they hit.
        $players = [];
        foreach ($snaps as $s) {
            foreach ($s->top5 ?? [] as $e) {
                $key = $this->playerKey($e);
                $rank = $e['rank'] ?? 999;
                if (! isset($players[$key])) {
                    $players[$key] = [
                        'key' => $key,
                        'name' => $e['public_name'] ?? $e['username'] ?? 'Unknown',
                        'best_rank' => $rank,
                    ];
                } elseif ($rank < $players[$key]['best_rank']) {
                    $players[$key]['best_rank'] = $rank;
                }
            }
        }

        $ordered = collect($players)
            ->sortBy('best_rank')
            ->take(self::TOP5_MAX_LINES)
            ->values();

        $series = $ordered->map(function ($p) use ($snaps) {
            $data = $snaps->map(function ($s) use ($p) {
                $day = collect($s->top5 ?? [])->first(fn ($e) => $this->playerKey($e) === $p['key']);

                return $day['score'] ?? null;
            })->all();

            return ['name' => $p['name'], 'data' => $data];
        })->all();

        return [$dates, $series];
    }

    /**
     * Stable identity for a top-5 entry: user_id when present (survives renames),
     * otherwise the public name.
     */
    private function playerKey(array $entry): string
    {
        return isset($entry['user_id']) && $entry['user_id'] !== null
            ? 'u:'.$entry['user_id']
            : 'n:'.($entry['public_name'] ?? $entry['username'] ?? 'Unknown');
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

        // Default window: last 30 days when no explicit `from` is given, so the
        // page and charts open on a recent, readable range rather than all data.
        $from = $parse($request->query('from')) ?? Carbon::now()->subDays(30)->toDateString();

        return [$from, $parse($request->query('to'))];
    }
}
