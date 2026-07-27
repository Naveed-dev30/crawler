<?php

namespace App\Console\Commands;

use App\Models\BidInsight;
use App\Models\Proposal;
use App\Services\ClientInsightWriter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Backfill "About the client" info for existing proposals that were crawled
 * before client capture existed. Fetches project owners in batches from the
 * Freelancer projects-by-id endpoint (with owner_info) and upserts via the
 * shared ClientInsightWriter — the same mapping the live crawl uses.
 */
class ClientBackfill extends Command
{
    protected $signature = 'client:backfill {--limit=200 : Max proposals to process} {--chunk=20 : Project ids per API call}';

    protected $description = 'Backfill client (project owner) info into bid_insights for existing proposals.';

    private const FLAGS = 'owner_info=true&job_details=true&user_details=true&user_avatar=true'
        .'&user_display_info=true&user_country_details=true&user_employer_reputation=true&user_status=true';

    public function handle(ClientInsightWriter $writer): int
    {
        $done = BidInsight::whereNotNull('client_country')->pluck('project_id')->all();

        $projectIds = Proposal::whereNotNull('project_id')
            ->when($done !== [], fn ($q) => $q->whereNotIn('project_id', $done))
            ->orderByDesc('id')
            ->limit((int) $this->option('limit'))
            ->pluck('project_id')
            ->unique()
            ->values();

        if ($projectIds->isEmpty()) {
            $this->info('Nothing to backfill — every proposal already has client info.');

            return self::SUCCESS;
        }

        $base = rtrim(config('variables.flBase'), '/').'/api/projects/0.1/projects/';
        $token = config('variables.flKey');
        $written = 0;
        $fetched = 0;

        foreach ($projectIds->chunk((int) $this->option('chunk')) as $chunk) {
            $query = self::FLAGS;
            foreach ($chunk as $pid) {
                $query .= '&projects[]='.$pid;
            }

            try {
                $response = Http::timeout(60)
                    ->withHeaders(['Freelancer-OAuth-V1' => $token])
                    ->get($base.'?'.$query);

                if (! $response->successful()) {
                    Log::warning('client:backfill HTTP '.$response->status());
                    $this->warn('Batch failed: HTTP '.$response->status());

                    continue;
                }

                $projects = $response->json('result.projects', []);
                $users = $response->json('result.users', []) ?? [];

                foreach ($projects as $project) {
                    $fetched++;
                    if ($writer->store($project, $users)) {
                        $written++;
                    }
                }
            } catch (\Throwable $e) {
                Log::warning('client:backfill exception: '.$e->getMessage());
                $this->warn('Batch error: '.$e->getMessage());
            }
        }

        $this->info("Backfill complete — {$written} client insights written from {$fetched} projects fetched ({$projectIds->count()} candidates).");

        return self::SUCCESS;
    }
}
