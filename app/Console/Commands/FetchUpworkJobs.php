<?php

namespace App\Console\Commands;

use App\Exceptions\UpworkFetchException;
use App\Models\UpworkJob;
use App\Services\UpworkClient;
use Illuminate\Console\Command;

class FetchUpworkJobs extends Command
{
    protected $signature = 'upwork:fetch';

    protected $description = 'Fetch recent Upwork marketplace jobs';

    public function handle(UpworkClient $client): int
    {
        try {
            $rows = $client->fetchRecentJobs();
        } catch (UpworkFetchException $e) {
            // Exit non-zero so a credential or API problem is visible in the
            // scheduler's exit status rather than looking like a quiet market.
            $this->error('Upwork fetch failed: '.$e->getMessage());

            return self::FAILURE;
        }

        $saved = 0;

        foreach ($rows as $row) {
            if (empty($row['job_id'])) {
                continue;
            }
            UpworkJob::updateOrCreate(['job_id' => $row['job_id']], $row);
            $saved++;
        }

        $this->info('Upwork fetch complete: '.$saved.' jobs.');

        return self::SUCCESS;
    }
}
