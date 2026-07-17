<?php

namespace App\Http\Controllers;

use App\Models\GamificationSnapshot;
use Carbon\Carbon;
use Illuminate\Http\Request;

class GamificationController extends Controller
{
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

        $snapshot = GamificationSnapshot::updateOrCreate(
            ['scraped_at' => $scrapedAt],
            [
                'self_rank' => $self['rank'] ?? null,
                'self_score' => $self['score'] ?? ($payload['level']['xp_total'] ?? null),
                'self_level' => $self['level'] ?? ($payload['level']['level'] ?? null),
                'self_username' => $self['username'] ?? null,
                'self_public_name' => $self['public_name'] ?? null,
                'top5' => $top5,
                'raw' => json_encode($payload),
            ]
        );

        return response()->json(['success' => true, 'id' => $snapshot->id]);
    }

    public function index()
    {
        $latest = GamificationSnapshot::orderByDesc('scraped_at')->first();

        $history = GamificationSnapshot::orderBy('scraped_at')
            ->limit(90)
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
        ]);
    }
}
