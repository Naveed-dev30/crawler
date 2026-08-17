<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Reads bid_stats for projects (api/projects/0.1).
 *
 * Exists to answer "rank 3 of what?" — the extension's insights payload gives
 * our own rank but never the size of the field, and bid_stats.bid_count is the
 * only place Freelancer publishes it. Works for closed and frozen projects, so
 * historical rows can be filled in too.
 */
class FreelancerProjectStats
{
    /** projects[] is a query string; keep each request well short of URL limits. */
    private const CHUNK = 50;

    private function client(): PendingRequest
    {
        return Http::timeout(30)->withHeaders([
            'Freelancer-OAuth-V1' => config('variables.flKey'),
        ]);
    }

    /**
     * The winning bid on each project, where one has been awarded.
     *
     * award_statuses[]=awarded makes Freelancer return just the winner per
     * project, so a batch of 50 projects costs one small response rather than
     * every bid on all of them.
     *
     * @param  array<int, int|string>  $projectIds
     * @return array<int, array{amount: ?float, sealed: bool, text: ?string}>
     *                                                                        keyed by project id; projects with no award are absent
     */
    public function winningBids(array $projectIds): array
    {
        $ids = collect($projectIds)->filter()->map(fn ($id) => (int) $id)->unique()->values();

        if ($ids->isEmpty()) {
            return [];
        }

        $base = rtrim(config('variables.flBase'), '/').'/api/projects/0.1/bids/';
        $out = [];

        foreach ($ids->chunk(self::CHUNK) as $chunk) {
            $query = $chunk->map(fn ($id) => "projects[]={$id}")->implode('&')
                .'&award_statuses[]=awarded&limit=100';

            try {
                $response = $this->client()->get($base.'?'.$query);

                if (! $response->successful()) {
                    Log::warning('FreelancerProjectStats winners: HTTP '.$response->status());

                    continue;
                }

                foreach ($response->json('result.bids') ?? [] as $bid) {
                    $projectId = (int) ($bid['project_id'] ?? 0);

                    if (! $projectId || ($bid['award_status'] ?? null) !== 'awarded') {
                        continue;
                    }

                    $sealed = (bool) ($bid['sealed'] ?? false);

                    $out[$projectId] = [
                        // A sealed project hides the figure; recording the 0 the
                        // API sends would read as "won for nothing".
                        'amount' => $sealed ? null : (isset($bid['amount']) ? (float) $bid['amount'] : null),
                        'sealed' => $sealed,
                        'text' => $bid['description'] ?? null,
                    ];
                }
            } catch (\Throwable $e) {
                Log::warning('FreelancerProjectStats winners exception: '.$e->getMessage());
            }
        }

        return $out;
    }

    /**
     * @param  array<int, int|string>  $projectIds
     * @return array<int, int> project id => total bids; ids with no stats are absent
     */
    public function bidCounts(array $projectIds): array
    {
        $ids = collect($projectIds)->filter()->map(fn ($id) => (int) $id)->unique()->values();

        if ($ids->isEmpty()) {
            return [];
        }

        $base = rtrim(config('variables.flBase'), '/').'/api/projects/0.1/projects/';
        $out = [];

        foreach ($ids->chunk(self::CHUNK) as $chunk) {
            $query = $chunk->map(fn ($id) => "projects[]={$id}")->implode('&');

            try {
                $response = $this->client()->get($base.'?'.$query);

                if (! $response->successful()) {
                    Log::warning('FreelancerProjectStats: HTTP '.$response->status());

                    continue; // a bad chunk must not lose the good ones
                }

                foreach ($response->json('result.projects') ?? [] as $project) {
                    $count = $project['bid_stats']['bid_count'] ?? null;

                    if (is_numeric($count)) {
                        $out[(int) $project['id']] = (int) $count;
                    }
                }
            } catch (\Throwable $e) {
                Log::warning('FreelancerProjectStats exception: '.$e->getMessage());
            }
        }

        return $out;
    }
}
