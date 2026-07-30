<?php

namespace App\Http\Controllers;

use App\Models\GamificationSnapshot;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class GamificationController extends Controller
{
    public function ingest(Request $request)
    {
        $payload = $request->all();

        Log::info('========================= gamification ingest: payload', ['payload' => $payload]);

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

        return view('content.pages.leaderboard', [
            'latest' => $latest,
            'history' => $history,
            'refreshedAt' => $latest?->scraped_at,
            'snapshotCount' => GamificationSnapshot::count(),
            'dateBounds' => $dateBounds,
            'from' => $from,
            'to' => $to,
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
